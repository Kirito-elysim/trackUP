<?php
declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

// Throttles the public endpoints: authentication and the token-based absence justification page.
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final class AuthRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.auth_requests')] private readonly RateLimiterFactoryInterface $authRequests,
        #[Autowire(service: 'limiter.password_reset')] private readonly RateLimiterFactoryInterface $passwordReset,
        #[Autowire(service: 'limiter.justification_requests')] private readonly RateLimiterFactoryInterface $justificationRequests,
        #[Autowire(service: 'limiter.justification_upload')] private readonly RateLimiterFactoryInterface $justificationUpload,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if ($event->isMainRequest() && str_starts_with($path, '/api/absences/justification')) {
            $this->limitJustification($event);
            return;
        }
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

    private function limitJustification(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $ip = $request->getClientIp() ?? 'unknown';
        $unavailable = 'Le dépôt de justificatif est temporairement indisponible. Merci de réessayer.';
        // Every justification call checks a token: cap them per IP to stop token guessing.
        $response = $this->consume($this->justificationRequests, hash('sha256', $ip), $unavailable);
        if (!$response && $request->isMethod('POST')) {
            // Uploads carry up to 10 MB each: cap them per IP and per justification link.
            $response = $this->consume($this->justificationUpload, hash('sha256', 'ip:' . $ip), $unavailable)
                ?? $this->consume($this->justificationUpload, hash('sha256', 'token:' . (string) $request->request->get('token', '')), $unavailable);
        }
        if ($response) {
            $event->setResponse($response);
        }
    }

    private function consume(RateLimiterFactoryInterface $factory, string $key, string $unavailable = 'Authentification temporairement indisponible. Merci de réessayer.'): ?JsonResponse
    {
        try {
            $limit = $factory->create($key)->consume();
        } catch (\Throwable) {
            return new JsonResponse(['message' => $unavailable], 503, ['Retry-After' => '30']);
        }
        if ($limit->isAccepted()) {
            return null;
        }
        return new JsonResponse(['message' => 'Trop de tentatives. Merci de réessayer plus tard.'], 429, [
            'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
        ]);
    }
}
