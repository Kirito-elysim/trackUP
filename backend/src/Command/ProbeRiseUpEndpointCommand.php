<?php
declare(strict_types=1);

namespace App\Command;

use App\Integration\RiseUp\RiseUpApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:riseup:probe',
    description: 'Call a Rise Up endpoint and print a compact summary of the response.',
)]
class ProbeRiseUpEndpointCommand extends Command
{
    public function __construct(private readonly RiseUpApiClient $riseUpApiClient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Endpoint path to query.', '/v3/users')
            ->addOption(
                'query',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Query parameters formatted as key=value.',
            )
            ->addOption(
                'show-first',
                null,
                InputOption::VALUE_NONE,
                'Display the first item payload when the response is a list or contains a list.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('path');

        try {
            $query = $this->parseQueryOptions($input->getOption('query'));
            $payload = $this->riseUpApiClient->get($path, $query);
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Rise Up endpoint %s responded successfully.', $path));

        foreach ($this->summarizePayload($payload) as $label => $value) {
            $io->definitionList([$label => $value]);
        }

        if ((bool) $input->getOption('show-first')) {
            $firstItem = $this->extractFirstItem($payload);

            if ($firstItem === null) {
                $io->note('No first item could be extracted from this payload.');
            } else {
                $io->section('First item');
                $io->writeln($this->formatJson($firstItem));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param mixed $queryOptions
     *
     * @return array<string, string>
     */
    private function parseQueryOptions(mixed $queryOptions): array
    {
        if (!is_array($queryOptions)) {
            return [];
        }

        $query = [];

        foreach ($queryOptions as $option) {
            if (!is_string($option) || !str_contains($option, '=')) {
                throw new \InvalidArgumentException(sprintf('Invalid --query value "%s". Expected key=value.', (string) $option));
            }

            [$key, $value] = explode('=', $option, 2);
            $query[trim($key)] = trim($value);
        }

        return $query;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<string, string>
     */
    private function summarizePayload(array $payload): array
    {
        $summary = [
            'Payload type' => $this->isList($payload) ? 'list' : 'object',
            'Top-level keys' => $this->isList($payload) ? 'n/a' : implode(', ', array_keys($payload)),
        ];

        if ($this->isList($payload)) {
            $summary['Items in payload'] = (string) count($payload);
            $summary['First item keys'] = $this->extractFirstItemKeys($payload);

            return $summary;
        }

        foreach (['data', 'items', 'results'] as $collectionKey) {
            if (isset($payload[$collectionKey]) && is_array($payload[$collectionKey])) {
                $summary['Collection key'] = $collectionKey;
                $summary['Items in collection'] = (string) count($payload[$collectionKey]);
                $summary['First item keys'] = $this->extractFirstItemKeys($payload[$collectionKey]);

                break;
            }
        }

        if (!isset($summary['Collection key'])) {
            $summary['Preview'] = $this->truncateJson($payload);
        }

        return $summary;
    }

    /**
     * @param array<mixed> $items
     */
    private function extractFirstItemKeys(array $items): string
    {
        $firstItem = $items[array_key_first($items)] ?? null;

        if (!is_array($firstItem)) {
            return 'n/a';
        }

        return implode(', ', array_keys($firstItem));
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<mixed>|null
     */
    private function extractFirstItem(array $payload): ?array
    {
        if ($this->isList($payload)) {
            $firstItem = $payload[array_key_first($payload)] ?? null;

            return is_array($firstItem) ? $firstItem : null;
        }

        foreach (['data', 'items', 'results'] as $collectionKey) {
            if (!isset($payload[$collectionKey]) || !is_array($payload[$collectionKey])) {
                continue;
            }

            $firstItem = $payload[$collectionKey][array_key_first($payload[$collectionKey])] ?? null;

            return is_array($firstItem) ? $firstItem : null;
        }

        return null;
    }

    /**
     * @param array<mixed> $payload
     */
    private function truncateJson(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            return 'n/a';
        }

        if (strlen($encoded) <= 240) {
            return $encoded;
        }

        return substr($encoded, 0, 237) . '...';
    }

    /**
     * @param array<mixed> $payload
     */
    private function formatJson(array $payload): string
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }

    /**
     * @param array<mixed> $payload
     */
    private function isList(array $payload): bool
    {
        return array_is_list($payload);
    }
}
