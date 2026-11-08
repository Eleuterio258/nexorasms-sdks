using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace E258Tech.NexoraSms;

/// <summary>
/// Client for the NexoraSMS API. Create it with <see cref="Builder"/>; it is immutable and thread-safe, so keep one
/// instance for the whole application. Dispose it when done: it disposes the <see cref="System.Net.Http.HttpClient"/>
/// only if it created it.
/// </summary>
public sealed class NexoraSmsClient : IDisposable
{
    /// <summary>SDK version.</summary>
    public const string Version = "0.1.0";

    /// <summary>Default API address.</summary>
    public const string DefaultBaseUrl = "https://sms.e258tech.tech";

    internal const string PublicKeyPrefix = "e258_nex_sms_pk_";
    internal const string ApiKeyPrefix = "e258_nex_sms_ak_";
    private static readonly TimeSpan MaxRetryAfter = TimeSpan.FromSeconds(30);
    private static readonly TimeSpan MaxBackoff = TimeSpan.FromSeconds(8);

    private readonly string _publicKey;
    private readonly string _apiKey;
    private readonly HttpClient _http;
    private readonly bool _ownsHttpClient;
    private readonly TimeProvider _timeProvider;
    private int _disposed;

    internal NexoraSmsClient(string publicKey, string apiKey, string baseUrl, TimeSpan timeout, int maxRetries, string userAgent,
        HttpClient? httpClient, TimeProvider timeProvider)
    {
        _publicKey = publicKey;
        _apiKey = apiKey;
        BaseUrl = baseUrl;
        Timeout = timeout;
        MaxRetries = maxRetries;
        UserAgent = userAgent;
        _timeProvider = timeProvider;
        if (httpClient is null)
        {
            _http = new HttpClient(new SocketsHttpHandler { PooledConnectionLifetime = TimeSpan.FromMinutes(5) })
            {
                Timeout = System.Threading.Timeout.InfiniteTimeSpan, // the SDK applies its own per-attempt timeout
            };
            _ownsHttpClient = true;
        }
        else
        {
            _http = httpClient;
        }
        Messages = new MessagesResource(this);
        Contacts = new ContactsResource(this);
        Wallet = new WalletResource(this);
        Campaigns = new CampaignsResource(this);
    }

    /// <summary>Starts configuring a client.</summary>
    public static NexoraSmsClientBuilder Builder() => new();

    /// <summary>API address, without a trailing slash.</summary>
    public string BaseUrl { get; }

    /// <summary>Timeout of each attempt.</summary>
    public TimeSpan Timeout { get; }

    /// <summary>Retries after transient failures (0 = none).</summary>
    public int MaxRetries { get; }

    /// <summary>User-Agent sent on every request.</summary>
    public string UserAgent { get; }

    /// <summary>Direct sends, estimates and message status.</summary>
    public MessagesResource Messages { get; }

    /// <summary>Contacts and consents.</summary>
    public ContactsResource Contacts { get; }

    /// <summary>Wallet balance and transactions.</summary>
    public WalletResource Wallet { get; }

    /// <summary>Campaigns and their statistics.</summary>
    public CampaignsResource Campaigns { get; }

    /// <summary>Disposes the HTTP client if this instance created it.</summary>
    public void Dispose()
    {
        if (Interlocked.Exchange(ref _disposed, 1) == 0 && _ownsHttpClient)
            _http.Dispose();
    }

    /// <summary>Sends a request, retrying when allowed, and deserializes the response.</summary>
    internal async Task<ApiResponse<T>> SendAsync<T>(HttpMethod method, string path, IEnumerable<KeyValuePair<string, string?>>? query,
        object? body, string? idempotencyKey, bool retryable, CancellationToken cancellationToken)
    {
        ObjectDisposedException.ThrowIf(_disposed != 0, this);
        var uri = new Uri(BaseUrl + path + QueryString(query));
        var payload = body is null ? null : JsonSerializer.Serialize(body, body.GetType(), NexoraJson.Options);

        for (var attempt = 0; ; attempt++)
        {
            cancellationToken.ThrowIfCancellationRequested();
            HttpResponseMessage? response = null;
            string text;
            using var attemptCts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
            attemptCts.CancelAfter(Timeout);
            try
            {
                using var request = CreateRequest(method, uri, payload, idempotencyKey);
                response = await _http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, attemptCts.Token).ConfigureAwait(false);
                text = await response.Content.ReadAsStringAsync(attemptCts.Token).ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                response?.Dispose();
                throw;
            }
            catch (Exception e) when (e is HttpRequestException or OperationCanceledException or IOException)
            {
                response?.Dispose();
                if (retryable && attempt < MaxRetries)
                {
                    await DelayAsync(Backoff(attempt), cancellationToken).ConfigureAwait(false);
                    continue;
                }
                throw new ConnectionException(
                    e is OperationCanceledException
                        ? $"Tempo limite excedido ao contactar {uri.Host}."
                        : $"Não foi possível contactar {uri.Host}.",
                    e);
            }

