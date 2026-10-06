<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Absence;
use App\Entity\AbsenceEvent;
use App\Entity\Learner;
use App\Entity\LearnerCommunication;
use App\Entity\User;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
        private readonly string $frontendUrl,
        private readonly string $fromAddress,
        private readonly string $disciplinaryAlertEmail,
    ) {
    }

    // Notification initiale, appelée une seule fois à la détection (AbsenceDetectionService) :
    // l'absence n'a jamais eu de token, on en génère toujours un nouveau.
    public function notify(Absence $absence): bool
    {
        $token = bin2hex(random_bytes(32));
        $absence->setJustificationToken($token, new \DateTimeImmutable(self::JUSTIFICATION_TOKEN_TTL));

        return $this->sendNotificationEmail($absence, null, false);
    }

    // Relance manuelle depuis la fiche absence (roadmap : bouton "Renvoyer la relance"). Décision
    // explicite de l'utilisateur : une relance ne remet PAS le délai à zéro par défaut — elle renvoie
    // le même lien (même token, même expiration) tant qu'il est encore valide, pour que l'apprenant
    // garde le nombre de jours restants affiché sur la page publique. $extend=true (bouton
    // "Prolonger" séparé) repousse explicitement l'expiration à 7 jours à partir de maintenant, en
    // conservant le même token. Si aucun token valide n'existe (jamais envoyé, ou expiré), un nouveau
    // token est généré dans tous les cas puisqu'il n'y a rien à réutiliser.
    /** @return array{renewed: bool, delivered: bool} */
    public function resend(Absence $absence, ?User $actor, bool $extend = false): array
    {
        $hasValidToken = $absence->getJustificationToken() !== null
            && $absence->getJustificationTokenExpiresAt() !== null
            && $absence->getJustificationTokenExpiresAt() > new \DateTimeImmutable();

        $renewed = $extend || !$hasValidToken;

        if ($renewed) {
            $token = $hasValidToken ? (string) $absence->getJustificationToken() : bin2hex(random_bytes(32));
            $absence->setJustificationToken($token, new \DateTimeImmutable(self::JUSTIFICATION_TOKEN_TTL));
        }

        return [
            'renewed' => $renewed,
            'delivered' => $this->sendNotificationEmail($absence, $actor, $renewed),
        ];
    }

    private function sendNotificationEmail(Absence $absence, ?User $actor, bool $renewed): bool
    {
        $token = $absence->getJustificationToken();
        $learner = $absence->getRegistration()->getLearner();
        $email = $learner->getEmail();

        if ($email === null || $email === '') {
            $this->eventLogger->log($absence, AbsenceEvent::TYPE_NOTIFICATION_SENT, $actor, ['delivered' => false, 'reason' => 'no_email']);

            return false;
        }

        $session = $absence->getRegistration()->getSession();
        $trainingOrModuleTitle = $session->getTraining()?->getTitle() ?? $session->getModule()?->getTitle() ?? 'votre session';
        $sessionDate = $session->getStartAt()?->format('d/m/Y à H:i') ?? 'date inconnue';
        $justificationUrl = sprintf('%s/absences/justificatif?token=%s', rtrim($this->frontendUrl, '/'), $token);
        $learnerFirstName = trim((string) $learner->getFirstName());
        $daysRemaining = $this->daysRemaining($absence);

        $subject = 'Absence constatée : ' . $trainingOrModuleTitle;
        $text = "Bonjour {$learnerFirstName},\n\n"
            . "Nous constatons votre absence à la session \"{$trainingOrModuleTitle}\" du {$sessionDate}.\n"
            . "Si vous disposez d'un justificatif, vous pouvez le déposer ici"
            . ($daysRemaining !== null ? " (encore {$daysRemaining} jour(s) pour le faire) :\n" : " :\n")
            . "{$justificationUrl}\n\n"
            . "Sans justificatif, cette absence sera considérée comme non justifiée.\n\n"
            . "L’équipe pédagogique\n\n"
            . "Ed’Up Business School\n";

        $message = (new Email())
            ->from($this->fromAddress)
            ->to($email)
            ->subject($subject)
            ->text($text)
            ->html(
                "<p>Bonjour {$learnerFirstName},</p>"
                . "<p>Nous constatons votre absence à la session \"{$trainingOrModuleTitle}\" du {$sessionDate}.</p>"
                . "<p>Si vous disposez d'un justificatif, vous pouvez le déposer ici"
                . ($daysRemaining !== null ? " (encore {$daysRemaining} jour(s) pour le faire) :<br>" : " :<br>")
                . "<a href=\"{$justificationUrl}\">{$justificationUrl}</a></p>"
                . "<p>Sans justificatif, cette absence sera considérée comme non justifiée.</p>"
                . "<p>L’équipe pédagogique</p>"
                . "<p>Ed’Up Business School</p>"
            );

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Failed to send absence notification email.', [
                'absenceId' => $absence->getId(),
                'recipient' => $email,
                'exception' => $exception,
            ]);
            $this->eventLogger->log($absence, AbsenceEvent::TYPE_NOTIFICATION_SENT, $actor, [
                'delivered' => false,
                'to' => $email,
                'subject' => $subject,
                'renewed' => $renewed,
                'reason' => 'transport_error',
            ]);

            return false;
        }

        $absence->setNotificationSentAt(new \DateTimeImmutable());
        $this->eventLogger->log($absence, AbsenceEvent::TYPE_NOTIFICATION_SENT, $actor, [
            'delivered' => true,
            'to' => $email,
            'subject' => $subject,
            'text' => $text,
            'renewed' => $renewed,
        ]);

        return true;
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
    public function sendConfirmation(Absence $absence, ?User $actor = null): bool
    {
        $learner = $absence->getRegistration()->getLearner();
        $email = $learner->getEmail();

        if ($email === null || $email === '') {
            $this->eventLogger->log($absence, AbsenceEvent::TYPE_CONFIRMATION_SENT, $actor, [
                'delivered' => false,
                'reason' => 'no_email',
            ]);

            return false;
        }

        $session = $absence->getRegistration()->getSession();
        $sessionLabel = $session->getTraining()?->getTitle() ?? $session->getModule()?->getTitle() ?? 'votre session';
        $learnerFirstName = trim((string) $learner->getFirstName());

        [$subjectLabel, $statusText] = match ($absence->getStatus()) {
            Absence::STATUS_JUSTIFIEE => ['Absence justifiée', 'a été validée comme justifiée'],
            Absence::STATUS_NON_JUSTIFIEE => ['Absence non justifiée', 'a été considérée comme non justifiée'],
            default => ['Absence traitée', 'a été traitée'],
        };

        $subject = $subjectLabel . ' : ' . $sessionLabel;
        $text = "Bonjour {$learnerFirstName},\n\n"
            . "Votre absence à la session \"{$sessionLabel}\" {$statusText}.\n\n"
            . "L’équipe pédagogique\n"
            . "Ed’Up Business School\n";

        $message = (new Email())
            ->from($this->fromAddress)
            ->to($email)
            ->subject($subject)
            ->text($text)
            ->html(
                "<p>Bonjour {$learnerFirstName},</p>"
                . "<p>Votre absence à la session \"{$sessionLabel}\" {$statusText}.</p>"
                . "<p style=\"margin-top: 24px;\">L’équipe pédagogique<br>Ed’Up Business School</p>"
            );

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Failed to send absence confirmation email.', [
                'absenceId' => $absence->getId(),
                'recipient' => $email,
                'exception' => $exception,
            ]);
            $this->eventLogger->log($absence, AbsenceEvent::TYPE_CONFIRMATION_SENT, $actor, [
                'delivered' => false,
                'to' => $email,
                'subject' => $subject,
                'reason' => 'transport_error',
            ]);

            return false;
        }

        $absence->setConfirmationSentAt(new \DateTimeImmutable());
        $this->eventLogger->log($absence, AbsenceEvent::TYPE_CONFIRMATION_SENT, $actor, [
            'delivered' => true,
            'to' => $email,
            'subject' => $subject,
            'text' => $text,
        ]);

        return true;
    }

    // Roadmap 3.4 : alerte à l'équipe pédagogique au 3ème dépassement d'absences masterclass non
    // justifiées consécutives, pour déclencher la procédure disciplinaire.
    public function sendDisciplinaryAlert(Learner $learner, int $count): bool
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
                . "Merci de déclencher la procédure disciplinaire (avertissement, courrier, suivi renforcé).\n\n"
                . "L’équipe pédagogique\n"
                . "Ed’Up Business School\n"
            )
            ->html(
                "<p>L'apprenant <strong>{$learnerName}</strong> cumule <strong>{$count}</strong> absences masterclass "
                . "non justifiées consécutives.</p>"
                . "<p><a href=\"{$learnerUrl}\">Voir la fiche apprenant</a></p>"
                . "<p>Merci de déclencher la procédure disciplinaire (avertissement, courrier, suivi renforcé).</p>"
                . "<p>L’équipe pédagogique<br>Ed’Up Business School</p>"
            );

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Failed to send disciplinary alert email.', [
                'learnerId' => $learner->getId(),
                'recipient' => $this->disciplinaryAlertEmail,
                'exception' => $exception,
            ]);

            return false;
        }

        return true;
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

        $learnerFirstName = trim((string) $learner->getFirstName());

        $subject = 'Absences répétées en masterclass : merci de régulariser votre situation';
        $text = "Bonjour {$learnerFirstName},\n\n"
            . "Nous constatons que vous cumulez {$count} absences non justifiées consécutives à vos sessions de masterclass.\n\n"
            . "Nous vous rappelons que l’assiduité aux masterclass est obligatoire et fait partie intégrante de votre parcours de formation.\n\n"
            . "Nous vous demandons donc de régulariser votre situation dans les meilleurs délais :\n\n"
            . "- si vous disposez de justificatifs d'absence, merci de les transmettre à l’équipe pédagogique dans les meilleurs délais,\n"
            . "- si vous rencontrez une difficulté particulière ayant un impact sur votre assiduité, nous vous invitons à nous contacter afin que nous puissions échanger sur votre situation.\n\n"
            . "En l’absence de régularisation ou en cas de nouvelles absences non justifiées, une procédure disciplinaire pourra être engagée, conformément au règlement intérieur. Celle-ci pourra notamment prendre la forme d’un avertissement, d’un entretien avec l’équipe pédagogique, d’un échange tripartite avec votre entreprise d’accueil ou, selon la situation, de la saisine du conseil de discipline.\n\n"
            . "Nous vous remercions de prendre les dispositions nécessaires afin de rétablir votre assiduité dès votre prochaine session.\n\n"
            . "Nous restons à votre disposition pour tout échange.\n\n"
            . "Cordialement,\n\n\n"
            . "L’équipe pédagogique\n\n"
            . "Ed’Up Business School\n";

        $message = (new Email())
            ->from($this->fromAddress)
            ->to($email)
            ->subject($subject)
            ->text($text)
            ->html(
                "<p>Bonjour {$learnerFirstName},</p>"
                . "<p>Nous constatons que vous cumulez <strong>{$count}</strong> absences non justifiées consécutives "
                . "à vos sessions de masterclass.</p>"
                . "<p>Nous vous rappelons que l’assiduité aux masterclass est obligatoire et fait partie intégrante "
                . "de votre parcours de formation.</p>"
                . "<p>Nous vous demandons donc de régulariser votre situation dans les meilleurs délais :</p>"
                . "<ul>"
                . "<li>si vous disposez de justificatifs d'absence, merci de les transmettre à l’équipe pédagogique dans les meilleurs délais,</li>"
                . "<li>si vous rencontrez une difficulté particulière ayant un impact sur votre assiduité, nous vous invitons à nous contacter afin que nous puissions échanger sur votre situation.</li>"
                . "</ul>"
                . "<p>En l’absence de régularisation ou en cas de nouvelles absences non justifiées, une procédure "
                . "disciplinaire pourra être engagée, conformément au règlement intérieur. Celle-ci pourra notamment "
                . "prendre la forme d’un avertissement, d’un entretien avec l’équipe pédagogique, d’un échange "
                . "tripartite avec votre entreprise d’accueil ou, selon la situation, de la saisine du conseil de discipline.</p>"
                . "<p>Nous vous remercions de prendre les dispositions nécessaires afin de rétablir votre assiduité "
                . "dès votre prochaine session.</p>"
                . "<p>Nous restons à votre disposition pour tout échange.</p>"
                . "<p>Cordialement,</p>"
                . "<p style=\"margin-top: 24px;\">L’équipe pédagogique</p>"
                . "<p>Ed’Up Business School</p>"
            );

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Failed to send disciplinary email to learner.', [
                'learnerId' => $learner->getId(),
                'recipient' => $email,
                'exception' => $exception,
            ]);
            $this->communicationLogger->log($learner, LearnerCommunication::TYPE_DISCIPLINARY_EMAIL, $actor, [
                'to' => $email,
                'subject' => $subject,
                'text' => $text,
                'consecutiveCount' => $count,
                'delivered' => false,
                'reason' => 'transport_error',
            ]);

            return false;
        }

        $this->communicationLogger->log($learner, LearnerCommunication::TYPE_DISCIPLINARY_EMAIL, $actor, [
            'to' => $email,
            'subject' => $subject,
            'text' => $text,
            'consecutiveCount' => $count,
            'delivered' => true,
        ]);

        return true;
    }
}
