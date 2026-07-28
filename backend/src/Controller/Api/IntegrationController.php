<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\UserPermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/integrations')]
class IntegrationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
        #[Autowire(param: 'app.riseup.base_url')]
        private readonly string $riseUpBaseUrl,
    ) {
    }

    #[Route('', name: 'api_integrations_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'integrations.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $datasets = [
            [
                'label' => 'Apprenants',
                'table' => 'learners',
                'command' => 'app:sync:learners',
            ],
            [
                'label' => 'Formations',
                'table' => 'trainings',
                'command' => 'app:sync:trainings',
            ],
            [
                'label' => 'Parcours',
                'table' => 'learning_paths',
                'secondaryTable' => 'learning_path_registrations',
                'command' => 'app:sync:learningpaths',
            ],
            [
                'label' => 'Inscriptions formation',
                'table' => 'training_registrations',
                'command' => 'app:sync:registrations',
            ],
            [
                'label' => 'Modules et steps',
                'table' => 'training_modules',
                'secondaryTable' => 'training_steps',
                'command' => 'app:sync:modules-steps',
            ],
            [
                'label' => 'Activité e-learning',
                'table' => 'learner_step_states',
                'command' => 'app:sync:userstepstates',
            ],
            [
                'label' => 'Sessions et signatures',
                'table' => 'classroom_sessions',
                'secondaryTable' => 'classroom_session_signatures',
                'command' => 'app:sync:sessions',
            ],
        ];

        $datasetStatuses = array_map(function (array $dataset) use ($connection): array {
            $primaryCount = (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $dataset['table']));
            $secondaryCount = isset($dataset['secondaryTable'])
                ? (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $dataset['secondaryTable']))
                : null;
            $lastSyncAt = $connection->fetchOne(sprintf('SELECT MAX(synced_at) FROM %s', $dataset['table']));

            return [
                'label' => $dataset['label'],
                'table' => $dataset['table'],
                'primaryCount' => $primaryCount,
                'secondaryCount' => $secondaryCount,
                'lastSyncAt' => $lastSyncAt,
                'command' => $dataset['command'],
                'status' => $primaryCount > 0 ? 'synced' : 'empty',
            ];
        }, $datasets);

        $lastSyncAt = null;
        foreach ($datasetStatuses as $status) {
            if (is_string($status['lastSyncAt']) && ($lastSyncAt === null || $status['lastSyncAt'] > $lastSyncAt)) {
                $lastSyncAt = $status['lastSyncAt'];
            }
        }

        return $this->json([
            'connection' => [
                'provider' => 'Rise Up',
                'mode' => 'read_only',
                'apiBaseUrl' => rtrim($this->riseUpBaseUrl, '/'),
                'health' => 'ok',
                'lastSyncAt' => $lastSyncAt,
            ],
            'metrics' => [
                'datasetsCount' => count($datasetStatuses),
                'syncedDatasetsCount' => count(array_filter($datasetStatuses, static fn (array $status): bool => $status['status'] === 'synced')),
                'totalRows' => array_sum(array_map(static fn (array $status): int => $status['primaryCount'] + ($status['secondaryCount'] ?? 0), $datasetStatuses)),
            ],
            'datasets' => $datasetStatuses,
            'commands' => array_column($datasetStatuses, 'command'),
        ]);
    }
}
