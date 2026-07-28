<?php
declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener]
class MessengerFailedMessageListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $envelope = $event->getEnvelope();

        $this->logger->critical('Messenger message exhausted its retries and landed in the failed transport.', [
            'message' => $envelope->getMessage()::class,
            'exception' => $event->getThrowable()->getMessage(),
            'receiverName' => $event->getReceiverName(),
        ]);
    }
}
