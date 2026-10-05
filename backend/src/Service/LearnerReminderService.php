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

        $learnerFirstName = trim((string) $learner->getFirstName());
        [$subject, $text, $html] = $this->buildMessage($reason, $learnerFirstName);

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
    private function buildMessage(string $reason, string $learnerFirstName): array
    {
        if ($reason === self::REASON_SCHEDULE) {
            $subject = 'Respect des horaires de connexion e-learning';
            $text = "Bonjour {$learnerFirstName},\n\n"
                . "Nous constatons que les horaires de connexion prévus lors de vos journées de formation en e-learning ne sont pas pleinement respectés.\n\n"
                . "Nous vous rappelons que, lors de ces journées, vous devez être connecté(e) à votre espace de formation et réaliser vos activités pédagogiques sur les créneaux prévus :\n\n"
                . "- 8h00 à 12h00\n"
                . "- 14h00 à 17h00\n\n"
                . "Ces horaires correspondent à votre temps de formation et doivent être respectés au même titre que les horaires des masterclass.\n\n"
                . "Nous vous demandons donc de veiller à leur respect dès votre prochaine journée de formation.\n\n"
                . "Si vous rencontrez une difficulté particulière vous empêchant de respecter ces créneaux, merci de vous rapprocher de l’équipe pédagogique afin que nous puissions échanger sur votre situation.\n\n"
                . "Cordialement,\n\n"
                . "L’équipe pédagogique\n\n"
                . "Ed’Up Business School\n";
            $html = "<p>Bonjour {$learnerFirstName},</p>"
                . "<p>Nous constatons que les horaires de connexion prévus lors de vos journées de formation en "
                . "e-learning ne sont pas pleinement respectés.</p>"
                . "<p>Nous vous rappelons que, lors de ces journées, vous devez être connecté(e) à votre espace de "
                . "formation et réaliser vos activités pédagogiques sur les créneaux prévus :</p>"
                . "<ul><li>8h00 à 12h00</li><li>14h00 à 17h00</li></ul>"
                . "<p>Ces horaires correspondent à votre temps de formation et doivent être respectés au même titre "
                . "que les horaires des masterclass.</p>"
                . "<p>Nous vous demandons donc de veiller à leur respect dès votre prochaine journée de formation.</p>"
                . "<p>Si vous rencontrez une difficulté particulière vous empêchant de respecter ces créneaux, merci "
                . "de vous rapprocher de l’équipe pédagogique afin que nous puissions échanger sur votre situation.</p>"
                . "<p>Cordialement,</p>"
                . "<p style=\"margin-top: 24px;\">L’équipe pédagogique<br>Ed’Up Business School</p>";

            return [$subject, $text, $html];
        }

        $subject = 'Avancement e-learning insuffisant';
        $text = "Bonjour {$learnerFirstName},\n\n"
            . "Nous constatons que votre avancement dans les modules e-learning est actuellement insuffisant au regard de la progression attendue à ce stade de votre parcours de formation.\n\n"
            . "Nous vous invitons à vous connecter dès que possible à votre espace de formation afin de reprendre les modules en cours et de régulariser votre avancement dans les meilleurs délais.\n\n"
            . "Nous vous rappelons que le travail réalisé en e-learning fait pleinement partie de votre parcours et de votre temps de formation. Une progression régulière est donc indispensable.\n\n"
            . "Si vous rencontrez une difficulté particulière (accès à la plateforme, compréhension des contenus, organisation ou autre), n’hésitez pas à contacter l’équipe pédagogique afin que nous puissions vous accompagner.\n\n"
            . "Merci de prendre les dispositions nécessaires pour reprendre votre progression.\n\n"
            . "Cordialement,\n\n"
            . "L’équipe pédagogique\n\n"
            . "Ed’Up Business School\n";
        $html = "<p>Bonjour {$learnerFirstName},</p>"
            . "<p>Nous constatons que votre avancement dans les modules e-learning est actuellement insuffisant au "
            . "regard de la progression attendue à ce stade de votre parcours de formation.</p>"
            . "<p>Nous vous invitons à vous connecter dès que possible à votre espace de formation afin de reprendre "
            . "les modules en cours et de régulariser votre avancement dans les meilleurs délais.</p>"
            . "<p>Nous vous rappelons que le travail réalisé en e-learning fait pleinement partie de votre parcours "
            . "et de votre temps de formation. Une progression régulière est donc indispensable.</p>"
            . "<p>Si vous rencontrez une difficulté particulière (accès à la plateforme, compréhension des contenus, "
            . "organisation ou autre), n’hésitez pas à contacter l’équipe pédagogique afin que nous puissions vous accompagner.</p>"
            . "<p>Merci de prendre les dispositions nécessaires pour reprendre votre progression.</p>"
            . "<p>Cordialement,</p>"
            . "<p style=\"margin-top: 24px;\">L’équipe pédagogique<br>Ed’Up Business School</p>";

        return [$subject, $text, $html];
    }
}
