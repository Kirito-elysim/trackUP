<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\LearnerSyncService;
use App\Service\LearnerStepStateSyncService;
use App\Service\LearningPathSyncService;
use App\Service\RiseUpGroupSyncService;
use App\Service\TrainingModuleStepSyncService;
use App\Service\TrainingRegistrationSyncService;
use App\Service\TrainingSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Message\SyncRiseUpMessage;

#[Route('/api/sync', name: 'api_sync_')]
#[IsGranted('ROLE_ADMIN')]
class SyncController extends AbstractController
{
    public function __construct(
        private readonly RiseUpGroupSyncService $groupSyncService,
        private readonly LearningPathSyncService $learningPathSyncService,
        private readonly TrainingSyncService $trainingSyncService,
        private readonly LearnerSyncService $learnerSyncService,
        private readonly TrainingRegistrationSyncService $trainingRegistrationSyncService,
        private readonly TrainingModuleStepSyncService $trainingModuleStepSyncService,
        private readonly LearnerStepStateSyncService $learnerStepStateSyncService,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/groups', name: 'groups', methods: ['POST'])]
    public function syncGroups(): JsonResponse
    {
        try {
            $result = $this->groupSyncService->sync();
            
            return $this->json([
                'success' => true,
                'message' => sprintf('Synchronisation terminée : %d groupe(s) traité(s)', $result['groupCount'] ?? 0),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des groupes : ' . $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/learning-paths', name: 'learning_paths', methods: ['POST'])]
    public function syncLearningPaths(): JsonResponse
    {
        try {
            $result = $this->learningPathSyncService->sync();
            
            return $this->json([
                'success' => true,
                'message' => sprintf(
                    'Synchronisation terminée : %d parcours créé(s), %d mis à jour, %d inscriptions synchronisées',
                    $result['learningPathsCreated'] ?? 0,
                    $result['learningPathsUpdated'] ?? 0,
                    $result['registrationsCreated'] ?? 0
                ),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des parcours : ' . $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/trainings', name: 'trainings', methods: ['POST'])]
    public function syncTrainings(): JsonResponse
    {
        try {
            $result = $this->trainingSyncService->sync();
            
            return $this->json([
                'success' => true,
                'message' => sprintf(
                    'Synchronisation terminée : %d formation(s) créée(s), %d mise(s) à jour',
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0
                ),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des formations : ' . $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/learners', name: 'learners', methods: ['POST'])]
    public function syncLearners(): JsonResponse
    {
        try {
            $result = $this->learnerSyncService->sync();
            
            return $this->json([
                'success' => true,
                'message' => sprintf(
                    'Synchronisation terminée : %d apprenant(s) créé(s), %d mis à jour',
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0
                ),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des apprenants : ' . $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/registrations', name: 'registrations', methods: ['POST'])]
    public function syncRegistrations(): JsonResponse
    {
        try {
            $result = $this->trainingRegistrationSyncService->sync();

            return $this->json([
                'success' => true,
                'message' => sprintf(
                    'Synchronisation terminée : %d inscription(s) créée(s), %d mise(s) à jour, %d ignorée(s)',
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0,
                    $result['skipped'] ?? 0,
                ),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des inscriptions : ' . $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/modules-steps', name: 'modules_steps', methods: ['POST'])]
    public function syncModulesAndSteps(): JsonResponse
    {
        try {
            $result = $this->trainingModuleStepSyncService->sync();

            return $this->json([
                'success' => true,
                'message' => 'Synchronisation terminée : modules et étapes synchronisés.',
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des modules/étapes : ' . $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/userstepstates', name: 'userstepstates', methods: ['POST'])]
    public function syncLearnerStepStates(): JsonResponse
    {
        try {
            $result = $this->learnerStepStateSyncService->sync();

            return $this->json([
                'success' => true,
                'message' => sprintf(
                    'Synchronisation terminée : %d état(s) créé(s), %d mis à jour, %d ignoré(s)',
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0,
                    $result['skipped'] ?? 0,
                ),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des états de progression : ' . $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/sessions', name: 'sessions', methods: ['POST'])]
    public function syncSessions(): JsonResponse
    {
        try {
            // Session sync can be very slow (signature fetch per registration, rate-limit retries, etc.).
            // Run it asynchronously to avoid HTTP proxy/browser timeouts.
            $this->messageBus->dispatch(new SyncRiseUpMessage('sessions'));

            return $this->json([
                'success' => true,
                'message' => 'Synchronisation des sessions lancée en arrière-plan. Cela peut prendre plusieurs minutes.',
            ], 202);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des sessions : ' . $e->getMessage(),
            ], 500);
        }
    }
}
