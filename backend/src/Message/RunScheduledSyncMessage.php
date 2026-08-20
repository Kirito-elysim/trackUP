<?php
declare(strict_types=1);

namespace App\Message;

// Message marqueur déposé par le Schedule Symfony (cron interne 0 2 * * *) sur le transport
// scheduler_default, consommé par RunScheduledSyncMessageHandler pour lancer les 9 syncs.
class RunScheduledSyncMessage
{
}
