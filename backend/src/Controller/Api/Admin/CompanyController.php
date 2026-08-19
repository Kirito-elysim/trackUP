<?php
declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Entity\Company;
use App\Entity\Learner;
use App\Entity\Sector;
use App\Entity\Tutor;
use App\Entity\User;
use App\Service\CompanyTutorImportService;
use App\Service\UserPermissionResolver;
use App\Validation\ContactInfoValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/companies')]
class CompanyController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
        private readonly CompanyTutorImportService $importService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'api_admin_companies_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $page = max((int) $request->query->get('page', 1), 1);
        $pageSize = min(max((int) $request->query->get('pageSize', 20), 1), 100);

        $qb = $this->entityManager->getRepository(Company::class)->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC');

        $search = trim((string) $request->query->get('q', ''));
        if ($search !== '') {
            $qb->andWhere('c.name LIKE :search OR c.siret LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $filters = [
            'name' => 'c.name',
            'siret' => 'c.siret',
            'city' => 'c.city',
            'postalCode' => 'c.postalCode',
            'contactEmail' => 'c.contactEmail',
        ];
        foreach ($filters as $param => $field) {
            $value = trim((string) $request->query->get($param, ''));
            if ($value !== '') {
                $qb->andWhere(sprintf('%s LIKE :%s', $field, $param))->setParameter($param, '%' . $value . '%');
            }
        }

        $contactPhone = trim((string) $request->query->get('contactPhone', ''));
        if ($contactPhone !== '') {
            $qb->andWhere('c.contactPhoneMobile LIKE :contactPhone OR c.contactPhoneFixe LIKE :contactPhone')
                ->setParameter('contactPhone', '%' . $contactPhone . '%');
        }

        $sectorId = (int) $request->query->get('sectorId', 0);
        if ($sectorId > 0) {
            $qb->andWhere('c.sector = :sectorId')->setParameter('sectorId', $sectorId);
        }

        $totalRows = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        $companies = $qb->setFirstResult(($page - 1) * $pageSize)->setMaxResults($pageSize)->getQuery()->getResult();

        $sectors = $this->entityManager->getRepository(Sector::class)->createQueryBuilder('s')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->json([
            'companies' => array_map(fn (Company $company): array => $this->normalizeCompany($company), $companies),
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalRows' => $totalRows,
                'totalPages' => max(1, (int) ceil($totalRows / $pageSize)),
            ],
            'sectors' => array_map(
                static fn (Sector $sector): array => ['id' => $sector->getId(), 'name' => $sector->getName()],
                $sectors
            ),
        ]);
    }

    #[Route('', name: 'api_admin_companies_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = $request->toArray();
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            return $this->json(['message' => 'Le nom est requis.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $siret = $this->nullableString($data['siret'] ?? null);
        if ($siret !== null && $this->entityManager->getRepository(Company::class)->findOneBy(['siret' => $siret]) instanceof Company) {
            return $this->json(['message' => 'Ce SIRET est déjà utilisé.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validationError = $this->validateContactFields($data);
        if ($validationError !== null) {
            return $this->json(['message' => $validationError], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sector = $this->resolveSector($data['sectorId'] ?? null);

        $company = (new Company())
            ->setName($name)
            ->setSiret($siret)
            ->setAddress($this->nullableString($data['address'] ?? null))
            ->setPostalCode($this->nullableString($data['postalCode'] ?? null))
            ->setCity($this->nullableString($data['city'] ?? null))
            ->setSector($sector)
            ->setContactName($this->nullableString($data['contactName'] ?? null))
            ->setContactEmail($this->nullableString($data['contactEmail'] ?? null))
            ->setContactPhoneMobile($this->nullableString($data['contactPhoneMobile'] ?? null))
            ->setContactPhoneFixe($this->nullableString($data['contactPhoneFixe'] ?? null));

        $this->entityManager->persist($company);
        $this->entityManager->flush();

        return $this->json($this->normalizeCompany($company), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_companies_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Company $company): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        return $this->json([
            'company' => $this->normalizeCompany($company),
            'tutors' => array_map(
                fn (Tutor $tutor): array => $this->normalizeTutorSummary($tutor),
                $company->getTutors()->toArray()
            ),
            'learners' => array_map(
                fn (Learner $learner): array => $this->normalizeLearnerSummary($learner),
                $company->getLearners()->toArray()
            ),
        ]);
    }

    #[Route('/{id}', name: 'api_admin_companies_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(Request $request, Company $company): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = $request->toArray();

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->json(['message' => 'Le nom est requis.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $company->setName($name);
        }

        if (\array_key_exists('siret', $data)) {
            $siret = $this->nullableString($data['siret']);
            $existing = $siret !== null ? $this->entityManager->getRepository(Company::class)->findOneBy(['siret' => $siret]) : null;
            if ($existing instanceof Company && $existing->getId() !== $company->getId()) {
                return $this->json(['message' => 'Ce SIRET est déjà utilisé.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $company->setSiret($siret);
        }

        $validationError = $this->validateContactFields($data);
        if ($validationError !== null) {
            return $this->json(['message' => $validationError], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (\array_key_exists('address', $data)) {
            $company->setAddress($this->nullableString($data['address']));
        }

        if (\array_key_exists('postalCode', $data)) {
            $company->setPostalCode($this->nullableString($data['postalCode']));
        }

        if (\array_key_exists('city', $data)) {
            $company->setCity($this->nullableString($data['city']));
        }

        if (\array_key_exists('sectorId', $data)) {
            $company->setSector($this->resolveSector($data['sectorId']));
        }

        if (\array_key_exists('contactName', $data)) {
            $company->setContactName($this->nullableString($data['contactName']));
        }

        if (\array_key_exists('contactEmail', $data)) {
            $company->setContactEmail($this->nullableString($data['contactEmail']));
        }

        if (\array_key_exists('contactPhoneMobile', $data)) {
            $company->setContactPhoneMobile($this->nullableString($data['contactPhoneMobile']));
        }

        if (\array_key_exists('contactPhoneFixe', $data)) {
            $company->setContactPhoneFixe($this->nullableString($data['contactPhoneFixe']));
        }

        $this->entityManager->flush();

        return $this->json($this->normalizeCompany($company));
    }

    #[Route('/{id}', name: 'api_admin_companies_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Company $company): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $company->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['message' => 'Entreprise supprimée.']);
    }

    #[Route('/import', name: 'api_admin_companies_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if ($file === null) {
            return $this->json(['success' => false, 'message' => 'Aucun fichier fourni.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            return $this->json(
                ['success' => false, 'message' => 'Le fichier doit être au format XLSX ou CSV.'],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        try {
            $result = $this->importService->import($file->getPathname(), $extension);

            return $this->json([
                'success' => true,
                'message' => sprintf(
                    '%d ligne(s) analysée(s) : %d entreprise(s) créée(s) (%d déjà existante(s)), '
                    . '%d tuteur(s) créé(s) (%d déjà existant(s)), %d apprenant(s) rattaché(s), '
                    . '%d apprenant(s) introuvable(s), %d téléphone(s) apprenant ajouté(s) '
                    . '(%d fiche(s) créée(s), %d complétée(s)), %d ligne(s) ignorée(s).',
                    $result['rowsRead'],
                    $result['companiesCreated'],
                    $result['companiesMatched'],
                    $result['tutorsCreated'],
                    $result['tutorsMatched'],
                    $result['learnersLinked'],
                    $result['learnersNotFound'],
                    $result['prospectsCreated'] + $result['prospectsUpdated'],
                    $result['prospectsCreated'],
                    $result['prospectsUpdated'],
                    $result['rowsSkipped']
                ),
                'result' => $result,
                'fileName' => $file->getClientOriginalName(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Company/tutor import failed.', [
                'fileName' => $file->getClientOriginalName(),
                'exception' => $e,
            ]);

            return $this->json([
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'L\'import a échoué.',
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateContactFields(array $data): ?string
    {
        foreach (['contactPhoneMobile', 'contactPhoneFixe'] as $field) {
            $value = $this->nullableString($data[$field] ?? null);
            if ($value !== null && !ContactInfoValidator::isValidPhone($value)) {
                return 'Le numéro de téléphone n\'est pas valide.';
            }
        }

        $email = $this->nullableString($data['contactEmail'] ?? null);
        if ($email !== null && !ContactInfoValidator::isValidEmail($email)) {
            return 'L\'email n\'est pas valide.';
        }

        $postalCode = $this->nullableString($data['postalCode'] ?? null);
        if ($postalCode !== null && !ContactInfoValidator::isValidPostalCode($postalCode)) {
            return 'Le code postal doit contenir exactement 5 chiffres.';
        }

        return null;
    }

    private function resolveSector(mixed $sectorId): ?Sector
    {
        if ($sectorId === null || $sectorId === '') {
            return null;
        }

        return $this->entityManager->getRepository(Sector::class)->find((int) $sectorId);
    }

    private function normalizeCompany(Company $company): array
    {
        $sector = $company->getSector();

        return [
            'id' => $company->getId(),
            'name' => $company->getName(),
            'siret' => $company->getSiret(),
            'address' => $company->getAddress(),
            'postalCode' => $company->getPostalCode(),
            'city' => $company->getCity(),
            'sector' => $sector instanceof Sector ? ['id' => $sector->getId(), 'name' => $sector->getName()] : null,
            'contactName' => $company->getContactName(),
            'contactEmail' => $company->getContactEmail(),
            'contactPhoneMobile' => $company->getContactPhoneMobile(),
            'contactPhoneFixe' => $company->getContactPhoneFixe(),
            'createdAt' => $company->getCreatedAt()?->format(DATE_ATOM),
            'updatedAt' => $company->getUpdatedAt()?->format(DATE_ATOM),
            'deletedAt' => $company->getDeletedAt()?->format(DATE_ATOM),
            'tutorCount' => $company->getTutors()->count(),
            'learnerCount' => $company->getLearners()->count(),
        ];
    }

    private function normalizeTutorSummary(Tutor $tutor): array
    {
        return [
            'id' => $tutor->getId(),
            'fullName' => $tutor->getFullName(),
            'email' => $tutor->getEmail(),
            'phoneMobile' => $tutor->getPhoneMobile(),
            'phoneFixe' => $tutor->getPhoneFixe(),
            'learnerCount' => $tutor->getLearners()->count(),
        ];
    }

    private function normalizeLearnerSummary(Learner $learner): array
    {
        return [
            'id' => $learner->getId(),
            'fullName' => trim(sprintf('%s %s', (string) $learner->getFirstName(), (string) $learner->getLastName())),
            'email' => $learner->getEmail(),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
