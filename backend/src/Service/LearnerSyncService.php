<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Learner;
use App\Integration\RiseUp\RiseUpApiClient;
use Doctrine\ORM\EntityManagerInterface;

class LearnerSyncService
{
    use RiseUpCollectionSyncTrait;

    public function __construct(
        private readonly RiseUpApiClient $riseUpApiClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{fetched:int,created:int,updated:int}
     */
    public function sync(int $pageSize = 500, int $flushEvery = 200): array
    {
        $rows = $this->riseUpApiClient->getCollection('/v3/users', [], $pageSize);

        $processRow = function (array $row): string {
            $externalId = $this->requireInt($row, 'id');
            $learner = $this->entityManager->getRepository(Learner::class)->findOneBy(['externalId' => $externalId]);
            $outcome = 'updated';

            if (!$learner instanceof Learner) {
                $learner = (new Learner())->setExternalId($externalId);
                $outcome = 'created';
            }

            $this->hydrateLearner($learner, $row);
            $this->entityManager->persist($learner);

            return $outcome;
        };

        /** @var array{fetched:int,created?:int,updated?:int} $result */
        $result = $this->runCollectionSync($this->entityManager, $rows, $processRow, $flushEvery);

        return [
            'fetched' => $result['fetched'],
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
        ];
    }

    /**
     * @param array<mixed> $row
     */
    private function hydrateLearner(Learner $learner, array $row): void
    {
        $learner
            ->setUsername($this->stringOrNull($row['username'] ?? null))
            ->setEmail($this->stringOrNull($row['email'] ?? null))
            ->setFirstName($this->stringOrNull($row['firstname'] ?? null))
            ->setLastName($this->stringOrNull($row['lastname'] ?? null))
            ->setLanguage($this->stringOrNull($row['language'] ?? null))
            ->setPhoneNumber($this->stringOrNull($row['phonenumber'] ?? null))
            ->setTimezone($this->stringOrNull($row['timezone'] ?? null))
            ->setRiseUpRole($this->stringOrNull($row['role'] ?? null))
            ->setType($this->stringOrNull($row['type'] ?? null))
            ->setState($this->stringOrNull($row['state'] ?? null))
            ->setActivatedAt($this->dateTimeOrNull($row['activateddate'] ?? null))
            ->setSuspendedAt($this->dateTimeOrNull($row['suspenddate'] ?? null))
            ->setLastLoginAt($this->dateTimeOrNull($row['lastlogindate'] ?? null))
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
            throw new \RuntimeException(sprintf('Rise Up learner payload is missing required field "%s".', $key));
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
}
