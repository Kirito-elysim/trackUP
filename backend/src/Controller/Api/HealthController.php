<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\DependencyHealth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class HealthController extends AbstractController
{
    public function __construct(private readonly DependencyHealth $dependencies)
    {
    }

    #[Route('/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $checks = $this->dependencies->check();
        $healthy = !in_array('down', $checks, true);
        return $this->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => 'trackup-backend',
            'checks' => $checks,
        ], $healthy ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
