<?php

namespace App\Controller\Api\Admin;

use App\Entity\Feature;
use App\Entity\Role;
use App\Entity\User;
use App\Service\UserPermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/roles')]
class RoleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
    ) {
    }

    #[Route('', name: 'api_admin_roles_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        if (!$this->permissionResolver->userHasFeature($this->getUser(), 'settings.roles')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $roles = $this->entityManager->getRepository(Role::class)->findBy([], ['name' => 'ASC']);

        return $this->json(array_map(fn (Role $role): array => $this->normalizeRole($role), $roles));
    }

    #[Route('', name: 'api_admin_roles_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->permissionResolver->userHasFeature($this->getUser(), 'settings.roles')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = $request->toArray();

        $existing = $this->entityManager->getRepository(Role::class)->findOneBy(['code' => strtoupper((string) ($data['code'] ?? ''))]);
        if ($existing instanceof Role) {
            return $this->json(['message' => 'Role code already exists.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $role = (new Role())
            ->setCode((string) ($data['code'] ?? ''))
            ->setName((string) ($data['name'] ?? ''))
            ->setDescription($data['description'] ?? null)
            ->setSystem(false);

        $this->syncRoleFeatures($role, $data['featureCodes'] ?? []);

        $this->entityManager->persist($role);
        $this->entityManager->flush();

        return $this->json($this->normalizeRole($role), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_roles_update', methods: ['PUT'])]
    public function update(Request $request, Role $role): JsonResponse
    {
        if (!$this->permissionResolver->userHasFeature($this->getUser(), 'settings.roles')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = $request->toArray();

        if (!$role->isSystem() && isset($data['code'])) {
            $role->setCode((string) $data['code']);
        }

        $role
            ->setName((string) ($data['name'] ?? $role->getName()))
            ->setDescription($data['description'] ?? $role->getDescription());

        if (\array_key_exists('featureCodes', $data)) {
            $this->syncRoleFeatures($role, $data['featureCodes']);
        }

        $this->entityManager->flush();

        return $this->json($this->normalizeRole($role));
    }

    /**
     * @param array<int, string> $featureCodes
     */
    private function syncRoleFeatures(Role $role, array $featureCodes): void
    {
        foreach ($role->getFeatures()->toArray() as $feature) {
            $role->removeFeature($feature);
        }

        foreach ($featureCodes as $featureCode) {
            $feature = $this->entityManager->getRepository(Feature::class)->findOneBy(['code' => $featureCode]);
            if ($feature instanceof Feature) {
                $role->addFeature($feature);
            }
        }
    }

    private function normalizeRole(Role $role): array
    {
        return [
            'id' => $role->getId(),
            'code' => $role->getCode(),
            'name' => $role->getName(),
            'description' => $role->getDescription(),
            'system' => $role->isSystem(),
            'featureCodes' => array_values(array_map(
                static fn (Feature $feature): string => $feature->getCode(),
                $role->getFeatures()->toArray()
            )),
        ];
    }
}
