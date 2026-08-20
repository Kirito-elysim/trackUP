<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\SyncRun;
use App\Message\RunScheduledSyncMessage;
use App\Service\SyncOrchestratorService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunScheduledSyncMessageHandler
{
    public function __construct(
        private readonly SyncOrchestratorService $orchestrator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunScheduledSyncMessage $message): void
    {
        $this->logger->info('Starting scheduled Rise Up sync (all 9 datasets).');

        $run = $this->orchestrator->runAll(SyncRun::TRIGGER_SCHEDULED);

        $this->logger->info('Scheduled Rise Up sync finished.', ['status' => $run->getStatus()]);
    }
}
