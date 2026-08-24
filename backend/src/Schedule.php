<?php

namespace App;

use App\Message\DetectAbsencesMessage;
use App\Message\ExpireAbsenceJustificationsMessage;
use App\Message\RunScheduledSyncMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            // Synchronisation Rise Up complète (9 datasets), tous les jours à 2h00.
            ->add(RecurringMessage::cron('0 2 * * *', new RunScheduledSyncMessage()))
            // Détection des absences (sessions passées sans émargement), tous les jours à 3h00,
            // après le cron de sync pour disposer des émargements Rise Up à jour.
            ->add(RecurringMessage::cron('0 3 * * *', new DetectAbsencesMessage()))
            // Expiration des délais de dépôt de justificatif (14 jours), tous les jours à 4h00,
            // après la détection pour ne pas expirer une absence qui vient d'être détectée.
            ->add(RecurringMessage::cron('0 4 * * *', new ExpireAbsenceJustificationsMessage()))
        ;
    }
}
