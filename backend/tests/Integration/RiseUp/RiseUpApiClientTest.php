<?php

declare(strict_types=1);

namespace App\Tests\Integration\RiseUp;

use App\Integration\RiseUp\RiseUpApiClient;
use App\Integration\RiseUp\RiseUpAuthClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RiseUpApiClientTest extends TestCase
{
    public function testRetriesOn429ThenSucceeds(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('{"error":"rate_limited"}', ['http_code' => 429]),
            new MockResponse('{"error":"rate_limited"}', ['http_code' => 429]),
            new MockResponse('{"id":1}', ['http_code' => 200]),
        ]);

        $client = $this->makeClient($mockClient);

        $payload = $client->get('/v3/whatever');

        $this->assertSame(['id' => 1], $payload);
        $this->assertSame(3, $mockClient->getRequestsCount());
    }

    public function testThrowsClearExceptionOn429WhenRetriesExhausted(): void
    {
        $mockClient = new MockHttpClient(array_fill(0, 10, new MockResponse('{}', ['http_code' => 429])));
        $client = $this->makeClient($mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/rate limit/i');

        $client->get('/v3/whatever');
    }

    public function testThrowsClearExceptionOn401(): void
    {
        $mockClient = new MockHttpClient([new MockResponse('{}', ['http_code' => 401])]);
        $client = $this->makeClient($mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/authentication failed/i');

        $client->get('/v3/whatever');
    }

    public function testThrowsClearExceptionOn5xx(): void
    {
        $mockClient = new MockHttpClient([new MockResponse('{}', ['http_code' => 503])]);
        $client = $this->makeClient($mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/server error/i');

        $client->get('/v3/whatever');
    }

    public function testGetCollectionStopsAtThePageCapInsteadOfLoopingForever(): void
    {
        // Never return an empty page, forcing the loop to keep going until it
        // either hits the cap (expected) or runs away indefinitely (the bug).
        $responses = [];
        for ($i = 0; $i < 550; ++$i) {
            $responses[] = new MockResponse('[{"id":' . $i . '}]', ['http_code' => 200]);
        }

        $mockClient = new MockHttpClient($responses);
        $client = $this->makeClient($mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeded the maximum of 500 pages/');

        $client->getCollection('/v3/whatever', [], 1);
    }

    private function makeClient(HttpClientInterface $httpClient): RiseUpApiClient
    {
        $authClient = $this->createStub(RiseUpAuthClient::class);
        $authClient->method('fetchAccessToken')->willReturn(['access_token' => 'test-token', 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => null]);

        return new RiseUpApiClient($this->wrapWithConfiguredRetry($httpClient), $authClient, new NullLogger(), 'https://riseup.example.test');
    }

    private function wrapWithConfiguredRetry(HttpClientInterface $httpClient): HttpClientInterface
    {
        // Mirrors config/packages/http_client.yaml's retry_failed settings, with a
        // near-zero delay so the test doesn't actually wait through the backoff.
        $strategy = new GenericRetryStrategy([429], 1, 1, 0, 0.0);

        return new RetryableHttpClient($httpClient, $strategy, 3);
    }
}