            using (response)
            {
                var status = (int)response.StatusCode;
                if (response.IsSuccessStatusCode)
                {
                    var replayed = response.Headers.TryGetValues("Idempotent-Replayed", out var values)
                        && values.Any(v => string.Equals(v, "true", StringComparison.OrdinalIgnoreCase));
                    return new ApiResponse<T>(Deserialize<T>(text, status), replayed);
                }

                var retryAfter = RetryAfterOf(response);
                var transient = status is 429 or 502 or 503 or 504 || (status == 409 && retryAfter is not null);
                if (retryable && transient && attempt < MaxRetries)
                {
                    var wait = retryAfter is { } ra ? (ra > MaxRetryAfter ? MaxRetryAfter : ra) : Backoff(attempt);
                    await DelayAsync(wait, cancellationToken).ConfigureAwait(false);
                    continue;
                }
                throw NexoraSmsException.FromResponse(status, text, retryAfter);
            }
        }
    }

    private HttpRequestMessage CreateRequest(HttpMethod method, Uri uri, string? payload, string? idempotencyKey)
    {
        var request = new HttpRequestMessage(method, uri);
        request.Headers.TryAddWithoutValidation("X-Public-Key", _publicKey);
        request.Headers.TryAddWithoutValidation("X-Api-Key", _apiKey);
        request.Headers.TryAddWithoutValidation("Accept", "application/json");
        request.Headers.TryAddWithoutValidation("User-Agent", UserAgent);
        if (idempotencyKey is not null)
            request.Headers.TryAddWithoutValidation("Idempotency-Key", idempotencyKey);
        if (payload is not null)
        {
            request.Content = new StringContent(payload, Encoding.UTF8);
            request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
        }
        return request;
    }

    private static T Deserialize<T>(string text, int status)
    {
        try
        {
            var value = string.IsNullOrWhiteSpace(text) ? default : JsonSerializer.Deserialize<T>(text, NexoraJson.Options);
            if (value is not null)
                return value;
        }
        catch (JsonException e)
        {
            throw new NexoraSmsException($"Resposta inválida da API (HTTP {status}).", status, innerException: e);
        }
        throw new NexoraSmsException($"Resposta vazia da API (HTTP {status}).", status);
    }

    private TimeSpan? RetryAfterOf(HttpResponseMessage response)
    {
        var header = response.Headers.RetryAfter;
        if (header?.Delta is { } delta)
            return delta < TimeSpan.Zero ? TimeSpan.Zero : delta;
        if (header?.Date is { } date)
        {
            var seconds = Math.Ceiling((date - _timeProvider.GetUtcNow()).TotalSeconds);
            return TimeSpan.FromSeconds(Math.Max(0, seconds));
        }
        return null;
    }

    /// <summary>0.5 s × 2^attempt with ±20 % jitter, at most 8 s.</summary>
    internal static TimeSpan Backoff(int attempt)
    {
        var baseMs = 500 * Math.Pow(2, Math.Min(attempt, 10));
        var ms = baseMs * (0.8 + Random.Shared.NextDouble() * 0.4);
        return TimeSpan.FromMilliseconds(Math.Min(ms, MaxBackoff.TotalMilliseconds));
    }

    private Task DelayAsync(TimeSpan delay, CancellationToken cancellationToken) =>
        Task.Delay(delay, _timeProvider, cancellationToken);

    private static string QueryString(IEnumerable<KeyValuePair<string, string?>>? query)
    {
        if (query is null)
            return "";
        var parts = query
            .Where(p => p.Value is not null)
            .Select(p => Uri.EscapeDataString(p.Key) + "=" + Uri.EscapeDataString(p.Value!))
            .ToList();
        return parts.Count == 0 ? "" : "?" + string.Join("&", parts);
    }

    /// <summary>Escapes one path segment (e.g. an external id with "/" or spaces).</summary>
    internal static string Segment(string id, string parameterName)
    {
        if (string.IsNullOrWhiteSpace(id))
            throw new ArgumentException("Identificador em falta.", parameterName);
        return Uri.EscapeDataString(id);
    }
}

internal readonly record struct ApiResponse<T>(T Value, bool Replayed);

/// <summary>Configures a <see cref="NexoraSmsClient"/>. Not thread-safe; <see cref="Build"/> validates.</summary>
public sealed class NexoraSmsClientBuilder
{
    private string? _publicKey;
    private string? _apiKey;
    private string _baseUrl = NexoraSmsClient.DefaultBaseUrl;
    private TimeSpan _timeout = TimeSpan.FromSeconds(30);
    private int _maxRetries = 2;
    private string? _userAgent;
    private HttpClient? _httpClient;
    private TimeProvider _timeProvider = System.TimeProvider.System;

