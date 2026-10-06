<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WorkerHeartbeat;
use PHPUnit\Framework\TestCase;

final class WorkerHeartbeatTest extends TestCase
{
    public function testMissingStoppedAndStaleHeartbeatsAreUnhealthy(): void
    {
        $path = sys_get_temp_dir() . '/trackup-heartbeat-' . bin2hex(random_bytes(8));
        $heartbeat = new WorkerHeartbeat($path);
        try {
            self::assertFalse($heartbeat->isHealthy());
            $heartbeat->start();
            self::assertTrue($heartbeat->isHealthy());
            $heartbeat->stop();
            self::assertFalse($heartbeat->isHealthy());
            file_put_contents($path, json_encode(['state' => 'idle', 'updatedAt' => time() - 100]));
            self::assertFalse($heartbeat->isHealthy());
            file_put_contents($path, json_encode(['state' => 'processing', 'updatedAt' => time() - 901]));
            self::assertFalse($heartbeat->isHealthy());
            file_put_contents($path, 'invalid');
            self::assertFalse($heartbeat->isHealthy());
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }

    public function testProcessingProgressRefreshesHeartbeatWithoutMarkingWorkAsComplete(): void
    {
        $path = sys_get_temp_dir() . '/trackup-heartbeat-' . bin2hex(random_bytes(8));
        $heartbeat = new WorkerHeartbeat($path);
        try {
            $heartbeat->progress();
            self::assertFileDoesNotExist($path);
            $heartbeat->start();
            $heartbeat->processing();
            $heartbeat->progress();
            self::assertTrue($heartbeat->isHealthy());
            self::assertSame('processing', json_decode(file_get_contents($path), true)['state']);
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }
}
