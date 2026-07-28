<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\ClassroomSession;
use App\Entity\ClassroomSessionRegistration;
use App\Entity\ClassroomSessionSignature;
use App\Entity\Learner;
use App\Entity\Training;
use App\Entity\TrainingModule;
use App\Entity\TrainingRegistration;
use App\Integration\RiseUp\RiseUpApiClient;
use Doctrine\ORM\EntityManagerInterface;

class ClassroomSessionSyncService
{
    use RiseUpCollectionSyncTrait;

    private const RATE_LIMIT_RETRY_DELAY_SECONDS = 61;
    private const RATE_LIMIT_MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly RiseUpApiClient $riseUpApiClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{
     *     sessions: array{fetched:int,created:int,updated:int},
     *     registrations: array{fetched:int,created:int,updated:int,skipped:int},
     *     signatures: array{registrations_processed:int,fetched:int,created:int,updated:int,removed:int}
     * }
     */
    public function sync(int $pageSize = 500, int $flushEvery = 200): array
    {
        $sessionResult = $this->syncSessions($pageSize, $flushEvery);
        $registrationResult = $this->syncRegistrations($pageSize, $flushEvery);
        $signatureResult = $this->syncSignatures($flushEvery);

        return [
            'sessions' => $sessionResult,
            'registrations' => $registrationResult,
            'signatures' => $signatureResult,
        ];
    }

    /**
     * @return array{fetched:int,created:int,updated:int}
     */
    private function syncSessions(int $pageSize, int $flushEvery): array
    {
        $rows = $this->riseUpApiClient->getCollection('/v3/classroomsessions', [], $pageSize);
        $modulesByExternalId = $this->reloadModules();
        $trainingsByExternalId = $this->reloadTrainings();

        $processRow = function (array $row) use (&$modulesByExternalId, &$trainingsByExternalId): string {
            $externalId = $this->requireInt($row, 'id');
            $session = $this->entityManager->getRepository(ClassroomSession::class)->findOneBy(['externalId' => $externalId]);
            $outcome = 'updated';

            if (!$session instanceof ClassroomSession) {
                $session = (new ClassroomSession())->setExternalId($externalId);
                $outcome = 'created';
            }

            $module = null;
            $moduleExternalId = $this->intOrNull($row['idmodule'] ?? null);
            if ($moduleExternalId !== null) {
                $module = $modulesByExternalId[$moduleExternalId] ?? null;
            }

            $training = null;
            $trainingExternalId = $this->intOrNull($row['idtraining'] ?? null);
            if ($trainingExternalId !== null) {
                $training = $trainingsByExternalId[$trainingExternalId] ?? null;
            }

            $this->hydrateSession($session, $module, $training, $row);
            $this->entityManager->persist($session);

            return $outcome;
        };

        $afterClear = function () use (&$modulesByExternalId, &$trainingsByExternalId): void {
            $modulesByExternalId = $this->reloadModules();
            $trainingsByExternalId = $this->reloadTrainings();
        };

        /** @var array{fetched:int,created?:int,updated?:int} $result */
        $result = $this->runCollectionSync($this->entityManager, $rows, $processRow, $flushEvery, $afterClear);

        return [
            'fetched' => $result['fetched'],
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
        ];
    }

    /**
     * @return array{fetched:int,created:int,updated:int,skipped:int}
     */
    private function syncRegistrations(int $pageSize, int $flushEvery): array
    {
        $rows = $this->riseUpApiClient->getCollection('/v3/classroomsessionregistrations', [], $pageSize);
        $learnersByExternalId = $this->reloadLearners();
        $sessionsByExternalId = $this->reloadSessions();
        $trainingRegistrationsByExternalId = $this->reloadTrainingRegistrations();

        $processRow = function (array $row) use (&$learnersByExternalId, &$sessionsByExternalId, &$trainingRegistrationsByExternalId): string {
            $learner = $learnersByExternalId[$this->requireInt($row, 'iduser')] ?? null;
            $session = $sessionsByExternalId[$this->requireInt($row, 'idsession')] ?? null;

            if (!$learner instanceof Learner || !$session instanceof ClassroomSession) {
                return 'skipped';
            }

            $externalId = $this->requireInt($row, 'id');
            $registration = $this->entityManager->getRepository(ClassroomSessionRegistration::class)->findOneBy(['externalId' => $externalId]);
            $outcome = 'updated';

            if (!$registration instanceof ClassroomSessionRegistration) {
                $registration = (new ClassroomSessionRegistration())->setExternalId($externalId);
                $outcome = 'created';
            }

            $trainingRegistration = null;
            $trainingRegistrationExternalId = $this->intOrNull($row['idtrainingsubscription'] ?? null);
            if ($trainingRegistrationExternalId !== null) {
                $trainingRegistration = $trainingRegistrationsByExternalId[$trainingRegistrationExternalId] ?? null;
            }

            $this->hydrateRegistration($registration, $learner, $session, $trainingRegistration, $row);
            $this->entityManager->persist($registration);

            return $outcome;
        };

        $afterClear = function () use (&$learnersByExternalId, &$sessionsByExternalId, &$trainingRegistrationsByExternalId): void {
            $learnersByExternalId = $this->reloadLearners();
            $sessionsByExternalId = $this->reloadSessions();
            $trainingRegistrationsByExternalId = $this->reloadTrainingRegistrations();
        };

        /** @var array{fetched:int,created?:int,updated?:int,skipped?:int} $result */
        $result = $this->runCollectionSync($this->entityManager, $rows, $processRow, $flushEvery, $afterClear);

        return [
            'fetched' => $result['fetched'],
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
        ];
    }

