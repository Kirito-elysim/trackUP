<?php

namespace App\Message;

class SyncRiseUpMessage
{
    public function __construct(
        public readonly string $scope = 'full',
    ) {
    }
}
