using System;
using System.Collections.Generic;
using System.Collections.ObjectModel;
using System.Text.Json;

namespace E258Tech.NexoraSms;

/// <summary>
/// Base exception for every failure reported by the NexoraSMS API (or reaching it).
/// API failures carry the ProblemDetails fields returned by the server.
/// </summary>
public class NexoraSmsException : Exception
{
    private static readonly IReadOnlyDictionary<string, IReadOnlyList<string>> NoErrors =
        new ReadOnlyDictionary<string, IReadOnlyList<string>>(new Dictionary<string, IReadOnlyList<string>>());

    /// <summary>Creates an exception with the ProblemDetails fields of a failed response.</summary>
    /// <param name="message">Exception message (the ProblemDetails title, detail or "HTTP status").</param>
    /// <param name="status">HTTP status code, or <c>null</c> when no response was received.</param>
    /// <param name="title">ProblemDetails <c>title</c> (in Portuguese, safe to show to users).</param>
    /// <param name="detail">ProblemDetails <c>detail</c>.</param>
    /// <param name="traceId">ProblemDetails <c>traceId</c>, useful when contacting support.</param>
    /// <param name="errors">Validation messages per field.</param>
    /// <param name="retryAfter">Value of the <c>Retry-After</c> response header, when present.</param>
    /// <param name="innerException">Underlying exception, if any.</param>
    public NexoraSmsException(
        string message,
        int? status = null,
        string? title = null,
        string? detail = null,
        string? traceId = null,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null,
        TimeSpan? retryAfter = null,
        Exception? innerException = null)
        : base(message, innerException)
    {
        Status = status;
        Title = title;
        Detail = detail;
        TraceId = traceId;
        Errors = errors ?? NoErrors;
        RetryAfter = retryAfter;
    }

    /// <summary>HTTP status code; <c>null</c> for network failures and timeouts.</summary>
    public int? Status { get; }

    /// <summary>ProblemDetails <c>title</c>.</summary>
    public string? Title { get; }

    /// <summary>ProblemDetails <c>detail</c>.</summary>
    public string? Detail { get; }

    /// <summary>ProblemDetails <c>traceId</c>.</summary>
    public string? TraceId { get; }

    /// <summary>Validation messages per field (empty when the API sent none).</summary>
    public IReadOnlyDictionary<string, IReadOnlyList<string>> Errors { get; }

    /// <summary>Value of the <c>Retry-After</c> response header, when present.</summary>
    public TimeSpan? RetryAfter { get; }

    /// <summary>Builds the exception for an HTTP failure from its status, body (ProblemDetails) and Retry-After.</summary>
    internal static NexoraSmsException FromResponse(int status, string? body, TimeSpan? retryAfter)
    {
        string? title = null, detail = null, traceId = null;
        IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null;
        if (!string.IsNullOrWhiteSpace(body))
        {
            try
            {
                using var document = JsonDocument.Parse(body);
                var root = document.RootElement;
                if (root.ValueKind == JsonValueKind.Object)
                {
                    title = StringProperty(root, "title");
                    detail = StringProperty(root, "detail");
                    traceId = StringProperty(root, "traceId") ?? StringProperty(root, "trace_id");
                    errors = ErrorsProperty(root);
                }
            }
            catch (JsonException)
            {
                // Not ProblemDetails (e.g. an HTML page from a proxy): keep only the status.
            }
        }

        var message = !string.IsNullOrEmpty(title) ? title! : !string.IsNullOrEmpty(detail) ? detail! : $"HTTP {status}";
        return status switch
        {
            400 => new ValidationException(message, status, title, detail, traceId, errors, retryAfter),
            401 => new AuthenticationException(message, status, title, detail, traceId, errors, retryAfter),
            402 => new InsufficientFundsException(message, status, title, detail, traceId, errors, retryAfter),
            403 => new PermissionDeniedException(message, status, title, detail, traceId, errors, retryAfter),
            404 => new NotFoundException(message, status, title, detail, traceId, errors, retryAfter),
            409 => new ConflictException(message, status, title, detail, traceId, errors, retryAfter),
            429 => new RateLimitException(message, status, title, detail, traceId, errors, retryAfter),
            >= 500 => new ServerException(message, status, title, detail, traceId, errors, retryAfter),
            _ => new NexoraSmsException(message, status, title, detail, traceId, errors, retryAfter),
        };
    }

    private static string? StringProperty(JsonElement root, string name) =>
        root.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() : null;

    private static IReadOnlyDictionary<string, IReadOnlyList<string>>? ErrorsProperty(JsonElement root)
    {
        if (!root.TryGetProperty("errors", out var errors) || errors.ValueKind != JsonValueKind.Object)
            return null;
        var result = new Dictionary<string, IReadOnlyList<string>>();
        foreach (var field in errors.EnumerateObject())
        {
            var messages = new List<string>();
            if (field.Value.ValueKind == JsonValueKind.Array)
            {
                foreach (var item in field.Value.EnumerateArray())
                    if (item.ValueKind == JsonValueKind.String)
                        messages.Add(item.GetString()!);
            }
            else if (field.Value.ValueKind == JsonValueKind.String)
            {
                messages.Add(field.Value.GetString()!);
            }
            result[field.Name] = messages.AsReadOnly();
        }
        return new ReadOnlyDictionary<string, IReadOnlyList<string>>(result);
    }
}

