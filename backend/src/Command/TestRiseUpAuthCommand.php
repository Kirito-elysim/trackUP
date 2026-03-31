<?php

namespace App\Command;

use App\Integration\RiseUp\RiseUpAuthClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:riseup:test-auth', description: 'Test OAuth authentication against Rise Up API.')]
class TestRiseUpAuthCommand extends Command
{
    public function __construct(private readonly RiseUpAuthClient $riseUpAuthClient)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $payload = $this->riseUpAuthClient->fetchAccessToken();
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        $tokenPreview = substr($payload['access_token'], 0, 8) . '...';

        $io->success('Rise Up authentication succeeded.');
        $io->definitionList(
            ['Token type' => $payload['token_type']],
            ['Expires in' => (string) $payload['expires_in']],
            ['Access token preview' => $tokenPreview],
        );

        return Command::SUCCESS;
    }
}
