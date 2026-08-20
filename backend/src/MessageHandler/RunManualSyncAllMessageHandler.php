<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\SyncRun;
use App\Entity\User;
use App\Message\RunManualSyncAllMessage;
use App\Service\SyncOrchestratorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunManualSyncAllMessageHandler
{
    public function __construct(
        private readonly SyncOrchestratorService $orchestrator,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunManualSyncAllMessage $message): void
    {
        $triggeredBy = $this->entityManager->getRepository(User::class)->find($message->triggeredByUserId);

        $this->logger->info('Starting manual grouped Rise Up sync (all 9 datasets).', [
            'triggeredByUserId' => $message->triggeredByUserId,
        ]);

        $run = $this->orchestrator->runAll(SyncRun::TRIGGER_MANUAL, $triggeredBy);

        $this->logger->info('Manual grouped Rise Up sync finished.', ['status' => $run->getStatus()]);
    }
}
