<?php

namespace App\Service;

use App\Entity\Feature;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class UserPermissionResolver
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return string[]
     */
    public function resolveFeatureCodes(User $user): array
    {
        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $features = $this->entityManager->getRepository(Feature::class)->findBy([], ['category' => 'ASC', 'name' => 'ASC']);

            return array_values(array_map(static fn (Feature $feature): string => $feature->getCode(), $features));
        }

        $codes = [];

        foreach ($user->getRoleEntities() as $role) {
            foreach ($role->getFeatures() as $feature) {
                $codes[$feature->getCode()] = true;
            }
        }

        ksort($codes);

        return array_keys($codes);
    }

    public function userHasFeature(?User $user, string $featureCode): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        return \in_array($featureCode, $this->resolveFeatureCodes($user), true);
    }
}
