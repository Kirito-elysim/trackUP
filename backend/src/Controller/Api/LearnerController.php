<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Company;
use App\Entity\Learner;
use App\Entity\Prospect;
use App\Entity\Tutor;
use App\Entity\User;
use App\Service\TimeMetricsService;
use App\Service\UserPermissionResolver;
use App\Util\DurationUnit;
use App\Validation\ContactInfoValidator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/learners')]
class LearnerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
        private readonly TimeMetricsService $timeMetricsService,
    ) {
    }

    #[Route('', name: 'api_learners_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'learners.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();
        $limit = min(max((int) $request->query->get('limit', 50), 1), 200);
        $search = trim((string) $request->query->get('q', ''));
        $state = trim((string) $request->query->get('state', ''));

        $conditions = [];
        $params = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];

        if ($search !== '') {
            $conditions[] = '(l.email LIKE :search OR l.first_name LIKE :search OR l.last_name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($state !== '') {
            $conditions[] = 'l.state = :state';
            $params['state'] = $state;
        }

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    l.id,
                    l.external_id AS externalId,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName,
                    l.state,
                    l.last_login_at AS lastLoginAt,
                    l.synced_at AS syncedAt,
                    COALESCE(trs.training_count, 0) AS trainingCount,
                    COALESCE(trs.total_time, 0) AS totalTime,
                    ROUND(COALESCE(trs.average_progress, 0), 2) AS averageProgress,
                    COALESCE(csrs.session_registration_count, 0) AS sessionRegistrationCount,
                    lss.last_activity_at AS lastActivityAt
                FROM learners l
                LEFT JOIN (
                    SELECT
                        learner_id,
                        COUNT(*) AS training_count,
                        COALESCE(SUM(total_time), 0) AS total_time,
                        COALESCE(AVG(progress), 0) AS average_progress
                    FROM training_registrations
                    GROUP BY learner_id
                ) trs ON trs.learner_id = l.id
                LEFT JOIN (
                    SELECT learner_id, COUNT(*) AS session_registration_count
                    FROM classroom_session_registrations
                    GROUP BY learner_id
                ) csrs ON csrs.learner_id = l.id
                LEFT JOIN (
                    SELECT learner_id, MAX(activity_at) AS last_activity_at
                    FROM learner_step_states
                    GROUP BY learner_id
                ) lss ON lss.learner_id = l.id
                {$whereSql}
                ORDER BY COALESCE(lss.last_activity_at, l.last_login_at) DESC, l.id DESC
                LIMIT :limit
            SQL,
            $params,
            $types
        );

        return $this->json(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'externalId' => (int) $row['externalId'],
            'email' => $row['email'],
            'firstName' => $row['firstName'],
            'lastName' => $row['lastName'],
            'fullName' => trim(sprintf('%s %s', (string) $row['firstName'], (string) $row['lastName'])),
            'state' => $row['state'],
            'lastLoginAt' => $row['lastLoginAt'],
            'lastActivityAt' => $row['lastActivityAt'],
            'syncedAt' => $row['syncedAt'],
            'trainingCount' => (int) $row['trainingCount'],
            'totalTime' => DurationUnit::secondsToMinutesInt($row['totalTime']),
            'averageProgress' => (float) $row['averageProgress'],
            'sessionRegistrationCount' => (int) $row['sessionRegistrationCount'],
        ], $rows));
    }

    #[Route('/{id}', name: 'api_learners_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'learners.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $learner = $connection->fetchAssociative(
            <<<SQL
                SELECT
                    l.id,
                    l.external_id AS externalId,
                    l.username,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName,
                    l.language,
                    l.phone_number AS phoneNumber,
                    l.timezone,
                    l.rise_up_role AS riseUpRole,
                    l.type,
                    l.state,
                    l.activated_at AS activatedAt,
                    l.suspended_at AS suspendedAt,
                    l.last_login_at AS lastLoginAt,
                    l.rise_up_created_at AS riseUpCreatedAt,
                    l.rise_up_updated_at AS riseUpUpdatedAt,
                    l.synced_at AS syncedAt,
                    COALESCE(trs.training_count, 0) AS trainingCount,
                    COALESCE(trs.total_time, 0) AS totalTime,
                    ROUND(COALESCE(trs.average_progress, 0), 2) AS averageProgress,
                    COALESCE(csrs.session_registration_count, 0) AS sessionRegistrationCount,
                    COALESCE(sig.signed_attendance_count, 0) AS signedAttendanceCount,
                    lss.last_activity_at AS lastActivityAt,
                    rg.id AS groupId,
                    rg.name AS groupName,
                    tu.id AS tutorId,
                    tu.first_name AS tutorFirstName,
                    tu.last_name AS tutorLastName,
                    tu.email AS tutorEmail,
                    tu.phone_mobile AS tutorPhoneMobile,
                    tu.phone_fixe AS tutorPhoneFixe,
                    co.id AS companyId,
                    co.name AS companyName,
                    pr.phone_mobile AS prospectPhoneMobile,
                    pr.phone_fixe AS prospectPhoneFixe,
                    pr.address AS prospectAddress,
                    pr.postal_code AS prospectPostalCode,
                    pr.city AS prospectCity,
                    pr.date_of_birth AS prospectDateOfBirth,
                    pr.comment AS prospectComment
                FROM learners l
                LEFT JOIN (
                    SELECT
                        learner_id,
                        COUNT(*) AS training_count,
                        COALESCE(SUM(total_time), 0) AS total_time,
                        COALESCE(AVG(progress), 0) AS average_progress
                    FROM training_registrations
                    GROUP BY learner_id
                ) trs ON trs.learner_id = l.id
                LEFT JOIN (
                    SELECT csr.learner_id, COUNT(*) AS session_registration_count
                    FROM classroom_session_registrations csr
                    INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                    WHERE cs.start_at <= NOW()
                    GROUP BY csr.learner_id
                ) csrs ON csrs.learner_id = l.id
                LEFT JOIN (
                    SELECT csr.learner_id, COUNT(*) AS signed_attendance_count
                    FROM classroom_session_signatures css
                    INNER JOIN classroom_session_registrations csr ON csr.id = css.registration_id
                    INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                    WHERE css.has_signed = 1 AND cs.start_at <= NOW()
                    GROUP BY csr.learner_id
                ) sig ON sig.learner_id = l.id
                LEFT JOIN (
                    SELECT learner_id, MAX(activity_at) AS last_activity_at
                    FROM learner_step_states
                    GROUP BY learner_id
                ) lss ON lss.learner_id = l.id
                LEFT JOIN riseup_learner_groups rlg ON rlg.learner_id = l.id
                LEFT JOIN riseup_groups rg ON rg.id = rlg.group_id
                LEFT JOIN tutors tu ON tu.id = l.tutor_id AND tu.deleted_at IS NULL
                LEFT JOIN companies co ON co.id = l.company_id AND co.deleted_at IS NULL
                LEFT JOIN prospects pr ON pr.email = l.email AND pr.deleted_at IS NULL
                WHERE l.id = :id
                ORDER BY rlg.synced_at DESC
                LIMIT 1
            SQL,
            ['id' => $id],
            ['id' => ParameterType::INTEGER]
        );

        if (!is_array($learner)) {
            return $this->json(['message' => 'Learner not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        // Calculer le temps du groupe en utilisant le service
        $groupId = $learner['groupId'] !== null ? (int) $learner['groupId'] : null;
        $groupTotalTime = 0;
        
        if ($groupId !== null) {
            $memberTimeMetrics = $this->timeMetricsService->getTimeMetricsByGroupMember($groupId);
            $groupTotalTime = $memberTimeMetrics[$id]['total_time_seconds'] ?? 0;
        }

        $trainingRegistrations = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    tr.id,
                    tr.external_id AS externalId,
                    t.id AS trainingId,
                    t.external_id AS trainingExternalId,
                    t.title AS trainingTitle,
                    t.state AS trainingState,
                    tr.state,
                    tr.total_time AS totalTime,
                    tr.progress,
                    tr.score,
                    tr.subscribed_at AS subscribedAt,
                    tr.training_end_at AS trainingEndAt
                FROM training_registrations tr
                INNER JOIN trainings t ON t.id = tr.training_id
                WHERE tr.learner_id = :learnerId
                ORDER BY tr.subscribed_at DESC, tr.id DESC
            SQL,
            ['learnerId' => $id],
            ['learnerId' => ParameterType::INTEGER]
        );

        $sessionRegistrations = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    csr.id,
                    csr.external_id AS externalId,
                    csr.state,
                    csr.attended,
                    csr.edu_duration AS eduDuration,
                    csr.subscribed_at AS subscribedAt,
                    cs.id AS sessionId,
                    cs.external_id AS sessionExternalId,
                    cs.session_type AS sessionType,
                    cs.start_at AS startAt,
                    cs.end_at AS endAt,
                    cs.meeting_url AS meetingUrl,
                    t.title AS trainingTitle,
                    (
                        SELECT COUNT(*)
                        FROM classroom_session_signatures css
                        WHERE css.registration_id = csr.id AND css.has_signed = 1
                    ) AS signedCount
                FROM classroom_session_registrations csr
                INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                LEFT JOIN trainings t ON t.id = cs.training_id
                WHERE csr.learner_id = :learnerId
                ORDER BY cs.start_at DESC, csr.id DESC
            SQL,
            ['learnerId' => $id],
            ['learnerId' => ParameterType::INTEGER]
        );

        $recentActivities = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lss.id,
                    lss.external_id AS externalId,
                    lss.state,
                    lss.time_spent AS timeSpent,
                    lss.total_time AS totalTime,
                    lss.score,
                    lss.activity_at AS activityAt,
                    ts.id AS stepId,
                    ts.external_id AS stepExternalId,
                    ts.title AS stepTitle,
                    ts.type AS stepType,
                    tm.title AS moduleTitle,
                    t.title AS trainingTitle
                FROM learner_step_states lss
                INNER JOIN training_steps ts ON ts.id = lss.step_id
                INNER JOIN training_modules tm ON tm.id = ts.module_id
                INNER JOIN trainings t ON t.id = tm.training_id
                WHERE lss.learner_id = :learnerId
                ORDER BY lss.activity_at DESC, lss.id DESC
                LIMIT 20
            SQL,
            ['learnerId' => $id],
            ['learnerId' => ParameterType::INTEGER]
        );

        return $this->json([
            'learner' => [
                'id' => (int) $learner['id'],
                'externalId' => (int) $learner['externalId'],
                'username' => $learner['username'],
                'email' => $learner['email'],
                'firstName' => $learner['firstName'],
                'lastName' => $learner['lastName'],
                'fullName' => trim(sprintf('%s %s', (string) $learner['firstName'], (string) $learner['lastName'])),
                'language' => $learner['language'],
                'phoneNumber' => $learner['phoneNumber'],
                'timezone' => $learner['timezone'],
                'riseUpRole' => $learner['riseUpRole'],
                'type' => $learner['type'],
                'state' => $learner['state'],
                'activatedAt' => $learner['activatedAt'],
                'suspendedAt' => $learner['suspendedAt'],
                'lastLoginAt' => $learner['lastLoginAt'],
                'lastActivityAt' => $learner['lastActivityAt'],
                'riseUpCreatedAt' => $learner['riseUpCreatedAt'],
                'riseUpUpdatedAt' => $learner['riseUpUpdatedAt'],
                'syncedAt' => $learner['syncedAt'],
                'trainingCount' => (int) $learner['trainingCount'],
                'totalTime' => DurationUnit::secondsToMinutesInt($learner['totalTime']),
                'averageProgress' => (float) $learner['averageProgress'],
                'sessionRegistrationCount' => (int) $learner['sessionRegistrationCount'],
                'signedAttendanceCount' => (int) $learner['signedAttendanceCount'],
                'groupId' => $groupId,
                'groupName' => $learner['groupName'],
                'groupTotalTime' => DurationUnit::secondsToMinutesInt($groupTotalTime),
                'tutor' => $learner['tutorId'] !== null ? [
                    'id' => (int) $learner['tutorId'],
                    'fullName' => trim(sprintf('%s %s', (string) $learner['tutorFirstName'], (string) $learner['tutorLastName'])),
                    'email' => $learner['tutorEmail'],
                    'phoneMobile' => $learner['tutorPhoneMobile'],
                    'phoneFixe' => $learner['tutorPhoneFixe'],
                ] : null,
                'company' => $learner['companyId'] !== null ? [
                    'id' => (int) $learner['companyId'],
                    'name' => $learner['companyName'],
                ] : null,
                'prospect' => (
                    $learner['prospectPhoneMobile'] !== null
                    || $learner['prospectPhoneFixe'] !== null
                    || $learner['prospectAddress'] !== null
                    || $learner['prospectPostalCode'] !== null
                    || $learner['prospectCity'] !== null
                    || $learner['prospectDateOfBirth'] !== null
                    || $learner['prospectComment'] !== null
                ) ? [
                    'phoneMobile' => $learner['prospectPhoneMobile'],
                    'phoneFixe' => $learner['prospectPhoneFixe'],
                    'address' => $learner['prospectAddress'],
                    'postalCode' => $learner['prospectPostalCode'],
                    'city' => $learner['prospectCity'],
                    'dateOfBirth' => $learner['prospectDateOfBirth'],
                    'comment' => $learner['prospectComment'],
                ] : null,
            ],
            'trainingRegistrations' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'externalId' => (int) $row['externalId'],
                'trainingId' => (int) $row['trainingId'],
                'trainingExternalId' => (int) $row['trainingExternalId'],
                'trainingTitle' => $row['trainingTitle'],
                'trainingState' => $row['trainingState'],
                'state' => $row['state'],
                'totalTime' => DurationUnit::secondsToMinutesInt($row['totalTime'] ?? 0),
                'progress' => $row['progress'] !== null ? (float) $row['progress'] : null,
                'score' => $row['score'] !== null ? (float) $row['score'] : null,
                'subscribedAt' => $row['subscribedAt'],
                'trainingEndAt' => $row['trainingEndAt'],
            ], $trainingRegistrations),
            'sessionRegistrations' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'externalId' => (int) $row['externalId'],
                'state' => $row['state'],
                'attended' => $row['attended'] !== null ? (bool) $row['attended'] : null,
                'eduDuration' => $row['eduDuration'] !== null ? (int) $row['eduDuration'] : null,
                'subscribedAt' => $row['subscribedAt'],
                'sessionId' => (int) $row['sessionId'],
                'sessionExternalId' => (int) $row['sessionExternalId'],
                'sessionType' => $row['sessionType'],
                'startAt' => $row['startAt'],
                'endAt' => $row['endAt'],
                'meetingUrl' => $row['meetingUrl'],
                'trainingTitle' => $row['trainingTitle'],
                'signedCount' => (int) $row['signedCount'],
            ], $sessionRegistrations),
            'recentActivities' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'externalId' => (int) $row['externalId'],
                'state' => $row['state'],
                'timeSpent' => DurationUnit::secondsToMinutesIntOrNull($row['timeSpent']),
                'totalTime' => DurationUnit::secondsToMinutesIntOrNull($row['totalTime']),
                'score' => $row['score'] !== null ? (float) $row['score'] : null,
                'activityAt' => $row['activityAt'],
                'stepId' => (int) $row['stepId'],
                'stepExternalId' => (int) $row['stepExternalId'],
                'stepTitle' => $row['stepTitle'],
                'stepType' => $row['stepType'],
                'moduleTitle' => $row['moduleTitle'],
                'trainingTitle' => $row['trainingTitle'],
            ], $recentActivities),
        ]);
    }

    #[Route('/{id}/assignment', name: 'api_learners_update_assignment', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateAssignment(Request $request, int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $learner = $this->entityManager->getRepository(Learner::class)->find($id);
        if (!$learner instanceof Learner) {
            return $this->json(['message' => 'Apprenant introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();

        $tutorId = isset($data['tutorId']) ? (int) $data['tutorId'] : null;
        $tutor = $tutorId !== null ? $this->entityManager->getRepository(Tutor::class)->find($tutorId) : null;
        if ($tutorId !== null && !$tutor instanceof Tutor) {
            return $this->json(['message' => 'Tuteur introuvable.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $companyProvided = \array_key_exists('companyId', $data);
        $companyId = $companyProvided && $data['companyId'] !== null ? (int) $data['companyId'] : null;
        $company = $companyId !== null ? $this->entityManager->getRepository(Company::class)->find($companyId) : null;
        if ($companyId !== null && !$company instanceof Company) {
            return $this->json(['message' => 'Entreprise introuvable.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Si aucune entreprise n'est explicitement fournie mais qu'un tuteur l'est, déduire
        // l'entreprise automatiquement seulement quand ce tuteur n'en a qu'une seule (sans
        // ambiguïté) — sinon la sélection manuelle reste obligatoire.
        if (!$companyProvided && $tutor instanceof Tutor && $tutor->getCompanies()->count() === 1) {
            $company = $tutor->getCompanies()->first() ?: null;
        }

        $learner->setTutor($tutor);
        $learner->setCompany($company);
        $this->entityManager->flush();

        return $this->json([
            'tutor' => $tutor instanceof Tutor
                ? [
                    'id' => $tutor->getId(),
                    'fullName' => $tutor->getFullName(),
                    'email' => $tutor->getEmail(),
                    'phoneMobile' => $tutor->getPhoneMobile(),
                    'phoneFixe' => $tutor->getPhoneFixe(),
                ]
                : null,
            'company' => $company instanceof Company ? ['id' => $company->getId(), 'name' => $company->getName()] : null,
        ]);
    }

    #[Route('/{id}/prospect', name: 'api_learners_update_prospect', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateProspect(Request $request, int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'companies.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $learner = $this->entityManager->getRepository(Learner::class)->find($id);
        if (!$learner instanceof Learner || $learner->getEmail() === null) {
            return $this->json(['message' => 'Apprenant introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();

        foreach (['phoneMobile', 'phoneFixe'] as $field) {
            $value = $this->nullableString($data[$field] ?? null);
            if ($value !== null && !ContactInfoValidator::isValidPhone($value)) {
                return $this->json(['message' => 'Le numéro de téléphone n\'est pas valide.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $postalCode = $this->nullableString($data['postalCode'] ?? null);
        if ($postalCode !== null && !ContactInfoValidator::isValidPostalCode($postalCode)) {
            return $this->json(['message' => 'Le code postal doit contenir exactement 5 chiffres.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $email = strtolower(trim($learner->getEmail()));

        $prospect = $this->entityManager->getRepository(Prospect::class)->findOneBy(['email' => $email]);
        if (!$prospect instanceof Prospect) {
            $prospect = (new Prospect())->setEmail($email);
            $this->entityManager->persist($prospect);
        }

        $prospect
            ->setPhoneMobile($this->nullableString($data['phoneMobile'] ?? null))
            ->setPhoneFixe($this->nullableString($data['phoneFixe'] ?? null))
            ->setAddress($this->nullableString($data['address'] ?? null))
            ->setPostalCode($this->nullableString($data['postalCode'] ?? null))
            ->setCity($this->nullableString($data['city'] ?? null))
            ->setDateOfBirth($this->nullableDate($data['dateOfBirth'] ?? null))
            ->setComment($this->nullableString($data['comment'] ?? null));

        $this->entityManager->flush();

        return $this->json([
            'prospect' => [
                'phoneMobile' => $prospect->getPhoneMobile(),
                'phoneFixe' => $prospect->getPhoneFixe(),
                'address' => $prospect->getAddress(),
                'postalCode' => $prospect->getPostalCode(),
                'city' => $prospect->getCity(),
                'dateOfBirth' => $prospect->getDateOfBirth()?->format('Y-m-d'),
                'comment' => $prospect->getComment(),
            ],
        ]);
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
