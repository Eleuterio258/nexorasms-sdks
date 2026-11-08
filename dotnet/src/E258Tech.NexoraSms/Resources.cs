using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Threading;
using System.Threading.Tasks;

namespace E258Tech.NexoraSms;

/// <summary>Direct sends, estimates and message status (<c>/api/v1/messages</c>).</summary>
public sealed class MessagesResource
{
    private readonly NexoraSmsClient _client;

    internal MessagesResource(NexoraSmsClient client) => _client = client;

    /// <summary>
    /// Sends now (one campaign per channel). Uses the request's Idempotency-Key, or generates a UUID v4 once per call;
    /// the same key is reused on every retry of this call, so a retried send is never sent twice.
    /// </summary>
    /// <exception cref="InsufficientFundsException">Not enough balance (402); nothing was sent.</exception>
    /// <exception cref="NexoraSmsException">Any other API or network failure.</exception>
    public async Task<SendMessageResult> SendAsync(SendMessageRequest request, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        var idempotencyKey = request.IdempotencyKey ?? Guid.NewGuid().ToString("D");
        var response = await _client.SendAsync<SendMessageResult>(HttpMethod.Post, "/api/v1/messages", null, request.ToBody(),
            idempotencyKey, retryable: true, cancellationToken).ConfigureAwait(false);
        return response.Value with { IdempotencyKey = idempotencyKey, Replayed = response.Replayed };
    }

    /// <summary>Cost, segments and available balance for a send. Sends nothing.</summary>
    public async Task<EstimateResult> EstimateAsync(SendMessageRequest request, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        return (await _client.SendAsync<EstimateResult>(HttpMethod.Post, "/api/v1/messages/estimate", null, request.ToBody(),
            null, retryable: true, cancellationToken).ConfigureAwait(false)).Value;
    }

    /// <summary>One message, with its delivery attempts.</summary>
    public async Task<Message> GetAsync(string id, CancellationToken cancellationToken = default) =>
        (await _client.SendAsync<Message>(HttpMethod.Get, "/api/v1/messages/" + NexoraSmsClient.Segment(id, nameof(id)), null, null,
            null, retryable: true, cancellationToken).ConfigureAwait(false)).Value;

    /// <summary>Lists messages, optionally filtered.</summary>
    public async Task<Page<Message>> ListAsync(MessageQuery? query = null, CancellationToken cancellationToken = default) =>
        (await _client.SendAsync<Page<Message>>(HttpMethod.Get, "/api/v1/messages", query?.ToQuery(), null,
            null, retryable: true, cancellationToken).ConfigureAwait(false)).Value;
}

/// <summary>Contacts and consents (<c>/api/v1/contacts</c>).</summary>
public sealed class ContactsResource
{
    private readonly NexoraSmsClient _client;

    internal ContactsResource(NexoraSmsClient client) => _client = client;

    /// <summary>Creates or updates the contact with this id from your system (<c>PUT</c>, safe to retry).</summary>
    public async Task<Contact> UpsertByExternalIdAsync(string externalId, ContactRequest request, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        var path = "/api/v1/contacts/external/" + NexoraSmsClient.Segment(externalId, nameof(externalId));
        return (await _client.SendAsync<Contact>(HttpMethod.Put, path, null, request, null, retryable: true, cancellationToken)
            .ConfigureAwait(false)).Value;
    }

    /// <summary>Creates a contact. Not retried after network errors (it is not idempotent).</summary>
    public async Task<Contact> CreateAsync(ContactRequest request, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        return (await _client.SendAsync<Contact>(HttpMethod.Post, "/api/v1/contacts", null, request, null, retryable: false, cancellationToken)
            .ConfigureAwait(false)).Value;
    }

    /// <summary>One contact.</summary>
    public async Task<Contact> GetAsync(string id, CancellationToken cancellationToken = default) =>
        (await _client.SendAsync<Contact>(HttpMethod.Get, "/api/v1/contacts/" + NexoraSmsClient.Segment(id, nameof(id)), null, null,
            null, retryable: true, cancellationToken).ConfigureAwait(false)).Value;

    /// <summary>Updates only the fields set in the request (<c>PATCH</c>). Not retried.</summary>
    public async Task<Contact> UpdateAsync(string id, ContactRequest request, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        var path = "/api/v1/contacts/" + NexoraSmsClient.Segment(id, nameof(id));
        return (await _client.SendAsync<Contact>(HttpMethod.Patch, path, null, request, null, retryable: false, cancellationToken)
            .ConfigureAwait(false)).Value;
    }

