using System;
using System.Collections.Generic;
using System.Globalization;

namespace E258Tech.NexoraSms;

/// <summary>Filters for <see cref="MessagesResource.ListAsync"/>. Create it with <see cref="Builder"/>.</summary>
public sealed class MessageQuery
{
    internal MessageQuery(string? campaignId, MessageStatus? status, Channel? channel, string? search, int? page, int? pageSize)
    {
        CampaignId = campaignId;
        Status = status;
        Channel = channel;
        Search = search;
        Page = page;
        PageSize = pageSize;
    }

    /// <summary>Starts a new query.</summary>
    public static MessageQueryBuilder Builder() => new();

    /// <summary>Only messages of this campaign.</summary>
    public string? CampaignId { get; }

    /// <summary>Only messages with this status.</summary>
    public MessageStatus? Status { get; }

    /// <summary>Only messages of this channel.</summary>
    public Channel? Channel { get; }

    /// <summary>Text search (destination, content…).</summary>
    public string? Search { get; }

    /// <summary>Page number (from 1).</summary>
    public int? Page { get; }

    /// <summary>Page size (1–200).</summary>
    public int? PageSize { get; }

    internal IEnumerable<KeyValuePair<string, string?>> ToQuery()
    {
        yield return new("campaign_id", CampaignId);
        yield return new("status", Status is { } s ? ApiEnum.Text(s) : null);
        yield return new("channel", Channel is { } c ? ApiEnum.Text(c) : null);
        yield return new("search", Search);
        yield return new("page", QueryValues.Int(Page));
        yield return new("page_size", QueryValues.Int(PageSize));
    }
}

/// <summary>Builds a <see cref="MessageQuery"/>.</summary>
public sealed class MessageQueryBuilder
{
    private string? _campaignId;
    private MessageStatus? _status;
    private Channel? _channel;
    private string? _search;
    private int? _page;
    private int? _pageSize;

    internal MessageQueryBuilder() { }

    /// <summary>Only messages of this campaign (e.g. the campaign of a direct send).</summary>
    public MessageQueryBuilder CampaignId(string campaignId) { _campaignId = campaignId; return this; }

    /// <summary>Only messages with this status.</summary>
    public MessageQueryBuilder Status(MessageStatus status) { _status = ApiEnum.RequireKnown(status, nameof(status)); return this; }

    /// <summary>Only messages of this channel.</summary>
    public MessageQueryBuilder Channel(Channel channel) { _channel = ApiEnum.RequireKnown(channel, nameof(channel)); return this; }

    /// <summary>Text search.</summary>
    public MessageQueryBuilder Search(string search) { _search = search; return this; }

    /// <summary>Page number (from 1).</summary>
    /// <exception cref="ArgumentOutOfRangeException">Less than 1.</exception>
    public MessageQueryBuilder Page(int page) { _page = QueryValues.CheckPage(page); return this; }

    /// <summary>Page size (1–200).</summary>
    /// <exception cref="ArgumentOutOfRangeException">Outside 1–200.</exception>
    public MessageQueryBuilder PageSize(int pageSize) { _pageSize = QueryValues.CheckPageSize(pageSize); return this; }

    /// <summary>Creates the query.</summary>
    public MessageQuery Build() => new(_campaignId, _status, _channel, _search, _page, _pageSize);
}

/// <summary>Filters for <see cref="ContactsResource.ListAsync"/>. Create it with <see cref="Builder"/>.</summary>
public sealed class ContactQuery
{
    internal ContactQuery(string? search, string? groupId, string? tag, ContactStatus? status, int? page, int? pageSize)
    {
        Search = search;
        GroupId = groupId;
        Tag = tag;
        Status = status;
        Page = page;
        PageSize = pageSize;
    }

    /// <summary>Starts a new query.</summary>
    public static ContactQueryBuilder Builder() => new();

    /// <summary>Text search (name, phone, email).</summary>
    public string? Search { get; }

    /// <summary>Only contacts in this group.</summary>
    public string? GroupId { get; }

    /// <summary>Only contacts with this tag.</summary>
    public string? Tag { get; }

    /// <summary>Only contacts with this status.</summary>
    public ContactStatus? Status { get; }

    /// <summary>Page number (from 1).</summary>
    public int? Page { get; }

    /// <summary>Page size (1–200).</summary>
    public int? PageSize { get; }

    internal IEnumerable<KeyValuePair<string, string?>> ToQuery()
    {
        yield return new("search", Search);
        yield return new("group_id", GroupId);
        yield return new("tag", Tag);
        yield return new("status", Status is { } s ? ApiEnum.Text(s) : null);
        yield return new("page", QueryValues.Int(Page));
        yield return new("page_size", QueryValues.Int(PageSize));
    }
}

/// <summary>Builds a <see cref="ContactQuery"/>.</summary>
public sealed class ContactQueryBuilder
{
    private string? _search;
    private string? _groupId;
    private string? _tag;
    private ContactStatus? _status;
    private int? _page;
    private int? _pageSize;

    internal ContactQueryBuilder() { }

    /// <summary>Text search.</summary>
    public ContactQueryBuilder Search(string search) { _search = search; return this; }

    /// <summary>Only contacts in this group.</summary>
    public ContactQueryBuilder GroupId(string groupId) { _groupId = groupId; return this; }

    /// <summary>Only contacts with this tag.</summary>
    public ContactQueryBuilder Tag(string tag) { _tag = tag; return this; }

    /// <summary>Only contacts with this status.</summary>
    public ContactQueryBuilder Status(ContactStatus status) { _status = ApiEnum.RequireKnown(status, nameof(status)); return this; }

    /// <summary>Page number (from 1).</summary>
    /// <exception cref="ArgumentOutOfRangeException">Less than 1.</exception>
    public ContactQueryBuilder Page(int page) { _page = QueryValues.CheckPage(page); return this; }

    /// <summary>Page size (1–200).</summary>
    /// <exception cref="ArgumentOutOfRangeException">Outside 1–200.</exception>
    public ContactQueryBuilder PageSize(int pageSize) { _pageSize = QueryValues.CheckPageSize(pageSize); return this; }

    /// <summary>Creates the query.</summary>
    public ContactQuery Build() => new(_search, _groupId, _tag, _status, _page, _pageSize);
}

internal static class QueryValues
{
    public static string? Int(int? value) => value?.ToString(CultureInfo.InvariantCulture);

    public static int CheckPage(int page, string parameterName = "page") =>
        page >= 1 ? page : throw new ArgumentOutOfRangeException(parameterName, page, "page deve ser um inteiro a partir de 1.");

    public static int CheckPageSize(int pageSize, string parameterName = "pageSize") =>
        pageSize is >= 1 and <= 200
            ? pageSize
            : throw new ArgumentOutOfRangeException(parameterName, pageSize, "pageSize deve ser um inteiro entre 1 e 200.");
}
