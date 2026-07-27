<?php

namespace App\Integration\RiseUp;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RiseUpApiClient
{
    private const MAX_PAGE_SIZE = 500;

    // Generous headroom (500 pages * 500 items = 250k) — real collections top out
    // around a few hundred items today. This exists to fail loudly instead of
    // looping forever if the API ever stops returning an empty terminating page.
    private const MAX_PAGES = 500;

    private ?string $accessToken = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RiseUpAuthClient $authClient,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, [
            'query' => $query,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<int, array<mixed>>
     */
    public function getCollection(string $path, array $query = [], int $pageSize = self::MAX_PAGE_SIZE): array
    {
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException(sprintf('Rise Up page size must be between 1 and %d.', self::MAX_PAGE_SIZE));
        }

        $items = [];
        $offset = 0;
        $pageCount = 0;

        while (true) {
            if (++$pageCount > self::MAX_PAGES) {
                $this->logger->alert('Rise Up collection pagination exceeded the maximum page limit; aborting instead of looping forever.', [
                    'path' => $path,
                    'maxPages' => self::MAX_PAGES,
                    'itemsFetchedSoFar' => count($items),
                ]);

                throw new \RuntimeException(sprintf(
                    'Rise Up collection endpoint %s exceeded the maximum of %d pages.',
                    $path,
                    self::MAX_PAGES,
                ));
            }

            $pageQuery = [
                ...$query,
                'limit' => $pageSize,
                'range' => sprintf('%d-%d', $offset, $offset + $pageSize - 1),
            ];

            $payload = $this->get($path, $pageQuery);

            if (!array_is_list($payload)) {
                throw new \RuntimeException(sprintf('Rise Up collection endpoint %s did not return a list payload.', $path));
            }

            if ($payload === []) {
                break;
            }

            /** @var array<int, array<mixed>> $payload */
            $items = [...$items, ...$payload];

            // Some Rise Up endpoints cap results below the requested limit (e.g. 499 items even when asking 500).
            // Do not stop early on "payload smaller than pageSize"; instead continue until an empty page is returned.
            $offset += count($payload);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    public function request(string $method, string $path, array $options = []): array
    {
        try {
            $headers = $options['headers'] ?? [];
            $headers['Authorization'] = 'Bearer ' . $this->getAccessToken();
            $headers['Accept'] = 'application/json';

            $response = $this->httpClient->request($method, $this->buildUrl($path), [
                ...$options,
                'headers' => $headers,
            ]);

            // toArray(false) never throws on its own regardless of HTTP status, so any
            // 401/429/5xx would otherwise decode silently as "just another JSON body"
            // (e.g. a 404 error payload without a 'groups' key looking like empty data).
            // Retrying 429 is handled by framework.http_client's retry_failed config; if
            // it still comes back 429 after retries, or on 401/5xx, fail loudly here
            // instead of returning a response callers would mistake for a normal payload.
            $statusCode = $response->getStatusCode();

            if ($statusCode === 401) {
                throw new \RuntimeException('Rise Up API authentication failed (401). Check the configured API credentials.');
            }

            if ($statusCode === 429) {
                throw new \RuntimeException('Rise Up API rate limit exceeded (429), retries exhausted.');
            }

            if ($statusCode >= 500) {
                throw new \RuntimeException(sprintf('Rise Up API returned a server error (%d).', $statusCode));
            }

            $payload = $response->toArray(false);

            if (!is_array($payload)) {
                throw new \RuntimeException('Rise Up API response is not a JSON object or array.');
            }

            return $payload;
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Rise Up API request failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $payload = $this->authClient->fetchAccessToken();
        $this->accessToken = $payload['access_token'];

        return $this->accessToken;
    }

    private function buildUrl(string $path): string
    {
        $normalizedPath = '/' . ltrim($path, '/');

        return rtrim($this->baseUrl, '/') . $normalizedPath;
    }
}
