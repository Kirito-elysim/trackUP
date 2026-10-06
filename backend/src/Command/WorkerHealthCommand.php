<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\DependencyHealth;
use App\Service\WorkerHeartbeat;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:health:worker', description: 'Check worker progress and database/Redis availability.')]
final class WorkerHealthCommand extends Command
{
    public function __construct(private readonly WorkerHeartbeat $heartbeat, private readonly DependencyHealth $dependencies)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max-idle', null, InputOption::VALUE_REQUIRED, 'Maximum idle heartbeat age in seconds', '90')
            ->addOption('max-stall', null, InputOption::VALUE_REQUIRED, 'Maximum age of progress during a job in seconds', '900');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->heartbeat->isHealthy((int) $input->getOption('max-idle'), (int) $input->getOption('max-stall'))) {
            $output->writeln('Worker heartbeat missing, stopped or stale.');
            return Command::FAILURE;
        }
        if (in_array('down', $this->dependencies->check(), true)) {
            $output->writeln('Worker dependencies unavailable.');
            return Command::FAILURE;
        }
        $output->writeln('Worker healthy.');
        return Command::SUCCESS;
    }
}
