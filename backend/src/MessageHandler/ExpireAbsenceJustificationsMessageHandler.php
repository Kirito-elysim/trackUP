<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ExpireAbsenceJustificationsMessage;
use App\Service\AbsenceExpiryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ExpireAbsenceJustificationsMessageHandler
{
    public function __construct(
        private readonly AbsenceExpiryService $absenceExpiryService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExpireAbsenceJustificationsMessage $message): void
    {
        $this->logger->info('Starting scheduled absence justification expiry.');

        $expired = $this->absenceExpiryService->expireOverdue();

        $this->logger->info('Scheduled absence justification expiry finished.', ['expired' => $expired]);
    }
}
