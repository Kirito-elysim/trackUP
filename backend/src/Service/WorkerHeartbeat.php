<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

final class WorkerHeartbeat implements EventSubscriberInterface
{
    private bool $active = false;
    private string $state = 'idle';
    private int $lastWritten = 0;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/worker-heartbeat.json')] private readonly string $path,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'start',
            WorkerRunningEvent::class => 'idle',
            WorkerMessageReceivedEvent::class => 'processing',
            WorkerMessageHandledEvent::class => 'idle',
            WorkerMessageFailedEvent::class => 'idle',
            WorkerStoppedEvent::class => 'stop',
        ];
    }

    public function start(): void
    {
        $this->active = true;
        $this->idle();
    }

    public function idle(): void
    {
        $this->state = 'idle';
        $this->lastWritten = 0;
        $this->progress();
    }

    public function processing(): void
    {
        $this->state = 'processing';
        $this->lastWritten = 0;
        $this->progress();
    }

    public function stop(): void
    {
        $this->state = 'stopped';
        $this->lastWritten = 0;
        $this->progress();
        $this->active = false;
    }

    public function progress(): void
    {
        if (!$this->active || $this->lastWritten === time()) {
            return;
        }
        $data = json_encode(['pid' => getmypid(), 'state' => $this->state, 'updatedAt' => time()], JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path . '.tmp', $data, LOCK_EX) === false || !rename($this->path . '.tmp', $this->path)) {
            throw new \RuntimeException('Unable to record worker progress.');
        }
        $this->lastWritten = time();
    }

    public function isHealthy(int $maxIdle = 90, int $maxStall = 900): bool
    {
        if (!is_file($this->path)) {
            return false;
        }
        $data = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($data) || !in_array($data['state'] ?? '', ['idle', 'processing'], true) || !is_int($data['updatedAt'] ?? null)) {
            return false;
        }
        $age = time() - $data['updatedAt'];
        return $age >= 0 && $age <= ($data['state'] === 'processing' ? $maxStall : $maxIdle);
    }
}
