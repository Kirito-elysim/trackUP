<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\SyncRun;

// Forme JSON partagée entre SyncController::latestRun() (polling du bouton "Tout synchroniser")
// et IntegrationController (carte "Dernière exécution groupée") pour ne pas dupliquer le mapping.
class SyncRunNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(SyncRun $run): array
    {
        $triggeredBy = $run->getTriggeredBy();

        return [
            'id' => $run->getId(),
            'triggerType' => $run->getTriggerType(),
            'status' => $run->getStatus(),
            'startedAt' => $run->getStartedAt()->format(DATE_ATOM),
            'finishedAt' => $run->getFinishedAt()?->format(DATE_ATOM),
            'steps' => $run->getSteps(),
            'currentStepIndex' => $run->getCurrentStepIndex(),
            'currentStepLabel' => $run->getCurrentStepLabel(),
            'triggeredByName' => $triggeredBy !== null
                ? trim($triggeredBy->getFirstName() . ' ' . $triggeredBy->getLastName())
                : null,
        ];
    }
}
