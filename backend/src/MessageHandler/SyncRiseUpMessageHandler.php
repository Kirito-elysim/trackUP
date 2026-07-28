<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncRiseUpMessage;
use App\Service\ClassroomSessionSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncRiseUpMessageHandler
{
    public function __construct(
        private readonly ClassroomSessionSyncService $classroomSessionSyncService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncRiseUpMessage $message): void
    {
        if ($message->scope !== 'sessions') {
            $this->logger->warning('Ignored SyncRiseUpMessage with unsupported scope.', [
                'scope' => $message->scope,
            ]);

            return;
        }

        $this->logger->info('Starting Rise Up sessions sync (async).');

        $result = $this->classroomSessionSyncService->sync();

        $this->logger->info('Rise Up sessions sync completed (async).', [
            'result' => $result,
        ]);
    }
}

