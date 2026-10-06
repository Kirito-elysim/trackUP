<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Absence;
use App\Entity\ClassroomSession;
use App\Entity\ClassroomSessionRegistration;
use App\Entity\Learner;
use App\Service\AbsenceEventLogger;
use App\Service\AbsenceNotificationService;
use App\Service\LearnerCommunicationLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;

final class AbsenceNotificationServiceTest extends TestCase
{
    public function testFailedNotificationDoesNotMarkEmailAsSent(): void
    {
        [$service, $absence] = $this->fixture();
        self::assertFalse($service->notify($absence));
        self::assertNull($absence->getNotificationSentAt());
    }

    public function testFailedConfirmationDoesNotMarkEmailAsSent(): void
    {
        [$service, $absence] = $this->fixture();
        self::assertFalse($service->sendConfirmation($absence));
        self::assertNull($absence->getConfirmationSentAt());
    }

    public function testFailedResendPreservesBooleanResultAndValidDeadline(): void
    {
        [$service, $absence] = $this->fixture();
        $deadline = new \DateTimeImmutable('+2 days');
        $absence->setJustificationToken('existing-test-token', $deadline);
        self::assertSame(['renewed' => false, 'delivered' => false], $service->resend($absence, null));
        self::assertSame($deadline, $absence->getJustificationTokenExpiresAt());
        self::assertNull($absence->getNotificationSentAt());
    }

    private function fixture(): array
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException(new TransportException('Simulated SMTP failure'));
        $em = $this->createStub(EntityManagerInterface::class);
        $service = new AbsenceNotificationService($mailer, new AbsenceEventLogger($em), new LearnerCommunicationLogger($em), new NullLogger(), 'https://example.test', 'school@example.test', 'school@example.test');
        $registration = (new ClassroomSessionRegistration())->setLearner((new Learner())->setEmail('learner@example.test'))->setSession(new ClassroomSession());
        return [$service, new Absence($registration, Absence::TYPE_MASTERCLASS)];
    }
}
