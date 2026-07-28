<?php
declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Entity\Role;
use App\Entity\User;
use App\Service\UserPermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/users')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', name: 'api_admin_users_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        if (!$this->permissionResolver->userHasFeature($this->getUser(), 'settings.users')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $users = $this->entityManager->getRepository(User::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->json(array_map(fn (User $user): array => $this->normalizeUser($user), $users));
    }

    #[Route('', name: 'api_admin_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->permissionResolver->userHasFeature($this->getUser(), 'settings.users')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = $request->toArray();

        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => strtolower((string) ($data['email'] ?? ''))]);
        if ($existing instanceof User) {
            return $this->json(['message' => 'Email already exists.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $plainPassword = trim((string) ($data['password'] ?? ''));
        if ($plainPassword === '') {
            return $this->json(['message' => 'Password is required.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = (new User())
            ->setEmail((string) ($data['email'] ?? ''))
            ->setFirstName((string) ($data['firstName'] ?? ''))
            ->setLastName((string) ($data['lastName'] ?? ''))
            ->setActive((bool) ($data['active'] ?? true));

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->syncUserRoles($user, $data['roleIds'] ?? []);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $this->json($this->normalizeUser($user), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_users_update', methods: ['PUT'])]
    public function update(Request $request, User $user): JsonResponse
    {
        if (!$this->permissionResolver->userHasFeature($this->getUser(), 'settings.users')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = $request->toArray();

        $user
            ->setFirstName((string) ($data['firstName'] ?? $user->getFirstName()))
            ->setLastName((string) ($data['lastName'] ?? $user->getLastName()))
            ->setActive((bool) ($data['active'] ?? $user->isActive()));

        if (!empty($data['password'])) {
            $user->setPassword($this->passwordHasher->hashPassword($user, (string) $data['password']));
        }

        if (\array_key_exists('roleIds', $data)) {
            $this->syncUserRoles($user, $data['roleIds']);
        }

        $this->entityManager->flush();

        return $this->json($this->normalizeUser($user));
    }

    /**
     * @param array<int, int> $roleIds
     */
    private function syncUserRoles(User $user, array $roleIds): void
    {
        foreach ($user->getRoleEntities()->toArray() as $role) {
            $user->removeRoleEntity($role);
        }

        foreach ($roleIds as $roleId) {
            $role = $this->entityManager->getRepository(Role::class)->find($roleId);
            if ($role instanceof Role) {
                $user->addRoleEntity($role);
            }
        }
    }

    private function normalizeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'active' => $user->isActive(),
            'roles' => array_values(array_map(static fn (Role $role): array => [
                'id' => $role->getId(),
                'code' => $role->getCode(),
                'name' => $role->getName(),
            ], $user->getRoleEntities()->toArray())),
        ];
    }
}
