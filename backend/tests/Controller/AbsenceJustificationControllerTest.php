<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Api\AbsenceJustificationController;
use App\Entity\Absence;
use App\Entity\ClassroomSession;
use App\Entity\ClassroomSessionRegistration;
use App\Service\AbsenceEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class AbsenceJustificationControllerTest extends TestCase
{
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
            if (is_dir($directory)) { rmdir($directory); }
        }
    }

    #[DataProvider('finalStatuses')]
    public function testFinalAbsenceCannotBeChanged(string $status): void
    {
        [$controller, $absence, $em] = $this->fixture();
        $absence->setStatus($status);
        $em->expects(self::never())->method('flush');
        $response = $controller->submit(new Request([], ['token' => 'test-token']));
        self::assertSame(409, $response->getStatusCode());
        self::assertNull($absence->getJustificationFilePath());
    }

    public static function finalStatuses(): array
    {
        return [['justifiee'], ['non_justifiee'], ['autre']];
    }

    public function testStatusAllowsReadingButNotReplacingValidatedDocument(): void
    {
        [$controller, $absence] = $this->fixture();
        $absence->setStatus(Absence::STATUS_JUSTIFIEE);
        $response = $controller->status(new Request(['token' => 'test-token']));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse(json_decode($response->getContent(), true)['canSubmit'] ?? true);
    }

    public function testRenamedTextFileIsRejected(): void
    {
        [$controller, , $em] = $this->fixture();
        $em->expects(self::never())->method('flush');
        $path = tempnam(sys_get_temp_dir(), 'trackup-upload-');
        file_put_contents($path, 'This is not a PDF.');
        try {
            $file = new UploadedFile($path, 'document.pdf', 'application/pdf', null, true);
            $response = $controller->submit(new Request([], ['token' => 'test-token'], [], [], ['file' => $file]));
            self::assertSame(400, $response->getStatusCode());
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }

    public function testPhpUploadErrorReturnsAnActionableResponse(): void
    {
        [$controller] = $this->fixture();
        $file = new UploadedFile('', 'document.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);
        $response = $controller->submit(new Request([], ['token' => 'test-token'], [], [], ['file' => $file]));
        self::assertSame(413, $response->getStatusCode());
    }

    public function testTenMebibytePdfIsAccepted(): void
    {
        [$controller, $absence, $em, $directory] = $this->fixture();
        $em->expects(self::once())->method('commit');
        $path = tempnam(sys_get_temp_dir(), 'trackup-upload-');
        file_put_contents($path, '%PDF-1.4' . str_repeat(' ', 10 * 1024 * 1024 - 8));
        try {
            $file = new UploadedFile($path, 'document.pdf', 'application/pdf', null, true);
            $response = $controller->submit(new Request([], ['token' => 'test-token'], [], [], ['file' => $file]));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(10 * 1024 * 1024, filesize($directory . '/' . $absence->getJustificationFilePath()));
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }

    public function testConcurrentValidationIsCheckedUnderTheDatabaseLock(): void
    {
        [$controller, $absence, $em] = $this->fixture();
        $em->expects(self::once())->method('refresh')->willReturnCallback(static function () use ($absence): void { $absence->setStatus(Absence::STATUS_JUSTIFIEE); });
        $em->expects(self::once())->method('rollback');
        $em->expects(self::never())->method('flush');
        $path = tempnam(sys_get_temp_dir(), 'trackup-upload-');
        file_put_contents($path, "%PDF-1.4\n");
        try {
            $file = new UploadedFile($path, 'document.pdf', 'application/pdf', null, true);
            self::assertSame(409, $controller->submit(new Request([], ['token' => 'test-token'], [], [], ['file' => $file]))->getStatusCode());
        } finally { unlink($path); }
    }

    public function testDatabaseFailurePreservesPreviousDocument(): void
    {
        [$controller, $absence, $em, $directory] = $this->fixture();
        mkdir($directory);
        file_put_contents($directory . '/previous.pdf', 'previous');
        $absence->setJustificationFile('previous.pdf', 'previous.pdf');
        $em->expects(self::once())->method('flush')->willThrowException(new \RuntimeException('database failure'));
        $em->expects(self::once())->method('rollback');
        $path = tempnam(sys_get_temp_dir(), 'trackup-upload-');
        file_put_contents($path, "%PDF-1.4\n");
        try {
            $controller->submit(new Request([], ['token' => 'test-token'], [], [], ['file' => new UploadedFile($path, 'document.pdf', null, null, true)]));
            self::fail('Expected the persistence failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('database failure', $exception->getMessage());
            self::assertSame([$directory . '/previous.pdf'], glob($directory . '/*'));
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }

    private function fixture(): array
    {
        $registration = (new ClassroomSessionRegistration())->setSession(new ClassroomSession());
        $absence = (new Absence($registration, Absence::TYPE_MASTERCLASS))
            ->setJustificationToken('test-token', new \DateTimeImmutable('+1 day'));
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($absence);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::atLeastOnce())->method('getRepository')->willReturn($repository);
        $directory = sys_get_temp_dir() . '/trackup-documents-' . bin2hex(random_bytes(8));
        $this->directories[] = $directory;
        $controller = new AbsenceJustificationController($em, $this->createStub(AbsenceEventLogger::class), new \App\Service\JustificationFileStorage($directory), $directory);
        $controller->setContainer(new ContainerBuilder());
        return [$controller, $absence, $em, $directory];
    }
}
