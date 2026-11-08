using System.Runtime.Serialization;
using System.Text.Json.Serialization;

namespace E258Tech.NexoraSms;

// Every enum has Unrecognized = 0: values added to the API later are read as Unrecognized instead of failing.
// "Unknown" is not used for that because it is a real state of messages and attempts.

/// <summary>Delivery channel.</summary>
[JsonConverter(typeof(TolerantEnumConverter<Channel>))]
public enum Channel
{
    /// <summary>A value this SDK version does not know.</summary>
    Unrecognized = 0,
    /// <summary><c>sms</c>.</summary>
    Sms,
    /// <summary><c>whatsapp</c>.</summary>
    [EnumMember(Value = "whatsapp")]
    WhatsApp,
    /// <summary><c>email</c>.</summary>
    Email,
}

/// <summary>Status of a message.</summary>
[JsonConverter(typeof(TolerantEnumConverter<MessageStatus>))]
public enum MessageStatus
{
    /// <summary>A value this SDK version does not know.</summary>
    Unrecognized = 0,
    /// <summary><c>pending</c>: queued (or waiting for quiet hours to end).</summary>
    Pending,
    /// <summary><c>processing</c>: being handed to the provider.</summary>
    Processing,
    /// <summary><c>accepted</c>: accepted by the provider, waiting for the delivery report.</summary>
    Accepted,
    /// <summary><c>delivered</c>: delivered. Final state.</summary>
    Delivered,
    /// <summary><c>failed</c>: failed. Final state; not charged.</summary>
    Failed,
    /// <summary><c>unknown</c>: no answer from the provider; the platform resolves it.</summary>
    Unknown,
    /// <summary><c>cancelled</c>: cancelled before sending. Final state.</summary>
    Cancelled,
}

/// <summary>Status of one delivery attempt to the provider.</summary>
[JsonConverter(typeof(TolerantEnumConverter<AttemptStatus>))]
public enum AttemptStatus
{
    /// <summary>A value this SDK version does not know.</summary>
    Unrecognized = 0,
    /// <summary><c>in_flight</c>.</summary>
    InFlight,
    /// <summary><c>accepted</c>.</summary>
    Accepted,
    /// <summary><c>rejected</c>.</summary>
    Rejected,
    /// <summary><c>failed</c>.</summary>
    Failed,
    /// <summary><c>unknown</c>.</summary>
    Unknown,
}

/// <summary>SMS text encoding used by an estimate.</summary>
[JsonConverter(typeof(TolerantEnumConverter<MessageEncoding>))]
public enum MessageEncoding
{
    /// <summary>A value this SDK version does not know.</summary>
    Unrecognized = 0,
    /// <summary><c>gsm7</c>.</summary>
    Gsm7,
    /// <summary><c>ucs2</c>.</summary>
    Ucs2,
    /// <summary><c>utf8</c>.</summary>
    Utf8,
}

/// <summary>Status of a contact.</summary>
[JsonConverter(typeof(TolerantEnumConverter<ContactStatus>))]
public enum ContactStatus
{
    /// <summary>A value this SDK version does not know.</summary>
    Unrecognized = 0,
    /// <summary><c>active</c>.</summary>
    Active,
    /// <summary><c>inactive</c>.</summary>
    Inactive,
}

/// <summary>Consent of a contact for one channel.</summary>
[JsonConverter(typeof(TolerantEnumConverter<ConsentStatus>))]
public enum ConsentStatus
{
    /// <summary>A value this SDK version does not know.</summary>
    Unrecognized = 0,
    /// <summary><c>granted</c>.</summary>
    Granted,
    /// <summary><c>revoked</c>: the contact receives nothing on that channel.</summary>
    Revoked,
}

/// <summary>Type of a wallet transaction.</summary>
[JsonConverter(typeof(TolerantEnumConverter<WalletTransactionType>))]
public enum WalletTransactionType
{
    /// <summary>A value this SDK version does not know.</summary>
    Unrecognized = 0,
    /// <summary><c>credit</c>.</summary>
    Credit,
    /// <summary><c>debit</c>.</summary>
    Debit,
    /// <summary><c>reserve</c>.</summary>
    Reserve,
    /// <summary><c>release</c>.</summary>
    Release,
    /// <summary><c>refund</c>.</summary>
    Refund,
    /// <summary><c>withdrawal</c>.</summary>
    Withdrawal,
}

/// <summary>Status of a campaign (a direct send creates one campaign per channel).</summary>
[JsonConverter(typeof(TolerantEnumConverter<CampaignStatus>))]
public enum CampaignStatus
{
    /// <summary>A value this SDK version does not know.</summary>
    Unrecognized = 0,
    /// <summary><c>draft</c>.</summary>
    Draft,
    /// <summary><c>scheduled</c>.</summary>
    Scheduled,
    /// <summary><c>running</c>.</summary>
    Running,
    /// <summary><c>paused</c>.</summary>
    Paused,
    /// <summary><c>completed</c>.</summary>
    Completed,
    /// <summary><c>cancelled</c>.</summary>
    Cancelled,
    /// <summary><c>failed</c>.</summary>
    Failed,
}
