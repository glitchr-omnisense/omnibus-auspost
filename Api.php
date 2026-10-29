<?php

namespace Omnibus\Auspost;

use Omnibus\Exception\CarrierException;
use Omnibus\Http\Redaction;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Australia Post's Shipping and Tracking API (basic auth with the API key and password, the account number in a header). */
final class Api
{
    public const LIVE = 'https://digitalapi.auspost.com.au';
    public const TEST = 'https://digitalapi.auspost.com.au/test';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly string $password,
        public readonly string $accountNumber,
        public readonly bool $sandbox = false,
        private readonly int $timeout = 20,
    ) {
    }

    public function base(): string
    {
        return $this->sandbox ? self::TEST : self::LIVE;
    }

    /** @return array<string, mixed> JSON, or ['content' => binary] for a document */
    public function call(string $method, string $path, ?array $body = null, array $query = []): array
    {
        try {
            $response = $this->http->request($method, str_starts_with($path, 'http') ? $path : $this->base().$path, [
                'auth_basic' => [$this->apiKey, $this->password],
                'headers' => ['Account-Number' => $this->accountNumber, 'Content-Type' => 'application/json', 'Accept' => 'application/json, application/pdf'],
                'query' => $query,
                'body' => null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
            $type = $response->getHeaders(false)['content-type'][0] ?? '';
        } catch (HttpExceptionInterface|\JsonException $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('auspost', 'Australia Post request failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if ($status < 400 && (str_contains($type, 'pdf') || str_contains($type, 'octet-stream'))) {
            return ['content' => $content];
        }
        $data = '' === $content ? [] : json_decode($content, true);
        if (!\is_array($data)) {
            throw Redaction::withoutArguments(new CarrierException('auspost', sprintf('Australia Post answered HTTP %d with a body that is not JSON.', $status)));
        }
        if ($status >= 400 || isset($data['errors'])) {
            $error = $data['errors'][0] ?? [];
            throw Redaction::withoutArguments(new CarrierException('auspost', (string) ($error['message'] ?? $error['error_message'] ?? sprintf('HTTP %d', $status)), isset($error['code']) ? (string) $error['code'] : (isset($error['error_code']) ? (string) $error['error_code'] : null)));
        }

        return $data;
    }

    /**
     * What a failure must not carry: the credentials, and what a request made of them.
     *
     * @return list<string>
     */
    private function secrets(string ...$more): array
    {
        return [$this->apiKey, $this->password, ...$more];
    }
}
