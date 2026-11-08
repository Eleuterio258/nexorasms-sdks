using System.Collections.Concurrent;
using System.Net;
using System.Text;
using System.Text.Json.Nodes;

namespace E258Tech.NexoraSms.Tests;

/// <summary>A request seen by <see cref="FakeHandler"/>.</summary>
public sealed record RecordedRequest(HttpMethod Method, string PathAndQuery, Dictionary<string, string> Headers, string? Body)
{
    public string? Header(string name) => Headers.TryGetValue(name.ToLowerInvariant(), out var value) ? value : null;

    public JsonNode? Json => Body is null ? null : JsonNode.Parse(Body);
}

/// <summary>Local fake HTTP server: answers from a queue and records every request. Never touches the network.</summary>
public sealed class FakeHandler : HttpMessageHandler
{
    private readonly ConcurrentQueue<Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>>> _replies = new();

    public List<RecordedRequest> Seen { get; } = new();

    public FakeHandler Reply(int status, object? body = null, params (string Name, string Value)[] headers)
    {
        _replies.Enqueue((_, _) =>
        {
            var response = new HttpResponseMessage((HttpStatusCode)status);
            var text = body switch { null => "", string s => s, _ => System.Text.Json.JsonSerializer.Serialize(body) };
            response.Content = new StringContent(text, Encoding.UTF8, "application/json");
            foreach (var (name, value) in headers)
                response.Headers.TryAddWithoutValidation(name, value);
            return Task.FromResult(response);
        });
        return this;
    }

    public FakeHandler Throw(Exception exception)
    {
        _replies.Enqueue((_, _) => Task.FromException<HttpResponseMessage>(exception));
        return this;
    }

    public FakeHandler Hang()
    {
        _replies.Enqueue(async (_, ct) =>
        {
            await Task.Delay(Timeout.Infinite, ct);
            throw new InvalidOperationException("unreachable");
        });
        return this;
    }

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        var headers = new Dictionary<string, string>();
        foreach (var (name, values) in request.Headers.NonValidated)
            headers[name.ToLowerInvariant()] = values.ToString();
        string? body = null;
        if (request.Content is not null)
        {
            foreach (var (name, values) in request.Content.Headers.NonValidated)
                headers[name.ToLowerInvariant()] = values.ToString();
            body = await request.Content.ReadAsStringAsync(cancellationToken);
        }
        lock (Seen)
            Seen.Add(new RecordedRequest(request.Method, request.RequestUri!.PathAndQuery, headers, body));

        if (!_replies.TryDequeue(out var reply))
            return new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent("{}", Encoding.UTF8, "application/json") };
        return await reply(request, cancellationToken);
    }
}

/// <summary>Clock for tests: fixed "now", and timers that fire at once while recording the requested delays.</summary>
public sealed class FakeTimeProvider : TimeProvider
{
    private readonly List<TimeSpan> _delays = new();

    public FakeTimeProvider(DateTimeOffset? now = null) => Now = now ?? new DateTimeOffset(2026, 11, 8, 10, 0, 0, TimeSpan.Zero);

    public DateTimeOffset Now { get; set; }

    public IReadOnlyList<TimeSpan> Delays
    {
        get { lock (_delays) return _delays.ToList(); }
    }

    public override DateTimeOffset GetUtcNow() => Now;

    public override ITimer CreateTimer(TimerCallback callback, object? state, TimeSpan dueTime, TimeSpan period)
    {
        if (dueTime != Timeout.InfiniteTimeSpan)
        {
            lock (_delays)
                _delays.Add(dueTime);
            ThreadPool.QueueUserWorkItem(_ => callback(state));
        }
        return new NoopTimer();
    }

    private sealed class NoopTimer : ITimer
    {
        public bool Change(TimeSpan dueTime, TimeSpan period) => true;
        public void Dispose() { }
        public ValueTask DisposeAsync() => ValueTask.CompletedTask;
    }
}

/// <summary>A client wired to a fake handler and a fake clock.</summary>
public sealed class TestApi : IDisposable
{
    public const string PublicKey = "e258_nex_sms_pk_HXj4qcWmXl0m";
    public const string ApiKey = "e258_nex_sms_ak_secretsecretsecretsecret123";
    public const string BaseUrl = "http://nexorasms.test";

    public FakeHandler Handler { get; } = new();
    public FakeTimeProvider Time { get; } = new();
    public HttpClient Http { get; }

    public TestApi() => Http = new HttpClient(Handler);

    public NexoraSmsClient Client(Func<NexoraSmsClientBuilder, NexoraSmsClientBuilder>? configure = null)
    {
        var builder = NexoraSmsClient.Builder()
            .PublicKey(PublicKey)
            .ApiKey(ApiKey)
            .BaseUrl(BaseUrl + "/")
            .HttpClient(Http)
            .TimeProvider(Time);
        return (configure?.Invoke(builder) ?? builder).Build();
    }

    public List<RecordedRequest> Seen => Handler.Seen;

    public void Dispose() => Http.Dispose();
}
