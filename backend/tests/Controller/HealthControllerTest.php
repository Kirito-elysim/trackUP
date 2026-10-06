<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Api\HealthController;
use App\Service\DependencyHealth;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class HealthControllerTest extends TestCase
{
    public function testHealthyDependenciesReturn200(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn(1);
        $redis = $this->createStub(\Redis::class);
        $redis->method('ping')->willReturn(true);
        $controller = new HealthController(new DependencyHealth($db, $redis));
        $controller->setContainer(new ContainerBuilder());
        self::assertSame(200, $controller()->getStatusCode());
    }

    public function testDependencyFailureReturns503WithoutExceptionDetails(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willThrowException(new \RuntimeException('sensitive-database-details'));
        $redis = $this->createStub(\Redis::class);
        $redis->method('ping')->willThrowException(new \RedisException('sensitive-redis-details'));
        $controller = new HealthController(new DependencyHealth($db, $redis));
        $controller->setContainer(new ContainerBuilder());
        $response = $controller();
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('sensitive-', $response->getContent());
        self::assertSame(['mysql' => 'down', 'redis' => 'down'], json_decode($response->getContent(), true)['checks']);
    }
}
