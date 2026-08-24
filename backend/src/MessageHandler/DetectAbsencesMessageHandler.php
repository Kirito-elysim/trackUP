<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DetectAbsencesMessage;
use App\Service\AbsenceDetectionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DetectAbsencesMessageHandler
{
    public function __construct(
        private readonly AbsenceDetectionService $absenceDetectionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DetectAbsencesMessage $message): void
    {
        $this->logger->info('Starting scheduled absence detection.');

        $detected = $this->absenceDetectionService->detect();

        $this->logger->info('Scheduled absence detection finished.', ['detected' => $detected]);
    }
}
