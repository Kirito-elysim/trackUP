<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\EventListener\AuthRateLimitListener;
use App\Security\AuthenticationFailureHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

final class AuthRateLimitListenerTest extends TestCase
{
    public function testResetIsLimitedRegardlessOfAccountExistenceOrClientAddress(): void
    {
        $listener = $this->listener();
        for ($i = 0; $i < 4; ++$i) {
            $event = $this->event('/api/auth/forgot-password', ['email' => ' User@Example.test '], '203.0.113.' . ($i + 1));
            $listener($event);
            self::assertSame($i < 3 ? null : 429, $event->getResponse()?->getStatusCode());
        }
        self::assertNotNull($event->getResponse()?->headers->get('Retry-After'));
    }

    public function testResetTokenAttemptsAreLimitedPerAddress(): void
    {
        $listener = $this->listener();
        for ($i = 0; $i < 4; ++$i) {
            $event = $this->event('/api/auth/reset-password', ['token' => 'different-' . $i]);
            $listener($event);
        }
        self::assertSame(429, $event->getResponse()?->getStatusCode());
    }

    public function testSpoofedForwardingHeaderDoesNotBypassIpLimit(): void
    {
        $listener = $this->listener(2);
        for ($i = 0; $i < 3; ++$i) {
            $event = $this->event('/api/auth/login', []);
            $event->getRequest()->headers->set('X-Forwarded-For', '198.51.100.' . ($i + 1));
            $listener($event);
        }
        self::assertSame(429, $event->getResponse()?->getStatusCode());
    }

    public function testLoginThrottleReturns429(): void
    {
        $response = (new AuthenticationFailureHandler())->onAuthenticationFailure(new Request(), new TooManyLoginAttemptsAuthenticationException(15));
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('900', $response->headers->get('Retry-After'));
    }

    public function testInvalidEmailTypeIsRejectedWithoutAWarning(): void
    {
        $event = $this->event('/api/auth/forgot-password', ['email' => ['invalid']]);
        ($this->listener())($event);
        self::assertSame(400, $event->getResponse()?->getStatusCode());
    }

    public function testUnavailableLimiterFailsClosed(): void
    {
        $factory = $this->createStub(\Symfony\Component\RateLimiter\RateLimiterFactoryInterface::class);
        $factory->method('create')->willThrowException(new \RuntimeException('sensitive connection details'));
        $event = $this->event('/api/auth/login', []);
        (new AuthRateLimitListener($factory, $factory, $factory, $factory))($event);
        self::assertSame(503, $event->getResponse()?->getStatusCode());
        self::assertStringNotContainsString('sensitive', $event->getResponse()->getContent());
    }

    public function testJustificationUploadsAreLimitedPerLink(): void
    {
        $listener = $this->listener();
        for ($i = 0; $i < 11; ++$i) {
            $event = $this->formEvent('/api/absences/justification', ['token' => 'same-link'], '203.0.113.' . ($i + 1));
            $listener($event);
            self::assertSame($i < 10 ? null : 429, $event->getResponse()?->getStatusCode());
        }
        self::assertNotNull($event->getResponse()?->headers->get('Retry-After'));
    }

    public function testJustificationUploadsAreLimitedPerAddress(): void
    {
        $listener = $this->listener();
        for ($i = 0; $i < 11; ++$i) {
            $event = $this->formEvent('/api/absences/justification', ['token' => 'link-' . $i]);
            $listener($event);
        }
        self::assertSame(429, $event->getResponse()?->getStatusCode());
    }

    public function testJustificationTokenGuessingIsLimitedPerAddress(): void
    {
        $listener = $this->listener();
        for ($i = 0; $i < 61; ++$i) {
            $event = new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('/api/absences/justification', 'GET', ['token' => 'guess-' . $i], [], [], ['REMOTE_ADDR' => '203.0.113.1']), HttpKernelInterface::MAIN_REQUEST);
            $listener($event);
            self::assertSame($i < 60 ? null : 429, $event->getResponse()?->getStatusCode());
        }
    }

    public function testUnavailableJustificationLimiterFailsClosedWithItsOwnMessage(): void
    {
        $factory = $this->createStub(\Symfony\Component\RateLimiter\RateLimiterFactoryInterface::class);
        $factory->method('create')->willThrowException(new \RuntimeException('down'));
        $event = $this->formEvent('/api/absences/justification', ['token' => 'link']);
        (new AuthRateLimitListener($factory, $factory, $factory, $factory))($event);
        self::assertSame(503, $event->getResponse()?->getStatusCode());
        self::assertStringContainsString('justificatif', $event->getResponse()->getContent());
    }

    private function listener(int $ipLimit = 30): AuthRateLimitListener
    {
        return new AuthRateLimitListener(
            new RateLimiterFactory(['id' => 'auth', 'policy' => 'sliding_window', 'limit' => $ipLimit, 'interval' => '15 minutes'], new InMemoryStorage()),
            new RateLimiterFactory(['id' => 'reset', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '15 minutes'], new InMemoryStorage()),
            new RateLimiterFactory(['id' => 'justification_requests', 'policy' => 'sliding_window', 'limit' => 60, 'interval' => '15 minutes'], new InMemoryStorage()),
            new RateLimiterFactory(['id' => 'justification_upload', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '15 minutes'], new InMemoryStorage()),
        );
    }

    private function formEvent(string $path, array $fields, string $ip = '203.0.113.1'): RequestEvent
    {
        $request = Request::create($path, 'POST', $fields, [], [], ['REMOTE_ADDR' => $ip]);
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function event(string $path, array $data, string $ip = '203.0.113.1'): RequestEvent
    {
        $request = Request::create($path, 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'], json_encode($data));
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
