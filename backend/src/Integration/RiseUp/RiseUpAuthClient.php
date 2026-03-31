<?php

namespace App\Integration\RiseUp;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RiseUpAuthClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $publicKey,
        private readonly string $privateKey,
    ) {
    }

    /**
     * @return array{access_token:string,expires_in:int,token_type:string,scope:mixed}
     */
    public function fetchAccessToken(): array
    {
        $this->assertCredentialsAreConfigured();

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/v3/oauth/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->publicKey . ':' . $this->privateKey),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);

            /** @var array{access_token:string,expires_in:int,token_type:string,scope:mixed} $payload */
            $payload = $response->toArray();

            return $payload;
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Rise Up OAuth authentication failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function assertCredentialsAreConfigured(): void
    {
        if ($this->publicKey === '' || $this->privateKey === '') {
            throw new \RuntimeException('Rise Up API credentials are missing. Configure RISEUP_API_PUBLIC_KEY and RISEUP_API_PRIVATE_KEY.');
        }
    }
}
