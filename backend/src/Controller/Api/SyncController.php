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
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Message\SyncRiseUpMessage;
use App\Message\SyncRiseUpGroupMembershipsMessage;

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
        private readonly LoggerInterface $logger,
    ) {
    }

    private function errorResponse(string $syncType, \Exception $e): JsonResponse
    {
        $this->logger->error('Rise Up sync failed.', [
            'syncType' => $syncType,
            'exception' => $e,
        ]);

        return $this->json([
            'success' => false,
            'message' => 'La synchronisation a échoué. Consultez les logs serveur pour plus de détails.',
        ], 500);
    }

    #[Route('/groups', name: 'groups', methods: ['POST'])]
    public function syncGroups(): JsonResponse
    {
        try {
            $result = $this->groupSyncService->sync();
            
            return $this->json([
                'success' => true,
                'message' => sprintf(
                    'Synchronisation terminée : %d groupe(s) importé(s) (%d créé(s), %d mis à jour, %d ignoré(s)), %d lien(s) parcours',
                    $result['groups'] ?? 0,
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0,
                    $result['skipped'] ?? 0,
                    $result['groupLearningPaths'] ?? 0,
                ),
                'stats' => $this->makeStats(
                    (int) ($result['fetched'] ?? ($result['groups'] ?? 0)),
                    (int) ($result['created'] ?? 0),
                    (int) ($result['updated'] ?? 0),
                    (int) ($result['skipped'] ?? 0),
                    [
                        'groupLearningPathsCreated' => (int) ($result['groupLearningPaths'] ?? 0),
                    ],
                ),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('groups', $e);
        }
    }

    #[Route('/riseup-group-memberships', name: 'riseup_group_memberships', methods: ['POST'])]
    public function syncRiseUpGroupMemberships(): JsonResponse
    {
        try {
            // Group membership sync makes one Rise Up API call per learner; run it
            // asynchronously to avoid HTTP proxy/browser timeouts, like sessions sync.
            $this->messageBus->dispatch(new SyncRiseUpGroupMembershipsMessage());

            return $this->json([
                'success' => true,
                'message' => 'Synchronisation des appartenances groupes lancée en arrière-plan. Cela peut prendre plusieurs minutes.',
            ], 202);
        } catch (\Exception $e) {
            return $this->errorResponse('riseup_group_memberships', $e);
        }
    }

    #[Route('/learning-paths', name: 'learning_paths', methods: ['POST'])]
    public function syncLearningPaths(): JsonResponse
    {
        try {
            $result = $this->learningPathSyncService->sync();
            
            $fetched = (int) ($result['learningPathsFetched'] ?? 0) + (int) ($result['registrationsFetched'] ?? 0);
            $created = (int) ($result['learningPathsCreated'] ?? 0) + (int) ($result['registrationsCreated'] ?? 0) + (int) ($result['trainingLinksCreated'] ?? 0);
            $updated = (int) ($result['learningPathsUpdated'] ?? 0) + (int) ($result['registrationsUpdated'] ?? 0);
            $skipped = (int) ($result['registrationsSkipped'] ?? 0) + (int) ($result['trainingLinksSkipped'] ?? 0);

            return $this->json([
                'success' => true,
                'message' => sprintf(
                    'Synchronisation terminée : %d éléments traités (%d créé(s), %d mis à jour, %d ignoré(s))',
                    $fetched,
                    $created,
                    $updated,
                    $skipped,
                ),
                'stats' => $this->makeStats($fetched, $created, $updated, $skipped, [
                    'learningPathsFetched' => (int) ($result['learningPathsFetched'] ?? 0),
                    'learningPathsCreated' => (int) ($result['learningPathsCreated'] ?? 0),
                    'learningPathsUpdated' => (int) ($result['learningPathsUpdated'] ?? 0),
                    'trainingLinksCreated' => (int) ($result['trainingLinksCreated'] ?? 0),
                    'trainingLinksSkipped' => (int) ($result['trainingLinksSkipped'] ?? 0),
                    'registrationsFetched' => (int) ($result['registrationsFetched'] ?? 0),
                    'registrationsCreated' => (int) ($result['registrationsCreated'] ?? 0),
                    'registrationsUpdated' => (int) ($result['registrationsUpdated'] ?? 0),
                    'registrationsSkipped' => (int) ($result['registrationsSkipped'] ?? 0),
                ]),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('learning_paths', $e);
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
                    'Synchronisation terminée : %d formation(s) (%d créée(s), %d mise(s) à jour)',
                    $result['fetched'] ?? 0,
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0
                ),
                'stats' => $this->makeStats(
                    (int) ($result['fetched'] ?? 0),
                    (int) ($result['created'] ?? 0),
                    (int) ($result['updated'] ?? 0),
                    0,
                ),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('trainings', $e);
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
                    'Synchronisation terminée : %d apprenant(s) (%d créé(s), %d mis à jour)',
                    $result['fetched'] ?? 0,
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0
                ),
                'stats' => $this->makeStats(
                    (int) ($result['fetched'] ?? 0),
                    (int) ($result['created'] ?? 0),
                    (int) ($result['updated'] ?? 0),
                    0,
                ),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('learners', $e);
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
                    'Synchronisation terminée : %d inscription(s) (%d créée(s), %d mise(s) à jour, %d ignorée(s))',
                    $result['fetched'] ?? 0,
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0,
                    $result['skipped'] ?? 0,
                ),
                'stats' => $this->makeStats(
                    (int) ($result['fetched'] ?? 0),
                    (int) ($result['created'] ?? 0),
                    (int) ($result['updated'] ?? 0),
                    (int) ($result['skipped'] ?? 0),
                ),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('registrations', $e);
        }
    }

    #[Route('/modules-steps', name: 'modules_steps', methods: ['POST'])]
    public function syncModulesAndSteps(): JsonResponse
    {
        try {
            $result = $this->trainingModuleStepSyncService->sync();

            $modules = is_array($result['modules'] ?? null) ? $result['modules'] : [];
            $steps = is_array($result['steps'] ?? null) ? $result['steps'] : [];

            $fetched = (int) ($modules['fetched'] ?? 0) + (int) ($steps['fetched'] ?? 0);
            $created = (int) ($modules['created'] ?? 0) + (int) ($steps['created'] ?? 0);
            $updated = (int) ($modules['updated'] ?? 0) + (int) ($steps['updated'] ?? 0);
            $skipped = (int) ($modules['skipped'] ?? 0) + (int) ($steps['skipped'] ?? 0);

            return $this->json([
                'success' => true,
                'message' => sprintf(
                    'Synchronisation terminée : %d éléments (%d créé(s), %d mis à jour, %d ignoré(s))',
                    $fetched,
                    $created,
                    $updated,
                    $skipped,
                ),
                'stats' => $this->makeStats($fetched, $created, $updated, $skipped, [
                    'modules' => $modules,
                    'steps' => $steps,
                ]),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('modules_steps', $e);
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
                    'Synchronisation terminée : %d état(s) (%d créé(s), %d mis à jour, %d ignoré(s))',
                    $result['fetched'] ?? 0,
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0,
                    $result['skipped'] ?? 0,
                ),
                'stats' => $this->makeStats(
                    (int) ($result['fetched'] ?? 0),
                    (int) ($result['created'] ?? 0),
                    (int) ($result['updated'] ?? 0),
                    (int) ($result['skipped'] ?? 0),
                ),
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('userstepstates', $e);
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
            return $this->errorResponse('sessions', $e);
        }
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function makeStats(int $fetched, int $created, int $updated, int $skipped, array $extra = []): array
    {
        return [
            'fetched' => $fetched,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            ...$extra,
        ];
    }
}
