<?php
declare(strict_types=1);

namespace App\Message;

class SyncRiseUpMessage
{
    public function __construct(
        public readonly string $scope = 'full',
    ) {
    }
}
