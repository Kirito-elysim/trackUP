<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Api\Admin\AbsenceController;
use App\Entity\Absence;
use App\Entity\ClassroomSessionRegistration;
use App\Entity\Learner;
use App\Entity\User;
use App\Service\AbsenceEventLogger;
use App\Service\AbsenceNotificationService;
use App\Service\AbsenceStreakService;
use App\Service\UserPermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

final class AbsenceNotificationControllerTest extends TestCase
{
    public function testFailedResendDoesNotReturnSuccess(): void
    {
        [$controller, $notifications] = $this->fixture();
        $notifications->method('resend')->willReturn(['renewed' => false, 'delivered' => false]);
        $response = $controller->resendNotification(1, new Request());
        self::assertSame(502, $response->getStatusCode());
        self::assertArrayHasKey('message', json_decode($response->getContent(), true));
    }

    public function testSuccessfulResendKeepsBooleanContract(): void
    {
        [$controller, $notifications] = $this->fixture();
        $notifications->method('resend')->willReturn(['renewed' => false, 'delivered' => true]);
        $response = $controller->resendNotification(1, new Request());
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse(json_decode($response->getContent(), true)['renewed']);
    }

    public function testFailedDisciplinaryAlertReturnsBadGateway(): void
    {
        [$controller, $notifications] = $this->fixture();
        $notifications->method('sendDisciplinaryAlert')->willReturn(false);
        self::assertSame(502, $controller->resendAlert(1)->getStatusCode());
    }

    public function testFailedDisciplinaryEmailReturnsBadGateway(): void
    {
        [$controller, $notifications] = $this->fixture();
        $notifications->method('sendDisciplinaryEmailToLearner')->willReturn(false);
        self::assertSame(502, $controller->sendDisciplinaryEmailToLearner(1)->getStatusCode());
    }

    private function fixture(): array
    {
        $learner = (new Learner())->setEmail('learner@example.test');
        $absence = new Absence((new ClassroomSessionRegistration())->setLearner($learner), Absence::TYPE_MASTERCLASS);
        $absenceRepository = $this->createStub(EntityRepository::class);
        $absenceRepository->method('find')->willReturn($absence);
        $learnerRepository = $this->createStub(EntityRepository::class);
        $learnerRepository->method('find')->willReturn($learner);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([[Absence::class, $absenceRepository], [Learner::class, $learnerRepository]]);
        $permissions = $this->createStub(UserPermissionResolver::class);
        $permissions->method('userHasFeature')->willReturn(true);
        $notifications = $this->createStub(AbsenceNotificationService::class);
        $controller = $this->getMockBuilder(AbsenceController::class)
            ->setConstructorArgs([$em, $permissions, $notifications, $this->createStub(AbsenceStreakService::class), $this->createStub(AbsenceEventLogger::class), new \App\Service\JustificationFileStorage('/unused'), '/unused'])
            ->onlyMethods(['getUser'])->getMock();
        $controller->expects(self::atLeastOnce())->method('getUser')->willReturn(new User());
        $controller->setContainer(new ContainerBuilder());
        return [$controller, $notifications];
    }
}
