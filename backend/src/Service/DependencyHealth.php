<?php
declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DependencyHealth
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'app.redis')] private readonly \Redis $redis,
    ) {
    }

    public function check(): array
    {
        try {
            $mysql = (int) $this->connection->fetchOne('SELECT 1') === 1;
        } catch (\Throwable) {
            $mysql = false;
        }
        try {
            $redis = in_array($this->redis->ping(), [true, 'PONG', '+PONG'], true);
        } catch (\Throwable) {
            $redis = false;
        }
        return ['mysql' => $mysql ? 'ok' : 'down', 'redis' => $redis ? 'ok' : 'down'];
    }
}
