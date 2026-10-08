<?php

namespace Agavesoft\Smartmailto;

use Agavesoft\Smartmailto\Exceptions\SmartmailtoException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Cliente HTTP sincrono. Lanza SmartmailtoException clasificada (transitoria o rechazada); nunca se
 * traga errores (el SDK v1 lo hacia y por eso nunca reintentaba).
 */
class SmartmailtoClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?string $apiUrl,
        private readonly ?string $apiToken,
        private readonly int $timeout = 10,
    ) {}

    public function isConfigured(): bool
    {
        return trim((string) $this->apiUrl) !== '' && trim((string) $this->apiToken) !== '';
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, array $headers = []): array
    {
        return $this->request('post', $path, $body, $headers);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body): array
    {
        return $this->request('put', $path, $body);
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->request('get', $path);
    }

    /** @return array<string, mixed> */
    public function delete(string $path): array
    {
        return $this->request('delete', $path);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body = [], array $headers = []): array
    {
        if (! $this->isConfigured()) {
            throw SmartmailtoException::rejected('Smartmailto is not configured (SMARTMAILTO_API_URL / SMARTMAILTO_API_TOKEN).', 0);
        }

        $url = rtrim((string) $this->apiUrl, '/').'/api/'.ltrim($path, '/');

        try {
            $response = $this->http
                ->withToken((string) $this->apiToken)
                ->withHeaders(['User-Agent' => 'agavesoft-smartmailto-php/2', ...$headers])
                ->acceptJson()
                ->timeout($this->timeout)
                ->{$method}($url, in_array($method, ['post', 'put'], true) ? $body : null);
        } catch (ConnectionException $e) {
            throw SmartmailtoException::transient('Could not reach Smartmailto: '.$e->getMessage(), previous: $e);
        }

        return $this->handle($response, $path);
    }

    /** @return array<string, mixed> */
    private function handle(Response $response, string $path): array
    {
        $status = $response->status();

        if ($response->successful()) {
            return (array) $response->json();
        }

        if ($status === 429) {
            $retryAfter = (int) $response->header('Retry-After');

            throw SmartmailtoException::transient("Smartmailto rate limit on {$path}.", 429, $retryAfter > 0 ? $retryAfter : null);
        }

        if ($status >= 500) {
            throw SmartmailtoException::transient("Smartmailto error {$status} on {$path}.", $status);
        }

        $body = (array) $response->json();
        $error = is_string($body['error'] ?? null) ? " {$body['error']}" : '';

        throw SmartmailtoException::rejected("Smartmailto rejected {$path} ({$status}{$error}).", $status, $body);
    }
}
