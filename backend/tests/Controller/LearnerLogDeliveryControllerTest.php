<?php
declare(strict_types=1);
namespace App\Tests\Controller;

use App\Controller\Api\LearnerLogDeliveryController;
use App\Entity\Learner;
use App\Entity\Tutor;
use App\Entity\User;
use App\Service\ActivityLogPdfService;
use App\Service\LearnerCommunicationLogger;
use App\Service\UserPermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class LearnerLogDeliveryControllerTest extends TestCase
{
    public function testConfirmedDraftSendsOnlyToTutorAndCannotBeSentTwice(): void
    {
        [$controller, $file, $token, $mailer] = $this->fixture('tutor@example.test');
        $mailer->expects(self::once())->method('send')->with(self::callback(static function (Email $email): bool {
            return $email->getTo()[0]->getAddress() === 'tutor@example.test' && count($email->getAttachments()) === 1;
        }));
        try {
            self::assertSame('sent', json_decode($controller->send($token)->getContent(), true)['items'][0]['status']);
            self::assertSame('sent', json_decode($controller->send($token)->getContent(), true)['items'][0]['status']);
        } finally { unlink($file); rmdir(dirname($file)); }
    }

    public function testChangedTutorAddressBlocksDelivery(): void
    {
        [$controller, $file, $token, $mailer] = $this->fixture('changed@example.test');
        $mailer->expects(self::never())->method('send');
        try {
            self::assertSame('failed', json_decode($controller->send($token)->getContent(), true)['items'][0]['status']);
        } finally { unlink($file); rmdir(dirname($file)); }
    }

    public function testMissingPdfBlocksDelivery(): void
    {
        [$controller, $file, $token, $mailer] = $this->fixture('tutor@example.test');
        $mailer->expects(self::never())->method('send');
        $draft = json_decode(file_get_contents($file), true); unset($draft['items'][0]['pdf']); file_put_contents($file, json_encode($draft));
        try { self::assertSame(422, $controller->send($token)->getStatusCode()); }
        finally { unlink($file); rmdir(dirname($file)); }
    }

    private function fixture(string $currentEmail): array
    {
        $actor = new User(); (new \ReflectionProperty(User::class, 'id'))->setValue($actor, 7);
        $learner = (new Learner())->setTutor((new Tutor())->setFirstName('Tutor')->setLastName('Example')->setEmail($currentEmail));
        $em = $this->createStub(EntityManagerInterface::class); $em->method('find')->willReturn($learner);
        $permissions = $this->createStub(UserPermissionResolver::class); $permissions->method('userHasFeature')->willReturn(true);
        $mailer = $this->createMock(MailerInterface::class);
        $directory = sys_get_temp_dir() . '/trackup-delivery-test-' . bin2hex(random_bytes(8)); mkdir($directory);
        $token = bin2hex(random_bytes(24)); $file = $directory . '/' . $token . '.json';
        file_put_contents($file, json_encode(['actor' => 7, 'expires' => time() + 100, 'learningPathId' => 9, 'items' => [['learnerId' => 1, 'problem' => null, 'status' => 'pending', 'email' => 'tutor@example.test', 'subject' => 'Logs', 'text' => 'Bonjour', 'pdf' => base64_encode('%PDF-test'), 'filename' => 'logs.pdf']]]));
        $controller = $this->getMockBuilder(LearnerLogDeliveryController::class)->setConstructorArgs([$em, $permissions, $this->createStub(ActivityLogPdfService::class), $mailer, $this->createStub(LearnerCommunicationLogger::class), 'school@example.test', $directory])->onlyMethods(['getUser'])->getMock();
        $controller->expects(self::atLeastOnce())->method('getUser')->willReturn($actor); $controller->setContainer(new ContainerBuilder());
        return [$controller, $file, $token, $mailer];
    }
}
