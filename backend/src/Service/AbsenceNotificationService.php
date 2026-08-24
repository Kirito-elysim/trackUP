<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Learner;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

// Roadmap 3.2, étape 2 : notification automatique de l'apprenant à la détection d'une absence,
// avec un lien de dépôt de justificatif protégé par token (même pattern que
// AuthController::forgotPassword — token en clair, expiration, usage unique côté justification).
class AbsenceNotificationService
{
    private const JUSTIFICATION_TOKEN_TTL = '+14 days';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $frontendUrl,
        private readonly string $fromAddress,
        private readonly string $disciplinaryAlertEmail,
    ) {
    }

    public function notify(Absence $absence): void
    {
        $token = bin2hex(random_bytes(32));
        $absence->setJustificationToken($token, new \DateTimeImmutable(self::JUSTIFICATION_TOKEN_TTL));

        $learner = $absence->getRegistration()->getLearner();
        $email = $learner->getEmail();

        if ($email === null || $email === '') {
            $absence->setNotificationSentAt(new \DateTimeImmutable());

            return;
        }

        $session = $absence->getRegistration()->getSession();
        $sessionLabel = $session->getTraining()?->getTitle() ?? $session->getModule()?->getTitle() ?? 'votre session';
        $sessionDate = $session->getStartAt()?->format('d/m/Y à H:i') ?? 'date inconnue';
        $justificationUrl = sprintf('%s/absences/justificatif?token=%s', rtrim($this->frontendUrl, '/'), $token);
        $learnerName = trim(sprintf('%s %s', (string) $learner->getFirstName(), (string) $learner->getLastName()));

        $message = (new Email())
            ->from($this->fromAddress)
            ->to($email)
            ->subject('TrackUp - Absence constatée : ' . $sessionLabel)
            ->text(
                "Bonjour {$learnerName},\n\n"
                . "Nous constatons votre absence à la session \"{$sessionLabel}\" du {$sessionDate}.\n"
                . "Si vous disposez d'un justificatif, vous pouvez le déposer ici (lien valable 14 jours) :\n"
                . "{$justificationUrl}\n\n"
                . "Sans justificatif, cette absence sera considérée comme non justifiée.\n"
            )
            ->html(
                "<p>Bonjour {$learnerName},</p>"
                . "<p>Nous constatons votre absence à la session \"{$sessionLabel}\" du {$sessionDate}.</p>"
                . "<p><a href=\"{$justificationUrl}\">Déposer un justificatif</a> (lien valable 14 jours).</p>"
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
    }

    // Roadmap 3.2, étape 4 : email de confirmation envoyé à l'apprenant après décision admin
    // (validation ou rejet du justificatif, ou changement de statut manuel).
    public function sendConfirmation(Absence $absence): void
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

        [$subject, $statusText] = match ($absence->getStatus()) {
            Absence::STATUS_JUSTIFIEE => ['Absence justifiée', 'a été validée comme justifiée'],
            Absence::STATUS_NON_JUSTIFIEE => ['Absence non justifiée', 'a été considérée comme non justifiée'],
            default => ['Absence traitée', 'a été traitée'],
        };

        $message = (new Email())
            ->from($this->fromAddress)
            ->to($email)
            ->subject('TrackUp - ' . $subject . ' : ' . $sessionLabel)
            ->text(
                "Bonjour {$learnerName},\n\n"
                . "Votre absence à la session \"{$sessionLabel}\" {$statusText}.\n"
            )
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
}
