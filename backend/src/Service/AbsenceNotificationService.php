<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Absence;
use App\Entity\AbsenceEvent;
use App\Entity\Learner;
use App\Entity\LearnerCommunication;
use App\Entity\User;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

// Roadmap 3.2, étape 2 : notification automatique de l'apprenant à la détection d'une absence,
// avec un lien de dépôt de justificatif protégé par token (même pattern que
// AuthController::forgotPassword — token en clair, expiration, usage unique côté justification).
class AbsenceNotificationService
{
    private const JUSTIFICATION_TOKEN_TTL = '+7 days';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly AbsenceEventLogger $eventLogger,
        private readonly LearnerCommunicationLogger $communicationLogger,
        private readonly string $frontendUrl,
        private readonly string $fromAddress,
        private readonly string $disciplinaryAlertEmail,
    ) {
    }

    // Notification initiale, appelée une seule fois à la détection (AbsenceDetectionService) :
    // l'absence n'a jamais eu de token, on en génère toujours un nouveau.
    public function notify(Absence $absence): void
    {
        $token = bin2hex(random_bytes(32));
        $absence->setJustificationToken($token, new \DateTimeImmutable(self::JUSTIFICATION_TOKEN_TTL));

        $this->sendNotificationEmail($absence, null, false);
    }

    // Relance manuelle depuis la fiche absence (roadmap : bouton "Renvoyer la relance"). Décision
    // explicite de l'utilisateur : une relance ne remet PAS le délai à zéro par défaut — elle renvoie
    // le même lien (même token, même expiration) tant qu'il est encore valide, pour que l'apprenant
    // garde le nombre de jours restants affiché sur la page publique. $extend=true (bouton
    // "Prolonger" séparé) repousse explicitement l'expiration à 7 jours à partir de maintenant, en
    // conservant le même token. Si aucun token valide n'existe (jamais envoyé, ou expiré), un nouveau
    // token est généré dans tous les cas puisqu'il n'y a rien à réutiliser.
    public function resend(Absence $absence, ?User $actor, bool $extend = false): bool
    {
        $hasValidToken = $absence->getJustificationToken() !== null
            && $absence->getJustificationTokenExpiresAt() !== null
            && $absence->getJustificationTokenExpiresAt() > new \DateTimeImmutable();

        $renewed = $extend || !$hasValidToken;

        if ($renewed) {
            $token = $hasValidToken ? (string) $absence->getJustificationToken() : bin2hex(random_bytes(32));
            $absence->setJustificationToken($token, new \DateTimeImmutable(self::JUSTIFICATION_TOKEN_TTL));
        }

        $this->sendNotificationEmail($absence, $actor, $renewed);

        return $renewed;
    }

    private function sendNotificationEmail(Absence $absence, ?User $actor, bool $renewed): void
    {
        $token = $absence->getJustificationToken();
        $learner = $absence->getRegistration()->getLearner();
        $email = $learner->getEmail();

        if ($email === null || $email === '') {
            $absence->setNotificationSentAt(new \DateTimeImmutable());
            $this->eventLogger->log($absence, AbsenceEvent::TYPE_NOTIFICATION_SENT, $actor, ['delivered' => false, 'reason' => 'no_email']);

            return;
        }

        $session = $absence->getRegistration()->getSession();
        $sessionLabel = $session->getTraining()?->getTitle() ?? $session->getModule()?->getTitle() ?? 'votre session';
        $sessionDate = $session->getStartAt()?->format('d/m/Y à H:i') ?? 'date inconnue';
        $justificationUrl = sprintf('%s/absences/justificatif?token=%s', rtrim($this->frontendUrl, '/'), $token);
        $learnerName = trim(sprintf('%s %s', (string) $learner->getFirstName(), (string) $learner->getLastName()));
        $daysRemaining = $this->daysRemaining($absence);

        $subject = 'TrackUp - Absence constatée : ' . $sessionLabel;
        $text = "Bonjour {$learnerName},\n\n"
            . "Nous constatons votre absence à la session \"{$sessionLabel}\" du {$sessionDate}.\n"
            . "Si vous disposez d'un justificatif, vous pouvez le déposer ici"
            . ($daysRemaining !== null ? " (encore {$daysRemaining} jour(s) pour le faire) :\n" : " :\n")
            . "{$justificationUrl}\n\n"
            . "Sans justificatif, cette absence sera considérée comme non justifiée.\n";

        $message = (new Email())
            ->from($this->fromAddress)
            ->to($email)
            ->subject($subject)
            ->text($text)
            ->html(
                "<p>Bonjour {$learnerName},</p>"
                . "<p>Nous constatons votre absence à la session \"{$sessionLabel}\" du {$sessionDate}.</p>"
                . "<p><a href=\"{$justificationUrl}\">Déposer un justificatif</a>"
                . ($daysRemaining !== null ? " (encore {$daysRemaining} jour(s)).</p>" : ".</p>")
                . "<p>Sans justificatif, cette absence sera considérée comme non justifiée.</p>"
            );

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface) {
            // Swallowed on purpose, matching AuthController::sendResetEmail(): the absence still
            // gets its token so the learner can be pointed to the link manually if needed, and
            // delivery failures are for ops monitoring, not something this call site can act on.
        }

        $absence->setNotificationSentAt(new \DateTimeImmutable());
        $this->eventLogger->log($absence, AbsenceEvent::TYPE_NOTIFICATION_SENT, $actor, [
            'delivered' => true,
            'to' => $email,
            'subject' => $subject,
            'text' => $text,
            'renewed' => $renewed,
        ]);
    }

    private function daysRemaining(Absence $absence): ?int
    {
        $expiresAt = $absence->getJustificationTokenExpiresAt();
        if ($expiresAt === null) {
            return null;
        }

        $secondsRemaining = $expiresAt->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();

        // Arrondi au jour supérieur (pas tronqué) : juste après un envoi avec expiresAt = now + 7
        // jours, quelques millisecondes se sont écoulées, donc un calcul tronqué afficherait déjà 6.
        return max(0, (int) ceil($secondsRemaining / 86400));
    }

    // Roadmap 3.2, étape 4 : email de confirmation envoyé à l'apprenant après décision admin
    // (validation ou rejet du justificatif, ou changement de statut manuel). $actor = l'admin à
    // l'origine de la décision, ou null si déclenché automatiquement (expiration du délai).
    public function sendConfirmation(Absence $absence, ?User $actor = null): void
    {
        $learner = $absence->getRegistration()->getLearner();
        $email = $learner->getEmail();

        if ($email === null || $email === '') {
            $absence->setConfirmationSentAt(new \DateTimeImmutable());

            return;
        }

        $session = $absence->getRegistration()->getSession();
        $sessionLabel = $session->getTraining()?->getTitle() ?? $session->getModule()?->getTitle() ?? 'votre session';
        $learnerName = trim(sprintf('%s %s', (string) $learner->getFirstName(), (string) $learner->getLastName()));

        [$subjectLabel, $statusText] = match ($absence->getStatus()) {
            Absence::STATUS_JUSTIFIEE => ['Absence justifiée', 'a été validée comme justifiée'],
            Absence::STATUS_NON_JUSTIFIEE => ['Absence non justifiée', 'a été considérée comme non justifiée'],
            default => ['Absence traitée', 'a été traitée'],
        };

        $subject = 'TrackUp - ' . $subjectLabel . ' : ' . $sessionLabel;
        $text = "Bonjour {$learnerName},\n\n"
            . "Votre absence à la session \"{$sessionLabel}\" {$statusText}.\n";

        $message = (new Email())
            ->from($this->fromAddress)
            ->to($email)
            ->subject($subject)
            ->text($text)
            ->html(
                "<p>Bonjour {$learnerName},</p>"
                . "<p>Votre absence à la session \"{$sessionLabel}\" {$statusText}.</p>"
            );

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface) {
            // Swallowed on purpose, même raisonnement que notify().
        }

        $absence->setConfirmationSentAt(new \DateTimeImmutable());
        $this->eventLogger->log($absence, AbsenceEvent::TYPE_CONFIRMATION_SENT, $actor, [
            'delivered' => true,
            'to' => $email,
            'subject' => $subject,
            'text' => $text,
        ]);
    }

    // Roadmap 3.4 : alerte à l'équipe pédagogique au 3ème dépassement d'absences masterclass non
    // justifiées consécutives, pour déclencher la procédure disciplinaire.
    public function sendDisciplinaryAlert(Learner $learner, int $count): void
    {
        $learnerName = trim(sprintf('%s %s', (string) $learner->getFirstName(), (string) $learner->getLastName()));
        $learnerUrl = sprintf('%s/learners/%d', rtrim($this->frontendUrl, '/'), $learner->getId());

        $message = (new Email())
            ->from($this->fromAddress)
            ->to($this->disciplinaryAlertEmail)
            ->subject(sprintf('TrackUp - Alerte absences : %s (%d consécutives)', $learnerName, $count))
            ->text(
                "L'apprenant {$learnerName} cumule {$count} absences masterclass non justifiées consécutives.\n\n"
                . "Fiche apprenant : {$learnerUrl}\n\n"
                . "Merci de déclencher la procédure disciplinaire (avertissement, courrier, suivi renforcé).\n"
            )
            ->html(
                "<p>L'apprenant <strong>{$learnerName}</strong> cumule <strong>{$count}</strong> absences masterclass "
                . "non justifiées consécutives.</p>"
                . "<p><a href=\"{$learnerUrl}\">Voir la fiche apprenant</a></p>"
                . "<p>Merci de déclencher la procédure disciplinaire (avertissement, courrier, suivi renforcé).</p>"
            );

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface) {
            // Swallowed on purpose, même raisonnement que notify().
        }
    }

    // Email disciplinaire envoyé DIRECTEMENT à l'apprenant (distinct de sendDisciplinaryAlert(), qui
    // reste l'alerte interne automatique à pedagogie@edup-bs.com). Décision explicite de
    // l'utilisateur : cet envoi n'est jamais automatique, uniquement déclenché manuellement par un
    // admin depuis /absences/alertes — d'où $actor non nullable ici (toujours une action manuelle).
    // Retourne false sans rien envoyer si l'apprenant n'a pas d'adresse email connue.
    public function sendDisciplinaryEmailToLearner(Learner $learner, int $count, User $actor): bool
    {
        $email = $learner->getEmail();
        if ($email === null || $email === '') {
            return false;
        }

        $learnerName = trim(sprintf('%s %s', (string) $learner->getFirstName(), (string) $learner->getLastName()));

        $subject = 'TrackUp - Absences répétées en masterclass : merci de régulariser votre situation';
        $text = "Bonjour {$learnerName},\n\n"
            . "Nous constatons que vous cumulez {$count} absences non justifiées consécutives à des sessions de masterclass.\n\n"
            . "L'assiduité aux masterclass fait partie intégrante de votre parcours de formation. Merci de régulariser "
            . "votre situation dans les meilleurs délais :\n"
            . "- si vous disposez d'un justificatif pour l'une de ces absences, transmettez-le à l'équipe pédagogique ;\n"
            . "- si votre situation le nécessite, contactez-nous pour en discuter.\n\n"
            . "Sans régularisation, une procédure disciplinaire pourra être engagée (avertissement, courrier officiel, "
            . "suivi renforcé).\n\n"
            . "Nous restons à votre disposition pour tout échange.\n\n"
            . "Cordialement,\nL'équipe pédagogique\n";

        $message = (new Email())
            ->from($this->fromAddress)
            ->to($email)
            ->subject($subject)
            ->text($text)
            ->html(
                "<p>Bonjour {$learnerName},</p>"
                . "<p>Nous constatons que vous cumulez <strong>{$count}</strong> absences non justifiées consécutives "
                . "à des sessions de masterclass.</p>"
                . "<p>L'assiduité aux masterclass fait partie intégrante de votre parcours de formation. Merci de "
                . "régulariser votre situation dans les meilleurs délais :</p>"
                . "<ul>"
                . "<li>si vous disposez d'un justificatif pour l'une de ces absences, transmettez-le à l'équipe pédagogique ;</li>"
                . "<li>si votre situation le nécessite, contactez-nous pour en discuter.</li>"
                . "</ul>"
                . "<p>Sans régularisation, une procédure disciplinaire pourra être engagée (avertissement, courrier "
                . "officiel, suivi renforcé).</p>"
                . "<p>Nous restons à votre disposition pour tout échange.</p>"
                . "<p>Cordialement,<br>L'équipe pédagogique</p>"
            );

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface) {
            // Swallowed on purpose, même raisonnement que notify().
        }

        $this->communicationLogger->log($learner, LearnerCommunication::TYPE_DISCIPLINARY_EMAIL, $actor, [
            'to' => $email,
            'subject' => $subject,
            'text' => $text,
            'consecutiveCount' => $count,
        ]);

        return true;
    }
}
