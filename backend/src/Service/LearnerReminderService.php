<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Learner;
use App\Entity\LearnerCommunication;
use App\Entity\User;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

// Relances e-learning envoyées manuellement depuis le tableau des membres d'un groupe (roadmap :
// action "Envoyer une relance" sur /groups/{id}), pour deux motifs distincts choisis par l'admin :
// avancement insuffisant dans les modules, ou non-respect des horaires de connexion imposés.
// Toujours déclenché par un admin (jamais automatique) — voir LearnerController::sendElearningReminder().
class LearnerReminderService
{
    public const REASON_PROGRESS = 'progress';
    public const REASON_SCHEDULE = 'schedule';

    public const REASONS = [self::REASON_PROGRESS, self::REASON_SCHEDULE];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LearnerCommunicationLogger $communicationLogger,
        private readonly string $fromAddress,
    ) {
    }

    public function sendElearningReminder(Learner $learner, string $reason, User $actor): bool
    {
        $email = $learner->getEmail();
        if ($email === null || $email === '') {
            return false;
        }

        $learnerName = trim(sprintf('%s %s', (string) $learner->getFirstName(), (string) $learner->getLastName()));
        [$subject, $text, $html] = $this->buildMessage($reason, $learnerName);

        $message = (new Email())
            ->from($this->fromAddress)
            ->to($email)
            ->subject($subject)
            ->text($text)
            ->html($html);

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface) {
            // Swallowed on purpose, même raisonnement que AbsenceNotificationService.
        }

        $this->communicationLogger->log($learner, LearnerCommunication::TYPE_ELEARNING_REMINDER, $actor, [
            'to' => $email,
            'subject' => $subject,
            'text' => $text,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [subject, text, html]
     */
    private function buildMessage(string $reason, string $learnerName): array
    {
        if ($reason === self::REASON_SCHEDULE) {
            $subject = 'TrackUp - Respect des horaires de connexion e-learning';
            $text = "Bonjour {$learnerName},\n\n"
                . "Nous vous rappelons que, lors des sessions e-learning, vous devez rester connecté(e) sur "
                . "les créneaux prévus :\n"
                . "- 8h00 à 12h00\n"
                . "- 14h00 à 17h00\n\n"
                . "Merci de veiller au respect de ces horaires pour la suite de votre parcours.\n\n"
                . "Pour toute question, n'hésitez pas à contacter l'équipe pédagogique.\n\n"
                . "Cordialement,\nL'équipe pédagogique\n";
            $html = "<p>Bonjour {$learnerName},</p>"
                . "<p>Nous vous rappelons que, lors des sessions e-learning, vous devez rester connecté(e) sur "
                . "les créneaux prévus :</p>"
                . "<ul><li>8h00 à 12h00</li><li>14h00 à 17h00</li></ul>"
                . "<p>Merci de veiller au respect de ces horaires pour la suite de votre parcours.</p>"
                . "<p>Pour toute question, n'hésitez pas à contacter l'équipe pédagogique.</p>"
                . "<p>Cordialement,<br>L'équipe pédagogique</p>";

            return [$subject, $text, $html];
        }

        $subject = 'TrackUp - Avancement e-learning insuffisant';
        $text = "Bonjour {$learnerName},\n\n"
            . "Nous constatons que votre avancement dans les modules e-learning n'est pas conforme à ce qui "
            . "est attendu à ce stade de votre parcours.\n\n"
            . "Merci de vous connecter à votre espace de formation pour finaliser les modules en cours dans "
            . "les meilleurs délais.\n\n"
            . "Pour toute difficulté, n'hésitez pas à contacter l'équipe pédagogique.\n\n"
            . "Cordialement,\nL'équipe pédagogique\n";
        $html = "<p>Bonjour {$learnerName},</p>"
            . "<p>Nous constatons que votre avancement dans les modules e-learning n'est pas conforme à ce "
            . "qui est attendu à ce stade de votre parcours.</p>"
            . "<p>Merci de vous connecter à votre espace de formation pour finaliser les modules en cours "
            . "dans les meilleurs délais.</p>"
            . "<p>Pour toute difficulté, n'hésitez pas à contacter l'équipe pédagogique.</p>"
            . "<p>Cordialement,<br>L'équipe pédagogique</p>";

        return [$subject, $text, $html];
    }
}
