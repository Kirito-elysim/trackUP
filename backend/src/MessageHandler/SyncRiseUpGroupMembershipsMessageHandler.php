<?php

namespace App\MessageHandler;

use App\Message\SyncRiseUpGroupMembershipsMessage;
use App\Service\RiseUpGroupMembershipSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncRiseUpGroupMembershipsMessageHandler
{
    public function __construct(
        private readonly RiseUpGroupMembershipSyncService $groupMembershipSyncService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncRiseUpGroupMembershipsMessage $message): void
    {
        $this->logger->info('Starting Rise Up group memberships sync (async).');

        $result = $this->groupMembershipSyncService->syncAll();

        $this->logger->info('Rise Up group memberships sync completed (async).', [
            'result' => $result,
        ]);
    }
}
