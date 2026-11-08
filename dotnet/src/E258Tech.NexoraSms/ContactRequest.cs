using System;
using System.Collections.Generic;
using System.Collections.ObjectModel;
using System.Linq;

namespace E258Tech.NexoraSms;

/// <summary>
/// Body to create, upsert or update (PATCH) a contact. Only the fields set on the builder are sent: on update,
/// a field left out is not changed. Create it with <see cref="Builder"/>; instances are immutable.
/// </summary>
public sealed class ContactRequest
{
    internal ContactRequest(string? name, string? phone, string? email, ContactStatus? status, IReadOnlyList<string>? groupIds,
        IReadOnlyList<string>? tags, IReadOnlyDictionary<string, string?>? attributes, string? externalId)
    {
        Name = name;
        Phone = phone;
        Email = email;
        Status = status;
        GroupIds = groupIds;
        Tags = tags;
        Attributes = attributes;
        ExternalId = externalId;
    }

    /// <summary>Starts a new request.</summary>
    public static ContactRequestBuilder Builder() => new();

    /// <summary>Name, or <c>null</c> when not set.</summary>
    public string? Name { get; }

    /// <summary>Phone (without country code it is treated as Mozambican), or <c>null</c> when not set.</summary>
    public string? Phone { get; }

    /// <summary>Email, or <c>null</c> when not set.</summary>
    public string? Email { get; }

    /// <summary>Status (update only), or <c>null</c> when not set.</summary>
    public ContactStatus? Status { get; }

    /// <summary>Groups that replace the current ones; <c>null</c> = not sent, empty = clear.</summary>
    public IReadOnlyList<string>? GroupIds { get; }

    /// <summary>Tags that replace the current ones; <c>null</c> = not sent, empty = clear.</summary>
    public IReadOnlyList<string>? Tags { get; }

    /// <summary>Custom fields merged with the current ones; a <c>null</c> value removes the field.</summary>
    public IReadOnlyDictionary<string, string?>? Attributes { get; }

    /// <summary>Id of the contact in your system, or <c>null</c> when not set.</summary>
    public string? ExternalId { get; }
}

/// <summary>Builds a <see cref="ContactRequest"/>. Not thread-safe.</summary>
public sealed class ContactRequestBuilder
{
    private string? _name;
    private string? _phone;
    private string? _email;
    private ContactStatus? _status;
    private string? _externalId;
    private List<string>? _groupIds;
    private List<string>? _tags;
    private Dictionary<string, string?>? _attributes;

    internal ContactRequestBuilder() { }

    /// <summary>Name.</summary>
    public ContactRequestBuilder Name(string name) { _name = name; return this; }

    /// <summary>Phone (E.164, or national number treated as Mozambican).</summary>
    public ContactRequestBuilder Phone(string phone) { _phone = phone; return this; }

    /// <summary>Email.</summary>
    public ContactRequestBuilder Email(string email) { _email = email; return this; }

    /// <summary>Status: active or inactive (only meaningful on update).</summary>
    /// <exception cref="ArgumentException"><see cref="ContactStatus.Unrecognized"/> was given.</exception>
    public ContactRequestBuilder Status(ContactStatus status)
    {
        _status = ApiEnum.RequireKnown(status, nameof(status));
        return this;
    }

    /// <summary>Id of the contact in your system.</summary>
    public ContactRequestBuilder ExternalId(string externalId) { _externalId = externalId; return this; }

    /// <summary>Adds one group (the list replaces the contact's groups when sent).</summary>
    public ContactRequestBuilder GroupId(string id) => GroupIds(id);

    /// <summary>Adds groups (accumulates). Calling it with no ids sends an empty list, which clears the groups.</summary>
    public ContactRequestBuilder GroupIds(params string[] ids) => GroupIds((IEnumerable<string>)ids);

    /// <summary>Adds groups (accumulates). An empty sequence sends an empty list, which clears the groups.</summary>
    public ContactRequestBuilder GroupIds(IEnumerable<string> ids)
    {
        _groupIds ??= new List<string>();
        AddAll(_groupIds, ids, nameof(ids));
        return this;
    }

    /// <summary>Adds one tag (the list replaces the contact's tags when sent).</summary>
    public ContactRequestBuilder Tag(string tag) => Tags(tag);

    /// <summary>Adds tags (accumulates). Calling it with no tags sends an empty list, which clears the tags.</summary>
    public ContactRequestBuilder Tags(params string[] tags) => Tags((IEnumerable<string>)tags);

    /// <summary>Adds tags (accumulates). An empty sequence sends an empty list, which clears the tags.</summary>
    public ContactRequestBuilder Tags(IEnumerable<string> tags)
    {
        _tags ??= new List<string>();
        AddAll(_tags, tags, nameof(tags));
        return this;
    }

    /// <summary>Sets a custom field; <c>null</c> removes it on update.</summary>
    /// <exception cref="ArgumentException">The key is empty.</exception>
    public ContactRequestBuilder Attribute(string key, string? value)
    {
        if (string.IsNullOrWhiteSpace(key))
            throw new ArgumentException("O nome do atributo não pode estar vazio.", nameof(key));
        _attributes ??= new Dictionary<string, string?>();
        _attributes[key] = value;
        return this;
    }

    /// <summary>Sets several custom fields; <c>null</c> values remove them on update.</summary>
    public ContactRequestBuilder Attributes(IEnumerable<KeyValuePair<string, string?>> attributes)
    {
        ArgumentNullException.ThrowIfNull(attributes);
        foreach (var (key, value) in attributes)
            Attribute(key, value);
        return this;
    }

    /// <summary>Creates the request with the fields set so far.</summary>
    public ContactRequest Build() => new(
        _name,
        _phone,
        _email,
        _status,
        _groupIds?.ToList().AsReadOnly(),
        _tags?.ToList().AsReadOnly(),
        _attributes is null ? null : new ReadOnlyDictionary<string, string?>(new Dictionary<string, string?>(_attributes)),
        _externalId);

    private static void AddAll(List<string> target, IEnumerable<string> values, string parameterName)
    {
        ArgumentNullException.ThrowIfNull(values, parameterName);
        var list = values.ToList();
        if (list.Any(v => v is null))
            throw new ArgumentException("A lista não pode conter valores nulos.", parameterName);
        target.AddRange(list);
    }
}
