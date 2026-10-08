<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Api\Admin\AbsenceController;
use App\Entity\Absence;
use App\Entity\AbsenceEvent;
use App\Entity\ClassroomSession;
use App\Entity\ClassroomSessionRegistration;
use App\Entity\User;
use App\Service\AbsenceEventLogger;
use App\Service\AbsenceNotificationService;
use App\Service\AbsenceStreakService;
use App\Service\JustificationFileStorage;
use App\Service\UserPermissionResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class AdminJustificationUploadTest extends TestCase
{
    private string $directory;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trackup-admin-upload-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach ([...glob($this->directory . '/*') ?: [], ...$this->tempFiles] as $file) {
            if (is_file($file)) { unlink($file); }
        }
        if (is_dir($this->directory)) { rmdir($this->directory); }
    }

    public function testTeamCanAttachAFileToAnAlreadyExpiredAbsenceWithoutChangingItsStatus(): void
    {
        $absence = $this->absence(Absence::STATUS_NON_JUSTIFIEE);
        mkdir($this->directory);
        file_put_contents($this->directory . '/previous.pdf', 'old');
        $absence->setJustificationFile('previous.pdf', 'ancien.pdf');
        $logger = $this->createMock(AbsenceEventLogger::class);
        $logger->expects(self::once())->method('log')->with(
            $absence,
            AbsenceEvent::TYPE_JUSTIFICATION_SUBMITTED,
            self::isInstanceOf(User::class),
            self::callback(static fn (array $meta): bool => $meta['uploadedByTeam'] === true && $meta['replacement'] === true && $meta['fileOriginalName'] === 'certificat.pdf'),
        );
        $controller = $this->controller($absence, true, $logger);

        $response = $controller->uploadJustificationFile(1, $this->request($this->pdf('certificat.pdf')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Absence::STATUS_NON_JUSTIFIEE, $absence->getStatus());
        self::assertSame('certificat.pdf', $absence->getJustificationFileOriginalName());
        self::assertNotNull($absence->getJustificationSubmittedAt());
        // The previous file is replaced on disk, only the new one remains.
        self::assertSame([$this->directory . '/' . $absence->getJustificationFilePath()], glob($this->directory . '/*'));
    }

    public function testWithoutManagePermissionNothingIsStored(): void
    {
        $controller = $this->controller($this->absence(Absence::STATUS_EN_ATTENTE), false);

        self::assertSame(403, $controller->uploadJustificationFile(1, $this->request($this->pdf('certificat.pdf')))->getStatusCode());
        self::assertSame([], glob($this->directory . '/*') ?: []);
    }

    public function testRenamedTextFileIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'trackup-upload-');
        $this->tempFiles[] = $path;
        file_put_contents($path, 'plain text');
        $controller = $this->controller($this->absence(Absence::STATUS_EN_ATTENTE), true);

        $response = $controller->uploadJustificationFile(1, $this->request(new UploadedFile($path, 'faux.pdf', 'application/pdf', null, true)));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], glob($this->directory . '/*') ?: []);
    }

    private function absence(string $status): Absence
    {
        return (new Absence((new ClassroomSessionRegistration())->setSession(new ClassroomSession()), Absence::TYPE_MASTERCLASS))->setStatus($status);
    }

    private function pdf(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'trackup-upload-');
        $this->tempFiles[] = $path;
        file_put_contents($path, "%PDF-1.4\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function request(UploadedFile $file): Request
    {
        return new Request([], [], [], [], ['file' => $file]);
    }

    private function controller(Absence $absence, bool $canManage, ?AbsenceEventLogger $logger = null): AbsenceController
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn($absence);
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->method('getConnection')->willReturn($connection);
        $permissions = $this->createStub(UserPermissionResolver::class);
        $permissions->method('userHasFeature')->willReturn($canManage);

        $controller = $this->getMockBuilder(AbsenceController::class)
            ->setConstructorArgs([
                $em,
                $permissions,
                $this->createStub(AbsenceNotificationService::class),
                $this->createStub(AbsenceStreakService::class),
                $logger ?? $this->createStub(AbsenceEventLogger::class),
                new JustificationFileStorage($this->directory),
                $this->directory,
            ])
            ->onlyMethods(['getUser'])->getMock();
        $controller->expects(self::atLeastOnce())->method('getUser')->willReturn(new User());
        $controller->setContainer(new ContainerBuilder());

        return $controller;
    }
}
