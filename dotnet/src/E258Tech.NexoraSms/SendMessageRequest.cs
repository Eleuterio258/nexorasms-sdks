using System;
using System.Collections.Generic;
using System.Linq;
using System.Text.Json.Serialization;

namespace E258Tech.NexoraSms;

/// <summary>
/// A direct send (body of <c>POST /api/v1/messages</c> and <c>/estimate</c>): one to three channels, to recipients
/// and/or existing contacts. Create it with <see cref="Builder"/>; instances are immutable.
/// </summary>
public sealed class SendMessageRequest
{
    internal SendMessageRequest(IReadOnlyList<ChannelContent> channels, IReadOnlyList<string> recipients,
        IReadOnlyList<string> contactIds, bool? ignoreQuietHours, string? idempotencyKey)
    {
        Channels = channels;
        Recipients = recipients;
        ContactIds = contactIds;
        IgnoreQuietHours = ignoreQuietHours;
        IdempotencyKey = idempotencyKey;
    }

    /// <summary>Starts a new request.</summary>
    public static SendMessageRequestBuilder Builder() => new();

    /// <summary>Channels and their content.</summary>
    public IReadOnlyList<ChannelContent> Channels { get; }

    /// <summary>E.164 phones and/or email addresses.</summary>
    public IReadOnlyList<string> Recipients { get; }

    /// <summary>Existing contact ids.</summary>
    public IReadOnlyList<string> ContactIds { get; }

    /// <summary>Send even during quiet hours (<c>null</c> = API default).</summary>
    public bool? IgnoreQuietHours { get; }

    /// <summary>Idempotency-Key for <see cref="MessagesResource.SendAsync"/>; when <c>null</c> the SDK generates one per call.</summary>
    public string? IdempotencyKey { get; }

    internal Body ToBody() => new(
        Channels,
        Recipients.Count > 0 ? Recipients : null,
        ContactIds.Count > 0 ? ContactIds : null,
        IgnoreQuietHours);

    internal sealed record Body(
        IReadOnlyList<ChannelContent> Channels,
        IReadOnlyList<string>? Recipients,
        IReadOnlyList<string>? ContactIds,
        bool? IgnoreQuietHours);
}

/// <summary>Content of one channel of a send.</summary>
public sealed record ChannelContent
{
    /// <summary>Channel.</summary>
    public Channel Channel { get; init; }

    /// <summary>Email subject.</summary>
    public string? Subject { get; init; }

    /// <summary>Text (SMS, WhatsApp) or HTML (email).</summary>
    public string? Content { get; init; }

    /// <summary>Approved Sender ID (SMS) or sender address at a verified domain (email).</summary>
    public string? Sender { get; init; }

    /// <summary>WhatsApp template approved by Meta.</summary>
    [JsonPropertyName("whatsapp_template")]
    public WhatsAppTemplate? WhatsAppTemplate { get; init; }
}

/// <summary>A WhatsApp template approved by Meta.</summary>
/// <param name="Name">Template name.</param>
/// <param name="Language">Language code (e.g. <c>pt_PT</c>).</param>
/// <param name="Parameters">Values for <c>{{1}}</c>, <c>{{2}}</c>… in order.</param>
public sealed record WhatsAppTemplate(string Name, string Language, IReadOnlyList<string> Parameters);

/// <summary>Builds a <see cref="SendMessageRequest"/>. Not thread-safe; <see cref="Build"/> validates.</summary>
public sealed class SendMessageRequestBuilder
{
    internal const int MaxList = 1000;

    private readonly List<ChannelContent> _channels = new();
    private readonly List<string> _recipients = new();
    private readonly List<string> _contactIds = new();
    private bool? _ignoreQuietHours;
    private string? _idempotencyKey;

    internal SendMessageRequestBuilder() { }

    /// <summary>Adds an SMS, optionally with an approved Sender ID.</summary>
    public SendMessageRequestBuilder Sms(string content, string? sender = null)
    {
        _channels.Add(new ChannelContent { Channel = Channel.Sms, Content = content, Sender = sender });
        return this;
    }

    /// <summary>Adds an email (HTML content), optionally with a sender at a verified domain.</summary>
    public SendMessageRequestBuilder Email(string subject, string content, string? sender = null)
    {
        _channels.Add(new ChannelContent { Channel = Channel.Email, Subject = subject, Content = content, Sender = sender });
        return this;
    }

    /// <summary>Adds WhatsApp free text (accepted only within 24 h of the customer's last message).</summary>
    public SendMessageRequestBuilder WhatsApp(string content)
    {
        _channels.Add(new ChannelContent { Channel = Channel.WhatsApp, Content = content });
        return this;
    }

    /// <summary>Adds WhatsApp with a template approved by Meta; parameters fill <c>{{1}}</c>, <c>{{2}}</c>… in order.</summary>
    public SendMessageRequestBuilder WhatsAppTemplate(string name, string language, params string[] parameters) =>
        WhatsAppTemplate(name, language, (IEnumerable<string>)parameters);

