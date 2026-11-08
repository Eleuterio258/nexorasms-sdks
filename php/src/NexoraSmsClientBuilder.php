<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms;

use E258Tech\NexoraSms\Http\CurlTransport;
use E258Tech\NexoraSms\Http\Psr18Transport;
use E258Tech\NexoraSms\Http\Transport;
use E258Tech\NexoraSms\Internal\ApiCore;
use E258Tech\NexoraSms\Internal\Sleeper;
use E258Tech\NexoraSms\Internal\SystemSleeper;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Configures and builds a {@see NexoraSmsClient}.
 */
final class NexoraSmsClientBuilder
{
    public const PUBLIC_KEY_PREFIX = 'e258_nex_sms_pk_';
    public const API_KEY_PREFIX = 'e258_nex_sms_ak_';

    private ?string $publicKey = null;
    private ?string $apiKey = null;
    private string $baseUrl = NexoraSmsClient::DEFAULT_BASE_URL;
    private float $timeout = 30.0;
    private int $maxRetries = 2;
    private ?string $userAgent = null;
    private ?Transport $transport = null;
    private ?Sleeper $sleeper = null;

    /**
     * Public key (X-Public-Key), e258_nex_sms_pk_…
     */
    public function publicKey(string $value): self
    {
        $this->publicKey = $value;

        return $this;
    }

    /**
     * Secret key (X-Api-Key), e258_nex_sms_ak_… Keep it on the server only.
     */
    public function apiKey(string $value): self
    {
        $this->apiKey = $value;

        return $this;
    }

    /**
     * API address (default https://sms.e258tech.tech). A trailing slash is removed.
     */
    public function baseUrl(string $value): self
    {
        $this->baseUrl = rtrim($value, '/');

        return $this;
    }

    /**
     * Per-attempt timeout in seconds (default 30).
     *
     * @throws \InvalidArgumentException
     */
    public function timeout(float $seconds): self
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new \InvalidArgumentException('timeout deve ser um número positivo de segundos.');
        }
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Retries after network errors, 429, 502–504 and 409 with Retry-After (default 2; 0 disables).
     *
     * @throws \InvalidArgumentException
     */
    public function maxRetries(int $value): self
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('maxRetries deve ser um inteiro >= 0.');
        }
        $this->maxRetries = $value;

        return $this;
    }

    /**
     * Extra text appended to the SDK's User-Agent (e.g. "loja-online/2.3").
     */
    public function userAgent(string $value): self
    {
        $this->userAgent = $value;

        return $this;
    }

    /**
     * Custom transport (proxy, tests, another HTTP library). Defaults to {@see CurlTransport}.
     */
    public function transport(Transport $transport): self
    {
        $this->transport = $transport;

        return $this;
    }

    /**
     * Uses a PSR-18 client. Requires psr/http-client and psr/http-factory (not installed by this SDK).
     * Configure the timeout in your client: PSR-18 has no per-request timeout.
     */
    public function psr18(
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
    ): self {
        return $this->transport(new Psr18Transport($client, $requestFactory, $streamFactory));
    }

    /**
     * @internal Waits between retries (tests).
     */
    public function sleeper(Sleeper $sleeper): self
    {
        $this->sleeper = $sleeper;

        return $this;
    }

    /**
     * @throws \InvalidArgumentException When a key is missing or has the wrong prefix, or the base URL is invalid.
     */
    public function build(): NexoraSmsClient
    {
        if ($this->publicKey === null || !str_starts_with($this->publicKey, self::PUBLIC_KEY_PREFIX)) {
            throw new \InvalidArgumentException(sprintf('publicKey em falta ou inválida: deve começar por %s.', self::PUBLIC_KEY_PREFIX));
        }
        if ($this->apiKey === null || !str_starts_with($this->apiKey, self::API_KEY_PREFIX)) {
            throw new \InvalidArgumentException(sprintf('apiKey em falta ou inválida: deve começar por %s.', self::API_KEY_PREFIX));
        }
        $scheme = parse_url($this->baseUrl, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true) || parse_url($this->baseUrl, PHP_URL_HOST) === null) {
            throw new \InvalidArgumentException('baseUrl inválida: indique um endereço http(s) completo.');
        }
        if ($this->transport === null && !extension_loaded('curl')) {
            throw new \InvalidArgumentException('A extensão curl não está disponível: instale ext-curl ou indique transport().');
        }

        $userAgent = 'nexorasms-php/' . NexoraSmsClient::VERSION
            . ($this->userAgent !== null && $this->userAgent !== '' ? ' ' . $this->userAgent : '');

        return new NexoraSmsClient(new ApiCore(
            $this->publicKey,
            $this->apiKey,
            $this->baseUrl,
            $this->timeout,
            $this->maxRetries,
            $userAgent,
            $this->transport ?? new CurlTransport(),
            $this->sleeper ?? new SystemSleeper(),
        ));
    }
}
