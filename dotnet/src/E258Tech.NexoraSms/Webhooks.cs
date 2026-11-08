using System;
using System.Collections.Generic;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace E258Tech.NexoraSms;

/// <summary>Webhook event types sent by NexoraSMS.</summary>
public static class WebhookEventTypes
{
    /// <summary>A message was delivered (<c>data</c> is a <see cref="MessageEvent"/>).</summary>
    public const string MessageDelivered = "message.delivered";

    /// <summary>A message failed for good (<c>data</c> is a <see cref="MessageEvent"/>).</summary>
    public const string MessageFailed = "message.failed";

    /// <summary>A campaign (or direct send) finished.</summary>
    public const string CampaignCompleted = "campaign.completed";

    /// <summary>A campaign was paused automatically.</summary>
    public const string CampaignPaused = "campaign.paused";

    /// <summary>A contact opted out of a channel.</summary>
    public const string ContactOptedOut = "contact.opted_out";

    /// <summary>Test sent from the dashboard.</summary>
    public const string Ping = "ping";
}

/// <summary>A verified webhook event.</summary>
public sealed record WebhookEvent
{
    /// <summary>Event id (use it to ignore repeated deliveries).</summary>
    public string Id { get; init; } = "";

    /// <summary>Event type (see <see cref="WebhookEventTypes"/>).</summary>
    public string Type { get; init; } = "";

    /// <summary>When the event was created.</summary>
    public DateTimeOffset CreatedAt { get; init; }

    /// <summary>Event data as raw JSON (snake_case, as sent).</summary>
    public JsonElement Data { get; init; }

    /// <summary>True for <c>message.delivered</c> and <c>message.failed</c>.</summary>
    public bool IsMessageEvent => Type is WebhookEventTypes.MessageDelivered or WebhookEventTypes.MessageFailed;

    /// <summary>Reads <see cref="Data"/> as a <see cref="MessageEvent"/>.</summary>
    /// <exception cref="InvalidOperationException">The event is not a message event.</exception>
    public MessageEvent GetMessageEvent()
    {
        if (!IsMessageEvent)
            throw new InvalidOperationException($"O evento '{Type}' não é um evento de mensagem.");
        return GetData<MessageEvent>();
    }

    /// <summary>Reads <see cref="Data"/> as <typeparamref name="T"/> (snake_case properties).</summary>
    /// <exception cref="InvalidOperationException">The data cannot be read as <typeparamref name="T"/>.</exception>
    public T GetData<T>()
    {
        try
        {
            return Data.Deserialize<T>(NexoraJson.Options)
                ?? throw new InvalidOperationException("O evento não tem dados.");
        }
        catch (JsonException e)
        {
            throw new InvalidOperationException($"Os dados do evento não podem ser lidos como {typeof(T).Name}.", e);
        }
    }
}

/// <summary>Data of <c>message.delivered</c> and <c>message.failed</c>.</summary>
public sealed record MessageEvent
{
    /// <summary>Message id.</summary>
    public string MessageId { get; init; } = "";

    /// <summary>Campaign id.</summary>
    public string CampaignId { get; init; } = "";

    /// <summary>Contact id.</summary>
    public string? ContactId { get; init; }

    /// <summary>Id of the contact in your system.</summary>
    public string? ExternalId { get; init; }

    /// <summary>Channel.</summary>
    public Channel Channel { get; init; }

    /// <summary>Final status (delivered or failed).</summary>
    public MessageStatus Status { get; init; }

    /// <summary>Cost.</summary>
    public decimal Cost { get; init; }

    /// <summary>When it happened (events may arrive out of order).</summary>
    public DateTimeOffset OccurredAt { get; init; }
}

/// <summary>
/// Verifies <c>X-Nexora-Signature</c> (<c>t=&lt;unix&gt;,v1=&lt;hex HMAC-SHA256(secret, "&lt;t&gt;.&lt;body&gt;")&gt;</c>) and
/// parses the event. Use the raw body exactly as received, before any JSON parsing. Thread-safe.
/// </summary>
public sealed class WebhookVerifier
{
    /// <summary>Default tolerance between the signature time and now: 300 s.</summary>
    public static readonly TimeSpan DefaultTolerance = TimeSpan.FromSeconds(300);

    private readonly byte[] _secret;
    private readonly TimeSpan _tolerance;
    private readonly TimeProvider _timeProvider;

