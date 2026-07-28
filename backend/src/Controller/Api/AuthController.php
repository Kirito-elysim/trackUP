<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\UserPermissionResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class AuthController extends AbstractController
{
    public function __construct(private readonly UserPermissionResolver $permissionResolver)
    {
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['message' => 'Unauthenticated.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $roles = array_values(array_map(
            static fn ($role) => [
                'id' => $role->getId(),
                'code' => $role->getCode(),
                'name' => $role->getName(),
            ],
            $user->getRoleEntities()->toArray()
        ));

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'fullName' => trim($user->getFirstName() . ' ' . $user->getLastName()),
            'isAdmin' => \in_array('ROLE_ADMIN', $user->getRoles(), true),
            'roles' => $roles,
            'features' => $this->permissionResolver->resolveFeatureCodes($user),
        ]);
    }
}
