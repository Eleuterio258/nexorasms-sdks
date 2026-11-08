<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Http;

/**
 * Default transport, based on ext-curl. One cURL handle per request; no shared state.
 */
final class CurlTransport implements Transport
{
    /**
     * @param array<int, mixed> $curlOptions Extra cURL options (e.g. proxy, CA bundle), applied last.
     */
    public function __construct(
        private readonly float $connectTimeout = 10.0,
        private readonly array $curlOptions = [],
    ) {
    }

    public function send(HttpRequest $request, float $timeout): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('curl_init failed.');
        }

        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $headerLines[] = 'Expect:';

        $responseHeaders = [];
        /** @var array<int, mixed> $options */
        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round(min($this->connectTimeout, $timeout) * 1000)),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $index = strpos($line, ':');
                if ($index !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $index)))] = trim(substr($line, $index + 1));
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // new response block (e.g. after 100 Continue)
                }

                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        if ($request->method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        curl_setopt_array($handle, $options);
        if ($this->curlOptions !== []) {
            curl_setopt_array($handle, $this->curlOptions);
        }

        $body = curl_exec($handle);
        if ($body === false || !is_string($body)) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);

            throw new TransportException(
                sprintf('cURL error %d: %s', $errno, $error),
                $errno === CURLE_OPERATION_TIMEDOUT,
            );
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        /** @var array<string, string> $responseHeaders */
        return new HttpResponse($status, $responseHeaders, $body);
    }
}
