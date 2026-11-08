<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Internal;

use E258Tech\NexoraSms\Exception\ConnectionException;
use E258Tech\NexoraSms\Exception\NexoraSmsException;
use E258Tech\NexoraSms\Http\HttpRequest;
use E258Tech\NexoraSms\Http\HttpResponse;
use E258Tech\NexoraSms\Http\Transport;
use E258Tech\NexoraSms\Http\TransportException;

/**
 * Builds requests, sends them through the transport and retries when allowed.
 *
 * @internal
 */
final class ApiCore
{
    private const MAX_RETRY_AFTER_MS = 30_000;
    private const MAX_BACKOFF_MS = 8_000;

    /** @var \Closure(): int */
    private readonly \Closure $now;

    /**
     * @param (\Closure(): int)|null $now Unix time in seconds (for Retry-After dates).
     */
    public function __construct(
        private readonly string $publicKey,
        private readonly string $apiKey,
        public readonly string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly string $userAgent,
        private readonly Transport $transport,
        private readonly Sleeper $sleeper,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * @param array<string, string|int|null> $query
     * @param array<string, mixed>|null      $body
     * @param array<string, string>          $headers
     *
     * @throws NexoraSmsException
     */
    public function request(
        string $method,
        string $path,
        bool $retryable,
        array $query = [],
        ?array $body = null,
        array $headers = [],
    ): ApiResponse {
        $url = $this->baseUrl . $path;
        $query = array_filter($query, static fn (string|int|null $value): bool => $value !== null);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $allHeaders = [
            'X-Public-Key' => $this->publicKey,
            'X-Api-Key' => $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        $payload = null;
        if ($body !== null) {
            $allHeaders['Content-Type'] = 'application/json';
            $payload = Json::encode($body);
        }
        $request = new HttpRequest($method, $url, $allHeaders + $headers, $payload);

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($request, $this->timeout);
            } catch (TransportException $e) {
                if ($retryable && $attempt < $this->maxRetries) {
                    $this->sleeper->sleep(self::backoff($attempt));
                    continue;
                }
                $host = (string) parse_url($this->baseUrl, PHP_URL_HOST);
                $message = $e->timedOut
                    ? sprintf('Tempo limite excedido ao contactar %s.', $host)
                    : sprintf('Não foi possível contactar %s.', $host);

                throw new ConnectionException($message, previous: $e);
            }

            if ($response->status >= 200 && $response->status < 300) {
                return new ApiResponse($response, Json::decodeObject($response->body));
            }

            $retryAfter = $this->parseRetryAfter($response);
            $transient = in_array($response->status, [429, 502, 503, 504], true)
                || ($response->status === 409 && $retryAfter !== null);
            if ($retryable && $transient && $attempt < $this->maxRetries) {
                $this->sleeper->sleep($retryAfter !== null
                    ? min($retryAfter * 1000, self::MAX_RETRY_AFTER_MS)
                    : self::backoff($attempt));
                continue;
            }

            throw NexoraSmsException::fromResponse($response->status, Json::decodeObject($response->body), $retryAfter);
        }
    }

    /**
     * 0.5 s × 2^attempt with ±20 % jitter, at most 8 s (in milliseconds).
     */
    private static function backoff(int $attempt): int
    {
        $base = min(500 * (2 ** min($attempt, 10)), self::MAX_BACKOFF_MS);

        return (int) round($base * (0.8 + mt_rand() / mt_getrandmax() * 0.4));
    }

    private function parseRetryAfter(HttpResponse $response): ?int
    {
        $value = trim($response->header('retry-after') ?? '');
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $seconds = (float) $value;

            return $seconds >= 0 ? (int) ceil($seconds) : null;
        }
        $date = strtotime($value);

        return $date === false ? null : max(0, $date - ($this->now)());
    }
}