    /// <summary>Adds WhatsApp with a template approved by Meta, with optional text content.</summary>
    public SendMessageRequestBuilder WhatsAppTemplate(string name, string language, IEnumerable<string> parameters, string? content = null)
    {
        ArgumentNullException.ThrowIfNull(parameters);
        var values = parameters.ToList();
        if (values.Any(p => p is null))
            throw new ArgumentException("Os parâmetros do template não podem ser nulos.", nameof(parameters));
        _channels.Add(new ChannelContent
        {
            Channel = Channel.WhatsApp,
            Content = content,
            WhatsAppTemplate = new WhatsAppTemplate(name, language, values.AsReadOnly()),
        });
        return this;
    }

    /// <summary>Adds E.164 phones and/or email addresses (accumulates). Phone format is validated by the API.</summary>
    public SendMessageRequestBuilder To(params string[] recipients) => To((IEnumerable<string>)recipients);

    /// <summary>Adds E.164 phones and/or email addresses (accumulates).</summary>
    public SendMessageRequestBuilder To(IEnumerable<string> recipients)
    {
        AddAll(_recipients, recipients, nameof(recipients));
        return this;
    }

    /// <summary>Adds one existing contact id (accumulates).</summary>
    public SendMessageRequestBuilder Contact(string id) => Contacts(id);

    /// <summary>Adds existing contact ids (accumulates).</summary>
    public SendMessageRequestBuilder Contacts(params string[] ids) => Contacts((IEnumerable<string>)ids);

    /// <summary>Adds existing contact ids (accumulates).</summary>
    public SendMessageRequestBuilder Contacts(IEnumerable<string> ids)
    {
        AddAll(_contactIds, ids, nameof(ids));
        return this;
    }

    /// <summary>Sends even during the company's quiet hours.</summary>
    public SendMessageRequestBuilder IgnoreQuietHours(bool value = true)
    {
        _ignoreQuietHours = value;
        return this;
    }

    /// <summary>Your own Idempotency-Key (1 to 200 visible ASCII characters), e.g. the invoice number.</summary>
    /// <exception cref="ArgumentException">The key is empty, too long or has characters outside 0x21–0x7E.</exception>
    public SendMessageRequestBuilder IdempotencyKey(string key)
    {
        _idempotencyKey = CheckIdempotencyKey(key);
        return this;
    }

    /// <summary>Validates and creates the request.</summary>
    /// <exception cref="InvalidOperationException">The request is not valid (message says why).</exception>
    public SendMessageRequest Build()
    {
        if (_channels.Count is < 1 or > 3)
            throw new InvalidOperationException("Indique entre 1 e 3 canais.");
        if (_channels.Select(c => c.Channel).Distinct().Count() != _channels.Count)
            throw new InvalidOperationException("Cada canal só pode aparecer uma vez.");
        foreach (var c in _channels)
        {
            switch (c.Channel)
            {
                case Channel.Sms when IsBlank(c.Content):
                    throw new InvalidOperationException("O SMS precisa de conteúdo.");
                case Channel.Email when IsBlank(c.Subject):
                    throw new InvalidOperationException("O email precisa de assunto.");
                case Channel.Email when IsBlank(c.Content):
                    throw new InvalidOperationException("O email precisa de conteúdo.");
                case Channel.WhatsApp when IsBlank(c.Content) && c.WhatsAppTemplate is null:
                    throw new InvalidOperationException("O WhatsApp precisa de conteúdo ou de um template.");
            }
            if (c.WhatsAppTemplate is { } template && (IsBlank(template.Name) || IsBlank(template.Language)))
                throw new InvalidOperationException("O template do WhatsApp precisa de nome e idioma.");
        }
        if (_recipients.Count == 0 && _contactIds.Count == 0)
            throw new InvalidOperationException("Indique pelo menos um destinatário ou contacto.");
        if (_recipients.Count > MaxList)
            throw new InvalidOperationException($"No máximo {MaxList} destinatários.");
        if (_contactIds.Count > MaxList)
            throw new InvalidOperationException($"No máximo {MaxList} contactos.");

        return new SendMessageRequest(
            _channels.ToList().AsReadOnly(),
            _recipients.ToList().AsReadOnly(),
            _contactIds.ToList().AsReadOnly(),
            _ignoreQuietHours,
            _idempotencyKey);
    }

    internal static string CheckIdempotencyKey(string key)
    {
        if (key is null || key.Length is < 1 or > 200 || key.Any(ch => ch is < '\x21' or > '\x7E'))
            throw new ArgumentException("idempotencyKey deve ter de 1 a 200 caracteres ASCII visíveis.", nameof(key));
        return key;
    }

    private static bool IsBlank(string? value) => string.IsNullOrWhiteSpace(value);

    private static void AddAll(List<string> target, IEnumerable<string> values, string parameterName)
    {
        ArgumentNullException.ThrowIfNull(values, parameterName);
        var list = values.ToList();
        if (list.Any(v => v is null))
            throw new ArgumentException("A lista não pode conter valores nulos.", parameterName);
        target.AddRange(list);
    }
}