    internal NexoraSmsClientBuilder() { }

    /// <summary>Public key (<c>X-Public-Key</c>), starting with <c>e258_nex_sms_pk_</c>. Required.</summary>
    public NexoraSmsClientBuilder PublicKey(string publicKey) { _publicKey = publicKey; return this; }

    /// <summary>Secret key (<c>X-Api-Key</c>), starting with <c>e258_nex_sms_ak_</c>. Required. Keep it on the server only.</summary>
    public NexoraSmsClientBuilder ApiKey(string apiKey) { _apiKey = apiKey; return this; }

    /// <summary>API address (default <c>https://sms.e258tech.tech</c>); a trailing slash is removed.</summary>
    /// <exception cref="ArgumentException">Not an absolute http(s) address.</exception>
    public NexoraSmsClientBuilder BaseUrl(string baseUrl)
    {
        if (!Uri.TryCreate(baseUrl, UriKind.Absolute, out var uri) || (uri.Scheme != Uri.UriSchemeHttps && uri.Scheme != Uri.UriSchemeHttp))
            throw new ArgumentException("baseUrl deve ser um endereço http(s) absoluto.", nameof(baseUrl));
        _baseUrl = baseUrl.TrimEnd('/');
        return this;
    }

    /// <summary>Timeout of each attempt (default 30 s).</summary>
    /// <exception cref="ArgumentOutOfRangeException">Zero or negative.</exception>
    public NexoraSmsClientBuilder Timeout(TimeSpan timeout)
    {
        if (timeout <= TimeSpan.Zero && timeout != System.Threading.Timeout.InfiniteTimeSpan)
            throw new ArgumentOutOfRangeException(nameof(timeout), timeout, "timeout deve ser positivo.");
        _timeout = timeout;
        return this;
    }

    /// <summary>Retries after network errors, 429, 502–504 and 409 with Retry-After (default 2; 0 disables).</summary>
    /// <exception cref="ArgumentOutOfRangeException">Negative.</exception>
    public NexoraSmsClientBuilder MaxRetries(int maxRetries)
    {
        if (maxRetries < 0)
            throw new ArgumentOutOfRangeException(nameof(maxRetries), maxRetries, "maxRetries deve ser um inteiro >= 0.");
        _maxRetries = maxRetries;
        return this;
    }

    /// <summary>Extra text appended to the SDK's User-Agent (e.g. <c>loja-online/2.3</c>).</summary>
    public NexoraSmsClientBuilder UserAgent(string userAgent) { _userAgent = userAgent; return this; }

    /// <summary>
    /// Your own <see cref="System.Net.Http.HttpClient"/> (e.g. from <c>IHttpClientFactory</c>, or with a proxy). The SDK
    /// does not dispose it. Its own <see cref="System.Net.Http.HttpClient.Timeout"/> also applies.
    /// </summary>
    public NexoraSmsClientBuilder HttpClient(HttpClient httpClient)
    {
        _httpClient = httpClient ?? throw new ArgumentNullException(nameof(httpClient));
        return this;
    }

    /// <summary>Clock used for retry waits (tests).</summary>
    internal NexoraSmsClientBuilder TimeProvider(TimeProvider timeProvider)
    {
        _timeProvider = timeProvider ?? throw new ArgumentNullException(nameof(timeProvider));
        return this;
    }

    /// <summary>Validates the configuration and creates the client.</summary>
    /// <exception cref="InvalidOperationException">A key is missing or has the wrong prefix.</exception>
    public NexoraSmsClient Build()
    {
        if (string.IsNullOrEmpty(_publicKey) || !_publicKey.StartsWith(NexoraSmsClient.PublicKeyPrefix, StringComparison.Ordinal))
            throw new InvalidOperationException($"publicKey em falta ou inválida: deve começar por {NexoraSmsClient.PublicKeyPrefix}.");
        if (string.IsNullOrEmpty(_apiKey) || !_apiKey.StartsWith(NexoraSmsClient.ApiKeyPrefix, StringComparison.Ordinal))
            throw new InvalidOperationException($"apiKey em falta ou inválida: deve começar por {NexoraSmsClient.ApiKeyPrefix}.");
        var userAgent = $"nexorasms-dotnet/{NexoraSmsClient.Version}" + (string.IsNullOrWhiteSpace(_userAgent) ? "" : " " + _userAgent.Trim());
        return new NexoraSmsClient(_publicKey, _apiKey, _baseUrl, _timeout, _maxRetries, userAgent, _httpClient, _timeProvider);
    }
}
