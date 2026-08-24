<?php
declare(strict_types=1);

namespace App\Message;

// Message marqueur déposé par le Schedule Symfony (cron interne) sur le transport scheduler_default,
// consommé par DetectAbsencesMessageHandler pour lancer la détection quotidienne des absences.
class DetectAbsencesMessage
{
}