    /**
     * @return array{registrations_processed:int,fetched:int,created:int,updated:int,removed:int,registrations_skipped?:int}
     */
    private function syncSignatures(int $flushEvery): array
    {
        $registrations = $this->entityManager->getRepository(ClassroomSessionRegistration::class)->findAll();

        $fetched = 0;
        $created = 0;
        $updated = 0;
        $removed = 0;
        $processedRegistrations = 0;
        $skippedRegistrations = 0;

        foreach ($registrations as $registration) {
            $payload = $this->fetchSignaturesWithRetry($registration->getExternalId());

            if (!array_is_list($payload)) {
                // Some tenants respond with an error object (or a wrapped collection) for this endpoint.
                // Keep sync robust: skip signature sync for that registration and keep existing signatures unchanged.
                $wrapped = $payload['data'] ?? $payload['items'] ?? $payload['results'] ?? null;

                if (!is_array($wrapped) || !array_is_list($wrapped)) {
                    ++$skippedRegistrations;
                    ++$processedRegistrations;
                    continue;
                }

                $payload = $wrapped;
            }

            $existingByKey = [];
            foreach ($this->entityManager->getRepository(ClassroomSessionSignature::class)->findBy(['registration' => $registration]) as $signature) {
                $key = $signature->getAttendanceDate()->format('Y-m-d') . '|' . $signature->getPeriod();
                $existingByKey[$key] = $signature;
            }

            $seenKeys = [];

            foreach ($payload as $row) {
                if (!is_array($row)) {
                    continue;
                }

                ++$fetched;

                $attendanceDate = $this->dateOrNull($row['attendancedate'] ?? null);
                $period = $this->stringOrNull($row['period'] ?? null);

                if (!$attendanceDate instanceof \DateTimeImmutable || $period === null) {
                    continue;
                }

                $key = $attendanceDate->format('Y-m-d') . '|' . $period;
                $seenKeys[$key] = true;

                $signature = $existingByKey[$key] ?? null;

                if (!$signature instanceof ClassroomSessionSignature) {
                    $signature = new ClassroomSessionSignature();
                    ++$created;
                } else {
                    ++$updated;
                }

                $signature
                    ->setRegistration($registration)
                    ->setAttendanceDate($attendanceDate)
                    ->setPeriod($period)
                    ->setHasSigned($this->boolOrFalse($row['hassigned'] ?? null))
                    ->setSignatureDate($this->dateTimeOrNull($row['signaturedate'] ?? null))
                    ->setSyncedAt(new \DateTimeImmutable());

                $this->entityManager->persist($signature);
            }

            foreach ($existingByKey as $key => $signature) {
                if (isset($seenKeys[$key])) {
                    continue;
                }

                $this->entityManager->remove($signature);
                ++$removed;
            }

            ++$processedRegistrations;

            if ($processedRegistrations % $flushEvery === 0) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $result = [
            'registrations_processed' => count($registrations),
            'fetched' => $fetched,
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
        ];

        if ($skippedRegistrations > 0) {
            $result['registrations_skipped'] = $skippedRegistrations;
        }

        return $result;
    }

    /**
     * @return array<mixed>
     */
    private function fetchSignaturesWithRetry(int $registrationExternalId): array
    {
        for ($attempt = 1; $attempt <= self::RATE_LIMIT_MAX_ATTEMPTS; ++$attempt) {
            $payload = $this->riseUpApiClient->get(sprintf('/v3/classroomsessionregistrations/%d/signatures', $registrationExternalId));

            if (!$this->isRateLimitPayload($payload)) {
                return $payload;
            }

            if ($attempt === self::RATE_LIMIT_MAX_ATTEMPTS) {
                break;
            }

            sleep(self::RATE_LIMIT_RETRY_DELAY_SECONDS);
        }

        throw new \RuntimeException(sprintf('Rise Up rate limit persisted while fetching signatures for registration %d.', $registrationExternalId));
    }

    /**
     * @param array<mixed> $row
     */
    private function hydrateSession(ClassroomSession $session, ?TrainingModule $module, ?Training $training, array $row): void
    {
        $session
            ->setModule($module)
            ->setTraining($training)
            ->setStartAt($this->dateTimeOrNull($row['startdate'] ?? null))
            ->setEndAt($this->dateTimeOrNull($row['enddate'] ?? null))
            ->setState($this->stringOrNull($row['state'] ?? null))
            ->setSessionType($this->stringOrNull($row['session_type'] ?? null))
            ->setLocation($this->stringOrNull($row['location'] ?? null))
            ->setSeats($this->intOrNull($row['seats'] ?? null))
            ->setMeetingUrl($this->stringOrNull($row['meetingurl'] ?? null))
            ->setDescription($this->stringOrNull($row['description'] ?? null))
            ->setRoom($this->stringOrNull($row['room'] ?? null))
            ->setLanguage($this->stringOrNull($row['language'] ?? null))
            ->setEduDuration($this->intOrNull($row['eduduration'] ?? null))
            ->setReference($this->stringOrNull($row['reference'] ?? null))
            ->setSubscriptionCount($this->intOrNull($row['nbsubscriptions'] ?? null))
            ->setRiseUpCreatedAt($this->dateTimeOrNull($row['creationdate'] ?? null))
            ->setRiseUpUpdatedAt($this->dateTimeOrNull($row['modificationdate'] ?? null))
            ->setSyncedAt(new \DateTimeImmutable());
    }

    /**
     * @param array<mixed> $row
     */
    private function hydrateRegistration(
        ClassroomSessionRegistration $registration,
        Learner $learner,
        ClassroomSession $session,
        ?TrainingRegistration $trainingRegistration,
        array $row,
    ): void {
        $registration
            ->setLearner($learner)
            ->setSession($session)
            ->setTrainingRegistration($trainingRegistration)
            ->setSubscribedAt($this->dateTimeOrNull($row['subscribedate'] ?? null))
            ->setState($this->stringOrNull($row['state'] ?? null))
            ->setAttended($this->boolOrNull($row['attended'] ?? null))
            ->setReference($this->stringOrNull($row['reference'] ?? null))
            ->setEduDuration($this->intOrNull($row['eduduration'] ?? null))
            ->setRiseUpCreatedAt($this->dateTimeOrNull($row['creationdate'] ?? null))
            ->setRiseUpUpdatedAt($this->dateTimeOrNull($row['modificationdate'] ?? null))
            ->setSyncedAt(new \DateTimeImmutable());
    }

    /**
     * @param array<mixed> $row
     */
    private function requireInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (!is_int($value) && !is_string($value)) {
            throw new \RuntimeException(sprintf('Rise Up classroom payload is missing required field "%s".', $key));
        }

        return (int) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            return null;
        }