    /// <summary>Lists contacts, optionally filtered.</summary>
    public async Task<Page<Contact>> ListAsync(ContactQuery? query = null, CancellationToken cancellationToken = default) =>
        (await _client.SendAsync<Page<Contact>>(HttpMethod.Get, "/api/v1/contacts", query?.ToQuery(), null,
            null, retryable: true, cancellationToken).ConfigureAwait(false)).Value;

    /// <summary>Records a consent (granted or revoked) for one channel. Not retried.</summary>
    /// <param name="id">Contact id.</param>
    /// <param name="channel">Channel.</param>
    /// <param name="status">Granted or revoked.</param>
    /// <param name="source">Where it came from (e.g. <c>erp</c>).</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    /// <exception cref="ArgumentException">An <c>Unrecognized</c> value or an empty source.</exception>
    public async Task<Contact> RecordConsentAsync(string id, Channel channel, ConsentStatus status, string source,
        CancellationToken cancellationToken = default)
    {
        ApiEnum.RequireKnown(channel, nameof(channel));
        ApiEnum.RequireKnown(status, nameof(status));
        if (string.IsNullOrWhiteSpace(source))
            throw new ArgumentException("Indique a origem do consentimento.", nameof(source));
        var path = "/api/v1/contacts/" + NexoraSmsClient.Segment(id, nameof(id)) + "/consents";
        var body = new ConsentBody(channel, status, source);
        return (await _client.SendAsync<Contact>(HttpMethod.Post, path, null, body, null, retryable: false, cancellationToken)
            .ConfigureAwait(false)).Value;
    }

    private sealed record ConsentBody(Channel Channel, ConsentStatus Status, string Source);
}

/// <summary>Wallet balance and transactions (<c>/api/v1/wallet</c>).</summary>
public sealed class WalletResource
{
    private readonly NexoraSmsClient _client;

    internal WalletResource(NexoraSmsClient client) => _client = client;

    /// <summary>Current balance.</summary>
    public async Task<Wallet> GetAsync(CancellationToken cancellationToken = default) =>
        (await _client.SendAsync<Wallet>(HttpMethod.Get, "/api/v1/wallet", null, null, null, retryable: true, cancellationToken)
            .ConfigureAwait(false)).Value;

    /// <summary>Transactions, newest first.</summary>
    /// <exception cref="ArgumentOutOfRangeException">page &lt; 1 or pageSize outside 1–200.</exception>
    public async Task<Page<WalletTransaction>> TransactionsAsync(int? page = null, int? pageSize = null,
        CancellationToken cancellationToken = default)
    {
        if (page is { } p)
            QueryValues.CheckPage(p, nameof(page));
        if (pageSize is { } s)
            QueryValues.CheckPageSize(s, nameof(pageSize));
        var query = new KeyValuePair<string, string?>[]
        {
            new("page", QueryValues.Int(page)),
            new("page_size", QueryValues.Int(pageSize)),
        };
        return (await _client.SendAsync<Page<WalletTransaction>>(HttpMethod.Get, "/api/v1/wallet/transactions", query, null,
            null, retryable: true, cancellationToken).ConfigureAwait(false)).Value;
    }
}

/// <summary>Campaigns (<c>/api/v1/campaigns</c>).</summary>
public sealed class CampaignsResource
{
    private readonly NexoraSmsClient _client;

    internal CampaignsResource(NexoraSmsClient client) => _client = client;

    /// <summary>One campaign.</summary>
    public async Task<Campaign> GetAsync(string id, CancellationToken cancellationToken = default) =>
        (await _client.SendAsync<Campaign>(HttpMethod.Get, "/api/v1/campaigns/" + NexoraSmsClient.Segment(id, nameof(id)), null, null,
            null, retryable: true, cancellationToken).ConfigureAwait(false)).Value;

    /// <summary>Statistics of a campaign.</summary>
    public async Task<CampaignStats> StatsAsync(string id, CancellationToken cancellationToken = default) =>
        (await _client.SendAsync<CampaignStats>(HttpMethod.Get, "/api/v1/campaigns/" + NexoraSmsClient.Segment(id, nameof(id)) + "/stats",
            null, null, null, retryable: true, cancellationToken).ConfigureAwait(false)).Value;
}
