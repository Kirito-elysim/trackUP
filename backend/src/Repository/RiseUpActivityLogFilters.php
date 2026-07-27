<?php

declare(strict_types=1);

namespace App\Repository;

final class RiseUpActivityLogFilters
{
    public function __construct(
        public readonly ?string $learnerQuery = null,
        public readonly ?int $groupExternalId = null,
        public readonly ?int $learningPathId = null,
        public readonly ?int $trainingExternalId = null,
        public readonly ?\DateTimeImmutable $dateFrom = null,
        public readonly ?\DateTimeImmutable $dateTo = null,
    ) {
    }
}
