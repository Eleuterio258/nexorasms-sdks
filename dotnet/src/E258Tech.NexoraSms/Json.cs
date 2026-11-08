using System;
using System.Collections.Generic;
using System.Reflection;
using System.Runtime.Serialization;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace E258Tech.NexoraSms;

/// <summary>Shared JSON settings: snake_case properties, null fields omitted, unknown fields ignored.</summary>
internal static class NexoraJson
{
    public static readonly JsonSerializerOptions Options = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
        NumberHandling = JsonNumberHandling.AllowReadingFromString,
    };
}

/// <summary>
/// Reads and writes an enum as the API's lower-case snake_case text (<c>InFlight</c> ↔ <c>"in_flight"</c>),
/// or the <see cref="EnumMemberAttribute.Value"/> when set. Values the SDK does not know become the member
/// named <c>Unrecognized</c> instead of failing, so new API values never break deserialization.
/// </summary>
internal sealed class TolerantEnumConverter<TEnum> : JsonConverter<TEnum> where TEnum : struct, Enum
{
    private const string FallbackName = "Unrecognized";
    private static readonly Dictionary<string, TEnum> FromApi = new(StringComparer.OrdinalIgnoreCase);
    private static readonly Dictionary<TEnum, string> ToApi = new();
    private static readonly TEnum Fallback;

    static TolerantEnumConverter()
    {
        foreach (var field in typeof(TEnum).GetFields(BindingFlags.Public | BindingFlags.Static))
        {
            var value = (TEnum)field.GetValue(null)!;
            if (field.Name == FallbackName)
            {
                Fallback = value;
                continue;
            }
            var name = field.GetCustomAttribute<EnumMemberAttribute>()?.Value ?? SnakeCase(field.Name);
            FromApi[name] = value;
            ToApi[value] = name;
        }
    }

    public override TEnum Read(ref Utf8JsonReader reader, Type typeToConvert, JsonSerializerOptions options)
    {
        if (reader.TokenType == JsonTokenType.String && FromApi.TryGetValue(reader.GetString()!, out var value))
            return value;
        if (reader.TokenType is JsonTokenType.StartObject or JsonTokenType.StartArray)
            reader.Skip();
        return Fallback;
    }

    public override void Write(Utf8JsonWriter writer, TEnum value, JsonSerializerOptions options) =>
        writer.WriteStringValue(ToApiValue(value));

    /// <summary>The API text for a value; throws for <c>Unrecognized</c> or undefined values.</summary>
    public static string ToApiValue(TEnum value) =>
        ToApi.TryGetValue(value, out var name)
            ? name
            : throw new ArgumentException($"Valor {typeof(TEnum).Name}.{value} não pode ser enviado à API.", nameof(value));

    /// <summary>True when the value has an API text (i.e. it is not <c>Unrecognized</c>).</summary>
    public static bool IsKnown(TEnum value) => ToApi.ContainsKey(value);

    private static string SnakeCase(string name)
    {
        var builder = new StringBuilder(name.Length + 4);
        for (var i = 0; i < name.Length; i++)
        {
            var c = name[i];
            if (char.IsUpper(c))
            {
                if (i > 0 && (char.IsLower(name[i - 1]) || char.IsDigit(name[i - 1])))
                    builder.Append('_');
                builder.Append(char.ToLowerInvariant(c));
            }
            else
            {
                builder.Append(c);
            }
        }
        return builder.ToString();
    }
}

/// <summary>Helpers to turn enums into API text.</summary>
internal static class ApiEnum
{
    public static string Text<TEnum>(TEnum value) where TEnum : struct, Enum => TolerantEnumConverter<TEnum>.ToApiValue(value);

    public static TEnum RequireKnown<TEnum>(TEnum value, string parameterName) where TEnum : struct, Enum =>
        TolerantEnumConverter<TEnum>.IsKnown(value)
            ? value
            : throw new ArgumentException($"Valor {typeof(TEnum).Name}.{value} não é válido.", parameterName);
}
