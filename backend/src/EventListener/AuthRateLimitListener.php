<?php
declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final class AuthRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.auth_requests')] private readonly RateLimiterFactoryInterface $authRequests,
        #[Autowire(service: 'limiter.password_reset')] private readonly RateLimiterFactoryInterface $passwordReset,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || !in_array($path, ['/api/auth/login', '/api/auth/forgot-password', '/api/auth/reset-password'], true)) {
            return;
        }

        $ip = $request->getClientIp() ?? 'unknown';
        if ($response = $this->consume($this->authRequests, hash('sha256', $ip))) {
            $event->setResponse($response);
            return;
        }

        if ($path === '/api/auth/login') {
            return;
        }
        $data = $request->toArray();
        if ($path === '/api/auth/forgot-password' && isset($data['email']) && !is_string($data['email'])) {
            $event->setResponse(new JsonResponse(['message' => 'Requête invalide.'], 400));
            return;
        }
        $identity = $path === '/api/auth/forgot-password' ? strtolower(trim((string) ($data['email'] ?? ''))) : $ip;
        if ($response = $this->consume($this->passwordReset, hash('sha256', $path . ':' . $identity))) {
            $event->setResponse($response);
        }
    }

    private function consume(RateLimiterFactoryInterface $factory, string $key): ?JsonResponse
    {
        try {
            $limit = $factory->create($key)->consume();
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Authentification temporairement indisponible. Merci de réessayer.'], 503, ['Retry-After' => '30']);
        }
        if ($limit->isAccepted()) {
            return null;
        }
        return new JsonResponse(['message' => 'Trop de tentatives. Merci de réessayer plus tard.'], 429, [
            'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
        ]);
    }
}