    /// <summary>Creates a verifier.</summary>
    /// <param name="secret">The endpoint's signing secret (<c>whsec_…</c>).</param>
    /// <param name="tolerance">Maximum difference between the signature time and now (default 300 s).</param>
    /// <exception cref="ArgumentException">Empty secret or negative tolerance.</exception>
    public WebhookVerifier(string secret, TimeSpan? tolerance = null) : this(secret, tolerance, TimeProvider.System) { }

    internal WebhookVerifier(string secret, TimeSpan? tolerance, TimeProvider timeProvider)
    {
        if (string.IsNullOrEmpty(secret))
            throw new ArgumentException("Indique o segredo do webhook (whsec_…).", nameof(secret));
        if (tolerance < TimeSpan.Zero)
            throw new ArgumentOutOfRangeException(nameof(tolerance), tolerance, "A tolerância não pode ser negativa.");
        _secret = Encoding.UTF8.GetBytes(secret);
        _tolerance = tolerance ?? DefaultTolerance;
        _timeProvider = timeProvider ?? throw new ArgumentNullException(nameof(timeProvider));
    }

    /// <summary>Verifies a webhook whose raw body is text.</summary>
    /// <param name="rawBody">Body exactly as received.</param>
    /// <param name="signatureHeader">Value of <c>X-Nexora-Signature</c>.</param>
    /// <exception cref="WebhookVerificationException">Malformed header, wrong signature, expired timestamp or invalid JSON.</exception>
    public WebhookEvent Verify(string rawBody, string? signatureHeader) =>
        Verify(Encoding.UTF8.GetBytes(rawBody ?? throw new ArgumentNullException(nameof(rawBody))), signatureHeader);

    /// <summary>Verifies a webhook from its raw body bytes.</summary>
    /// <param name="rawBody">Body exactly as received.</param>
    /// <param name="signatureHeader">Value of <c>X-Nexora-Signature</c>.</param>
    /// <exception cref="WebhookVerificationException">Malformed header, wrong signature, expired timestamp or invalid JSON.</exception>
    public WebhookEvent Verify(ReadOnlySpan<byte> rawBody, string? signatureHeader)
    {
        var parts = ParseHeader(signatureHeader);
        if (!parts.TryGetValue("t", out var t) || !long.TryParse(t, System.Globalization.NumberStyles.None,
                System.Globalization.CultureInfo.InvariantCulture, out var timestamp)
            || !parts.TryGetValue("v1", out var v1) || v1.Length == 0)
            throw new WebhookVerificationException("Cabeçalho X-Nexora-Signature mal formado.");

        var prefix = Encoding.UTF8.GetBytes(t + ".");
        var signed = new byte[prefix.Length + rawBody.Length];
        prefix.CopyTo(signed, 0);
        rawBody.CopyTo(signed.AsSpan(prefix.Length));
        var expected = Encoding.ASCII.GetBytes(Convert.ToHexString(HMACSHA256.HashData(_secret, signed)).ToLowerInvariant());
        if (!CryptographicOperations.FixedTimeEquals(expected, Encoding.UTF8.GetBytes(v1)))
            throw new WebhookVerificationException("Assinatura do webhook inválida.");

        var nowSeconds = _timeProvider.GetUtcNow().ToUnixTimeMilliseconds() / 1000.0;
        if (Math.Abs(nowSeconds - timestamp) > _tolerance.TotalSeconds)
            throw new WebhookVerificationException("Webhook fora da janela de tempo permitida.");

        try
        {
            var reader = new Utf8JsonReader(rawBody);
            var webhookEvent = JsonSerializer.Deserialize<WebhookEvent>(ref reader, NexoraJson.Options);
            return webhookEvent ?? throw new WebhookVerificationException("Corpo do webhook não é JSON válido.");
        }
        catch (JsonException e)
        {
            throw new WebhookVerificationException("Corpo do webhook não é JSON válido.", e);
        }
    }

    private static Dictionary<string, string> ParseHeader(string? header)
    {
        var parts = new Dictionary<string, string>(StringComparer.Ordinal);
        foreach (var piece in (header ?? "").Split(','))
        {
            var index = piece.IndexOf('=');
            if (index > 0)
                parts[piece[..index].Trim()] = piece[(index + 1)..].Trim();
        }
        return parts;
    }
}
