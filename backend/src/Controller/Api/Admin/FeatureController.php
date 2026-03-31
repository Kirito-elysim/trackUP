<?php

namespace App\Controller\Api\Admin;

use App\Entity\Feature;
use App\Entity\User;
use App\Service\UserPermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/features')]
class FeatureController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
    ) {
    }

    #[Route('', name: 'api_admin_features_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'settings.roles')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $features = $this->entityManager->getRepository(Feature::class)->findBy([], ['category' => 'ASC', 'name' => 'ASC']);

        return $this->json(array_map(static fn (Feature $feature): array => [
            'id' => $feature->getId(),
            'code' => $feature->getCode(),
            'name' => $feature->getName(),
            'category' => $feature->getCategory(),
            'description' => $feature->getDescription(),
        ], $features));
    }
}
