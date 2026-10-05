<?php
declare(strict_types=1);
namespace App\Tests\Service;

use App\Repository\RiseUpActivityLogFilters;
use App\Repository\RiseUpActivityLogRepository;
use App\Service\ActivityLogPdfService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ActivityLogPdfServiceTest extends TestCase
{
    public function testRendersSignedAndUnsignedSessionsBeforeLearnerLogs(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([['id' => 1, 'durationSeconds' => 3600, 'sourceType' => 'elearning', 'loginAt' => '2026-09-01 08:00:00', 'logoutAt' => '2026-09-01 09:00:00', 'learnerFullName' => 'Alice Exemple', 'learnerEmail' => 'alice@example.test', 'trainingTitle' => 'Module de formation']]);
        $db->method('fetchAssociative')->willReturn(['logCount' => 1, 'uniqueLearnersCount' => 1, 'uniqueTrainingsCount' => 1, 'totalDurationSeconds' => 3600]);
        $service = new ActivityLogPdfService(new RiseUpActivityLogRepository($db), '/app/assets/logo/trackup-logo.png');
        $rows = [];
        foreach ([true, false] as $signed) {
            $rows[] = ['title' => 'Classe virtuelle de démonstration', 'start_at' => '2026-09-01 08:00:00', 'end_at' => '2026-09-01 17:00:00', 'attendance_date' => '2026-09-01', 'period' => $signed ? 'Matin' : 'Après-midi', 'has_signed' => $signed, 'signature_date' => $signed ? '2026-09-01 08:05:00' : null];
        }
        $pdf = $service->render(new RiseUpActivityLogFilters(learnerId: 1, learningPathId: 9), 'Alice Exemple — Parcours de démonstration', $rows);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(1000, strlen($pdf));
        if (getenv('TRACKUP_PDF_QA')) { file_put_contents('/tmp/trackup-logs-qa.pdf', $pdf); }
    }
}
