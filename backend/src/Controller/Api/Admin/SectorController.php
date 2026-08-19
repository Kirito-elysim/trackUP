<?php
declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Entity\Sector;
use App\Entity\User;
use App\Service\UserPermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/sectors')]
class SectorController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
    ) {
    }

    #[Route('', name: 'api_admin_sectors_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $search = trim((string) $request->query->get('q', ''));

        $qb = $this->entityManager->getRepository(Sector::class)->createQueryBuilder('s')
            ->orderBy('s.name', 'ASC');

        if ($search !== '') {
            $qb->andWhere('s.name LIKE :search')->setParameter('search', '%' . $search . '%');
        }

        $sectors = $qb->getQuery()->getResult();

        return $this->json(array_map(
            static fn (Sector $sector): array => ['id' => $sector->getId(), 'name' => $sector->getName()],
            $sectors
        ));
    }

    #[Route('', name: 'api_admin_sectors_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $name = trim((string) ($request->toArray()['name'] ?? ''));
        if ($name === '') {
            return $this->json(['message' => 'Le nom est requis.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = $this->entityManager->getRepository(Sector::class)->createQueryBuilder('s')
            ->where('LOWER(s.name) = :name')
            ->setParameter('name', mb_strtolower($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existing instanceof Sector) {
            return $this->json(['id' => $existing->getId(), 'name' => $existing->getName()], JsonResponse::HTTP_OK);
        }

        $sector = (new Sector())->setName($name);
        $this->entityManager->persist($sector);
        $this->entityManager->flush();

        return $this->json(['id' => $sector->getId(), 'name' => $sector->getName()], JsonResponse::HTTP_CREATED);
    }
}