        return (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }

    private function boolOrFalse(mixed $value): bool
    {
        return $this->boolOrNull($value) ?? false;
    }

    /**
     * @param array<mixed> $payload
     */
    private function isRateLimitPayload(array $payload): bool
    {
        if (array_is_list($payload)) {
            return false;
        }

        return ($payload['error'] ?? null) === 'Too Many Requests';
    }

    private function dateTimeOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        return new \DateTimeImmutable($normalized);
    }

    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        return new \DateTimeImmutable($normalized);
    }

    /**
     * @return array<int, TrainingModule>
     */
    private function reloadModules(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(TrainingModule::class)->findAll() as $module) {
            $items[$module->getExternalId()] = $module;
        }

        return $items;
    }

    /**
     * @return array<int, Training>
     */
    private function reloadTrainings(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(Training::class)->findAll() as $training) {
            $items[$training->getExternalId()] = $training;
        }

        return $items;
    }

    /**
     * @return array<int, Learner>
     */
    private function reloadLearners(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(Learner::class)->findAll() as $learner) {
            $items[$learner->getExternalId()] = $learner;
        }

        return $items;
    }

    /**
     * @return array<int, ClassroomSession>
     */
    private function reloadSessions(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(ClassroomSession::class)->findAll() as $session) {
            $items[$session->getExternalId()] = $session;
        }

        return $items;
    }

    /**
     * @return array<int, TrainingRegistration>
     */
    private function reloadTrainingRegistrations(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(TrainingRegistration::class)->findAll() as $registration) {
            $items[$registration->getExternalId()] = $registration;
        }

        return $items;
    }
}
