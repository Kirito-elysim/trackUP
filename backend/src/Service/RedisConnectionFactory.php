<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Cache\Adapter\RedisAdapter;

final class RedisConnectionFactory
{
    public static function create(#[\SensitiveParameter] string $messengerDsn): \Redis
    {
        $parts = parse_url($messengerDsn);
        if ($parts === false || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['redis', 'rediss'], true)) {
            throw new \InvalidArgumentException('A Redis transport DSN is required.');
        }
        parse_str($parts['query'] ?? '', $options);
        $auth = isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '';
        $dsn = sprintf('%s://%s%s:%d/%d', $parts['scheme'], $auth, $parts['host'], $parts['port'] ?? 6379, (int) ($options['dbindex'] ?? 0));

        return RedisAdapter::createConnection($dsn, ['class' => \Redis::class, 'lazy' => true, 'timeout' => 2, 'read_timeout' => 2]);
    }
}
