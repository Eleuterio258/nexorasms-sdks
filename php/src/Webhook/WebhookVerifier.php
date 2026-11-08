<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Webhook;

use E258Tech\NexoraSms\Exception\WebhookVerificationException;
use E258Tech\NexoraSms\Internal\Json;

/**
 * Verifies X-Nexora-Signature (t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")>) and parses the event.
 * Use the raw body exactly as received (e.g. file_get_contents('php://input')), before any JSON parsing.
 */
final class WebhookVerifier
{
    public const DEFAULT_TOLERANCE = 300;
    public const HEADER = 'X-Nexora-Signature';

    /** @var \Closure(): int */
    private readonly \Closure $now;

    /**
     * @param string                 $secret           The endpoint's signing secret (whsec_…).
     * @param int                    $toleranceSeconds Maximum allowed |now − t| (default 300 s).
     * @param (\Closure(): int)|null $now              Current Unix time in seconds (inject a fixed clock in tests).
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = self::DEFAULT_TOLERANCE,
        ?\Closure $now = null,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('Indique o segredo do webhook (whsec_…).');
        }
        if ($toleranceSeconds < 0) {
            throw new \InvalidArgumentException('toleranceSeconds deve ser >= 0.');
        }
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * @throws WebhookVerificationException When the header is malformed, the signature does not match, the
     *                                      timestamp is outside the tolerance or the body is not JSON.
     */
    public function verify(string $rawBody, ?string $signatureHeader): WebhookEvent
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader ?? '') as $piece) {
            $index = strpos($piece, '=');
            if ($index === false || $index === 0) {
                continue;
            }
            $key = trim(substr($piece, 0, $index));
            $value = trim(substr($piece, $index + 1));
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || preg_match('/^\d{1,15}$/D', $timestamp) !== 1 || $signatures === []) {
            throw new WebhookVerificationException('Cabeçalho X-Nexora-Signature mal formado.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);
        $valid = false;
        foreach ($signatures as $signature) {
            $valid = hash_equals($expected, $signature) || $valid;
        }
        if (!$valid) {
            throw new WebhookVerificationException('Assinatura do webhook inválida.');
        }
        if (abs(($this->now)() - (int) $timestamp) > $this->toleranceSeconds) {
            throw new WebhookVerificationException('Webhook fora da janela de tempo permitida.');
        }

        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookVerificationException('Corpo do webhook não é JSON válido.', 0, $e);
        }
        if (!is_array($event)) {
            throw new WebhookVerificationException('Corpo do webhook não é JSON válido.');
        }

        return WebhookEvent::fromArray(Json::object($event));
    }
}