/// <summary>400: invalid request (missing field, invalid phone, invalid Idempotency-Key…). See <see cref="NexoraSmsException.Errors"/>.</summary>
public sealed class ValidationException : NexoraSmsException
{
    /// <inheritdoc cref="NexoraSmsException(string, int?, string?, string?, string?, IReadOnlyDictionary{string, IReadOnlyList{string}}?, TimeSpan?, Exception?)"/>
    public ValidationException(string message, int? status = 400, string? title = null, string? detail = null, string? traceId = null,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, TimeSpan? retryAfter = null, Exception? innerException = null)
        : base(message, status, title, detail, traceId, errors, retryAfter, innerException) { }
}

/// <summary>401: keys missing, wrong or revoked.</summary>
public sealed class AuthenticationException : NexoraSmsException
{
    /// <inheritdoc cref="NexoraSmsException(string, int?, string?, string?, string?, IReadOnlyDictionary{string, IReadOnlyList{string}}?, TimeSpan?, Exception?)"/>
    public AuthenticationException(string message, int? status = 401, string? title = null, string? detail = null, string? traceId = null,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, TimeSpan? retryAfter = null, Exception? innerException = null)
        : base(message, status, title, detail, traceId, errors, retryAfter, innerException) { }
}

/// <summary>402: not enough balance in the wallet. Nothing was sent.</summary>
public sealed class InsufficientFundsException : NexoraSmsException
{
    /// <inheritdoc cref="NexoraSmsException(string, int?, string?, string?, string?, IReadOnlyDictionary{string, IReadOnlyList{string}}?, TimeSpan?, Exception?)"/>
    public InsufficientFundsException(string message, int? status = 402, string? title = null, string? detail = null, string? traceId = null,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, TimeSpan? retryAfter = null, Exception? innerException = null)
        : base(message, status, title, detail, traceId, errors, retryAfter, innerException) { }
}

/// <summary>403: the key lacks the permission for this operation.</summary>
public sealed class PermissionDeniedException : NexoraSmsException
{
    /// <inheritdoc cref="NexoraSmsException(string, int?, string?, string?, string?, IReadOnlyDictionary{string, IReadOnlyList{string}}?, TimeSpan?, Exception?)"/>
    public PermissionDeniedException(string message, int? status = 403, string? title = null, string? detail = null, string? traceId = null,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, TimeSpan? retryAfter = null, Exception? innerException = null)
        : base(message, status, title, detail, traceId, errors, retryAfter, innerException) { }
}

/// <summary>404: the resource does not exist or belongs to another company.</summary>
public sealed class NotFoundException : NexoraSmsException
{
    /// <inheritdoc cref="NexoraSmsException(string, int?, string?, string?, string?, IReadOnlyDictionary{string, IReadOnlyList{string}}?, TimeSpan?, Exception?)"/>
    public NotFoundException(string message, int? status = 404, string? title = null, string? detail = null, string? traceId = null,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, TimeSpan? retryAfter = null, Exception? innerException = null)
        : base(message, status, title, detail, traceId, errors, retryAfter, innerException) { }
}

/// <summary>409: idempotency conflict, duplicate, or a state that does not allow the operation.</summary>
public sealed class ConflictException : NexoraSmsException
{
    /// <inheritdoc cref="NexoraSmsException(string, int?, string?, string?, string?, IReadOnlyDictionary{string, IReadOnlyList{string}}?, TimeSpan?, Exception?)"/>
    public ConflictException(string message, int? status = 409, string? title = null, string? detail = null, string? traceId = null,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, TimeSpan? retryAfter = null, Exception? innerException = null)
        : base(message, status, title, detail, traceId, errors, retryAfter, innerException) { }
}

/// <summary>429: too many requests. See <see cref="NexoraSmsException.RetryAfter"/>.</summary>
public sealed class RateLimitException : NexoraSmsException
{
    /// <inheritdoc cref="NexoraSmsException(string, int?, string?, string?, string?, IReadOnlyDictionary{string, IReadOnlyList{string}}?, TimeSpan?, Exception?)"/>
    public RateLimitException(string message, int? status = 429, string? title = null, string? detail = null, string? traceId = null,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, TimeSpan? retryAfter = null, Exception? innerException = null)
        : base(message, status, title, detail, traceId, errors, retryAfter, innerException) { }
}

/// <summary>5xx: server-side failure. Retry later with the same Idempotency-Key.</summary>
public sealed class ServerException : NexoraSmsException
{
    /// <inheritdoc cref="NexoraSmsException(string, int?, string?, string?, string?, IReadOnlyDictionary{string, IReadOnlyList{string}}?, TimeSpan?, Exception?)"/>
    public ServerException(string message, int? status = 500, string? title = null, string? detail = null, string? traceId = null,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? errors = null, TimeSpan? retryAfter = null, Exception? innerException = null)
        : base(message, status, title, detail, traceId, errors, retryAfter, innerException) { }
}

/// <summary>Network failure or timeout: no HTTP response, so <see cref="NexoraSmsException.Status"/> is <c>null</c>.</summary>
public sealed class ConnectionException : NexoraSmsException
{
    /// <summary>Creates a connection exception.</summary>
    /// <param name="message">What failed.</param>
    /// <param name="innerException">The network or timeout exception.</param>
    public ConnectionException(string message, Exception? innerException = null)
        : base(message, innerException: innerException) { }
}

/// <summary>A webhook whose signature, timestamp or body could not be verified. Reject the request (e.g. HTTP 400).</summary>
public sealed class WebhookVerificationException : Exception
{
    /// <summary>Creates a webhook verification exception.</summary>
    /// <param name="message">Why the webhook was rejected.</param>
    /// <param name="innerException">Underlying exception, if any.</param>
    public WebhookVerificationException(string message, Exception? innerException = null) : base(message, innerException) { }
}
