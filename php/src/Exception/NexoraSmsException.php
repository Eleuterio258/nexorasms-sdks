<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Exception;

/**
 * Base exception for failed API calls. Carries the ProblemDetails fields returned by NexoraSMS.
 * The message is the title (or the detail, or "HTTP <status>").
 */
class NexoraSmsException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors Field => messages (validation errors).
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $title = null,
        public readonly ?string $detail = null,
        public readonly ?string $traceId = null,
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /**
     * Builds the exception for an HTTP failure from its status, decoded body (ProblemDetails) and Retry-After.
     *
     * @internal
     *
     * @param mixed $body Decoded JSON body.
     */
    public static function fromResponse(int $status, mixed $body, ?int $retryAfter): self
    {
        $problem = is_array($body) ? $body : [];
        $title = self::text($problem['title'] ?? null);
        $detail = self::text($problem['detail'] ?? null);
        $traceId = self::text($problem['traceId'] ?? $problem['trace_id'] ?? null);

        $errors = [];
        if (isset($problem['errors']) && is_array($problem['errors'])) {
            foreach ($problem['errors'] as $field => $messages) {
                $list = [];
                foreach (is_array($messages) ? $messages : [$messages] as $message) {
                    if (is_scalar($message)) {
                        $list[] = (string) $message;
                    }
                }
                $errors[(string) $field] = $list;
            }
        }

        $message = $title ?? $detail ?? 'HTTP ' . $status;
        $class = match (true) {
            $status === 400 => ValidationException::class,
            $status === 401 => AuthenticationException::class,
            $status === 402 => InsufficientFundsException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => self::class,
        };

        return new $class($message, $status, $title, $detail, $traceId, $errors, $retryAfter);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
