<?php
declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Entity\Company;
use App\Entity\Learner;
use App\Entity\Tutor;
use App\Entity\User;
use App\Service\UserPermissionResolver;
use App\Validation\ContactInfoValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/tutors')]
class TutorController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
    ) {
    }

    #[Route('', name: 'api_admin_tutors_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $page = max((int) $request->query->get('page', 1), 1);
        $pageSize = min(max((int) $request->query->get('pageSize', 20), 1), 100);

        $qb = $this->entityManager->getRepository(Tutor::class)->createQueryBuilder('t')
            ->orderBy('t.lastName', 'ASC')
            ->addOrderBy('t.firstName', 'ASC');

        $search = trim((string) $request->query->get('q', ''));
        if ($search !== '') {
            $qb->andWhere('t.firstName LIKE :search OR t.lastName LIKE :search OR t.email LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $filters = [
            'email' => 't.email',
            'city' => 't.city',
        ];
        foreach ($filters as $param => $field) {
            $value = trim((string) $request->query->get($param, ''));
            if ($value !== '') {
                $qb->andWhere(sprintf('%s LIKE :%s', $field, $param))->setParameter($param, '%' . $value . '%');
            }
        }

        $phone = trim((string) $request->query->get('phone', ''));
        if ($phone !== '') {
            $qb->andWhere('t.phoneMobile LIKE :phone OR t.phoneFixe LIKE :phone')->setParameter('phone', '%' . $phone . '%');
        }

        $companyId = (int) $request->query->get('companyId', 0);
        if ($companyId > 0) {
            $qb->innerJoin('t.companies', 'filterCompany')
                ->andWhere('filterCompany.id = :companyId')
                ->setParameter('companyId', $companyId);
        }

        $totalRows = (int) (clone $qb)->select('COUNT(DISTINCT t.id)')->getQuery()->getSingleScalarResult();

        $tutors = $qb->setFirstResult(($page - 1) * $pageSize)->setMaxResults($pageSize)->getQuery()->getResult();

        return $this->json([
            'tutors' => array_map(fn (Tutor $tutor): array => $this->normalizeTutor($tutor), $tutors),
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalRows' => $totalRows,
                'totalPages' => max(1, (int) ceil($totalRows / $pageSize)),
            ],
        ]);
    }

    #[Route('', name: 'api_admin_tutors_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = $request->toArray();
        $firstName = trim((string) ($data['firstName'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            return $this->json(['message' => 'Le prénom et le nom sont requis.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $email = $this->nullableString($data['email'] ?? null);
        if ($email === null) {
            return $this->json(['message' => 'L\'email est requis.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validationError = $this->validateContactFields($data, $email);
        if ($validationError !== null) {
            return $this->json(['message' => $validationError], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tutor = (new Tutor())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setEmail($email)
            ->setPhoneMobile($this->nullableString($data['phoneMobile'] ?? null))
            ->setPhoneFixe($this->nullableString($data['phoneFixe'] ?? null))
            ->setAddress($this->nullableString($data['address'] ?? null))
            ->setPostalCode($this->nullableString($data['postalCode'] ?? null))
            ->setCity($this->nullableString($data['city'] ?? null))
            ->setDateOfBirth($this->nullableDate($data['dateOfBirth'] ?? null));

        $this->syncCompanies($tutor, $data['companyIds'] ?? []);

        $this->entityManager->persist($tutor);
        $this->entityManager->flush();

        return $this->json($this->normalizeTutor($tutor), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_tutors_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Tutor $tutor): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        return $this->json([
            'tutor' => $this->normalizeTutor($tutor),
            'learners' => array_map(
                fn (Learner $learner): array => $this->normalizeLearnerSummary($learner),
                $tutor->getLearners()->toArray()
            ),
        ]);
    }

    #[Route('/{id}', name: 'api_admin_tutors_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(Request $request, Tutor $tutor): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = $request->toArray();

        if (isset($data['firstName'])) {
            $firstName = trim((string) $data['firstName']);
            if ($firstName === '') {
                return $this->json(['message' => 'Le prénom est requis.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $tutor->setFirstName($firstName);
        }

        if (isset($data['lastName'])) {
            $lastName = trim((string) $data['lastName']);
            if ($lastName === '') {
                return $this->json(['message' => 'Le nom est requis.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $tutor->setLastName($lastName);
        }

        if (\array_key_exists('email', $data)) {
            $email = $this->nullableString($data['email']);
            if ($email === null) {
                return $this->json(['message' => 'L\'email est requis.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $existing = $this->entityManager->getRepository(Tutor::class)->findOneBy(['email' => strtolower($email)]);
            if ($existing instanceof Tutor && $existing->getId() !== $tutor->getId()) {
                return $this->json(['message' => 'Cet email est déjà utilisé par un autre tuteur.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $validationError = $this->validateContactFields($data, $data['email'] ?? null);
        if ($validationError !== null) {
            return $this->json(['message' => $validationError], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (\array_key_exists('email', $data)) {
            $tutor->setEmail($this->nullableString($data['email']));
        }

        if (\array_key_exists('phoneMobile', $data)) {
            $tutor->setPhoneMobile($this->nullableString($data['phoneMobile']));
        }

        if (\array_key_exists('phoneFixe', $data)) {
            $tutor->setPhoneFixe($this->nullableString($data['phoneFixe']));
        }

        if (\array_key_exists('address', $data)) {
            $tutor->setAddress($this->nullableString($data['address']));
        }

        if (\array_key_exists('postalCode', $data)) {
            $tutor->setPostalCode($this->nullableString($data['postalCode']));
        }

        if (\array_key_exists('city', $data)) {
            $tutor->setCity($this->nullableString($data['city']));
        }

        if (\array_key_exists('dateOfBirth', $data)) {
            $tutor->setDateOfBirth($this->nullableDate($data['dateOfBirth']));
        }

        if (\array_key_exists('companyIds', $data)) {
            $this->syncCompanies($tutor, $data['companyIds']);
        }

        $this->entityManager->flush();

        return $this->json($this->normalizeTutor($tutor));
    }

    #[Route('/{id}', name: 'api_admin_tutors_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Tutor $tutor): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $tutor->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['message' => 'Tuteur supprimé.']);
    }

    /**
     * @param array<int, int> $companyIds
     */
    private function syncCompanies(Tutor $tutor, array $companyIds): void
    {
        foreach ($tutor->getCompanies()->toArray() as $company) {
            $tutor->removeCompany($company);
        }

        foreach ($companyIds as $companyId) {
            $company = $this->entityManager->getRepository(Company::class)->find($companyId);
            if ($company instanceof Company) {
                $tutor->addCompany($company);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateContactFields(array $data, mixed $email = null): ?string
    {
        foreach (['phoneMobile', 'phoneFixe'] as $field) {
            $value = $this->nullableString($data[$field] ?? null);
            if ($value !== null && !ContactInfoValidator::isValidPhone($value)) {
                return 'Le numéro de téléphone n\'est pas valide.';
            }
        }

        $emailString = $this->nullableString($email);
        if ($emailString !== null && !ContactInfoValidator::isValidEmail($emailString)) {
            return 'L\'email n\'est pas valide.';
        }

        $postalCode = $this->nullableString($data['postalCode'] ?? null);
        if ($postalCode !== null && !ContactInfoValidator::isValidPostalCode($postalCode)) {
            return 'Le code postal doit contenir exactement 5 chiffres.';
        }

        return null;
    }

    private function normalizeTutor(Tutor $tutor): array
    {
        return [
            'id' => $tutor->getId(),
            'firstName' => $tutor->getFirstName(),
            'lastName' => $tutor->getLastName(),
            'fullName' => $tutor->getFullName(),
            'email' => $tutor->getEmail(),
            'phoneMobile' => $tutor->getPhoneMobile(),
            'phoneFixe' => $tutor->getPhoneFixe(),
            'address' => $tutor->getAddress(),
            'postalCode' => $tutor->getPostalCode(),
            'city' => $tutor->getCity(),
            'dateOfBirth' => $tutor->getDateOfBirth()?->format('Y-m-d'),
            'createdAt' => $tutor->getCreatedAt()?->format(DATE_ATOM),
            'updatedAt' => $tutor->getUpdatedAt()?->format(DATE_ATOM),
            'deletedAt' => $tutor->getDeletedAt()?->format(DATE_ATOM),
            'companies' => array_values(array_map(
                static fn (Company $company): array => ['id' => $company->getId(), 'name' => $company->getName()],
                $tutor->getCompanies()->toArray()
            )),
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

    private function nullableDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));

        return $date instanceof \DateTimeImmutable ? $date->setTime(0, 0) : null;
    }
}
