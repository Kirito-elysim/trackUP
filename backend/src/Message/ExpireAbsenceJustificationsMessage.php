<?php
declare(strict_types=1);

namespace App\Message;

// Message marqueur déposé par le Schedule Symfony (cron interne) sur le transport scheduler_default,
// consommé par ExpireAbsenceJustificationsMessageHandler pour expirer automatiquement les demandes
// de justificatif dont le délai (14 jours) est dépassé sans dépôt.
class ExpireAbsenceJustificationsMessage
{
}
