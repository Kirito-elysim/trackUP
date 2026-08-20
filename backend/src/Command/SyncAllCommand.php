<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\SyncRun;
use App\Service\SyncOrchestratorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:all',
    description: 'Run all 9 Rise Up syncs in dependency order and log the result (same as the cron and the "Tout synchroniser" button).',
)]
class SyncAllCommand extends Command
{
    public function __construct(private readonly SyncOrchestratorService $orchestrator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $run = $this->orchestrator->runAll(SyncRun::TRIGGER_MANUAL);

        $rows = array_map(
            static fn (array $step): array => [
                $step['label'],
                $step['command'],
                $step['status'] === 'success' ? '✅' : '❌',
                sprintf('%d ms', $step['durationMs']),
            ],
            $run->getSteps(),
        );

        $io->table(['Jeu de données', 'Commande', 'Statut', 'Durée'], $rows);

        if ($run->getStatus() === SyncRun::STATUS_SUCCESS) {
            $io->success('Synchronisation complète terminée avec succès.');

            return Command::SUCCESS;
        }

        if ($run->getStatus() === SyncRun::STATUS_PARTIAL) {
            $io->warning('Synchronisation complète terminée avec des erreurs partielles.');

            return Command::FAILURE;
        }

        $io->error('La synchronisation complète a échoué.');

        return Command::FAILURE;
    }
}
