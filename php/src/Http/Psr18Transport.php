<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Adapter for any PSR-18 HTTP client (Guzzle 7, Symfony HttpClient, …).
 *
 * Optional: requires the packages psr/http-client and psr/http-factory (and a PSR-7 implementation), which this
 * SDK does not install. The timeout is not passed per request (PSR-18 has no such option): configure it in your
 * client.
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(HttpRequest $request, float $timeout): HttpResponse
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }
        if ($request->body !== null) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            $response = $this->client->sendRequest($psrRequest);
        } catch (NetworkExceptionInterface $e) {
            throw new TransportException($e->getMessage(), stripos($e->getMessage(), 'timed out') !== false, $e);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), false, $e);
        }

        $headers = [];
        foreach (array_keys($response->getHeaders()) as $name) {
            $headers[(string) $name] = $response->getHeaderLine((string) $name);
        }

        return new HttpResponse($response->getStatusCode(), $headers, (string) $response->getBody());
    }
}
