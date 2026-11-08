using System.Text.Json;
using System.Text.Json.Nodes;

namespace E258Tech.NexoraSms.Tests;

public sealed class RequestBuilderTests
{
    private static SendMessageRequestBuilder B() => SendMessageRequest.Builder();

    private static string Fails(Func<SendMessageRequestBuilder> build) =>
        Assert.Throws<InvalidOperationException>(() => build().Build()).Message;

    private static JsonNode? BodyOf(SendMessageRequest request) =>
        JsonNode.Parse(JsonSerializer.Serialize(request.ToBody(), NexoraJson.Options));

    [Fact]
    public void SendMessageRequest_valida_canais_destinatarios_e_conteudo()
    {
        Assert.Contains("entre 1 e 3", Fails(() => B().To("+258841234567")));
        Assert.Contains("entre 1 e 3", Fails(() => B().Sms("a").Email("s", "c").WhatsApp("w").Sms("b").To("x")));
        Assert.Contains("uma vez", Fails(() => B().Sms("a").Sms("b").To("x")));
        Assert.Contains("destinatário", Fails(() => B().Sms("a")));
        Assert.Contains("SMS", Fails(() => B().Sms("  ").To("x")));
        Assert.Contains("assunto", Fails(() => B().Email("", "x").To("x")));
        Assert.Contains("conteúdo", Fails(() => B().Email("Assunto", " ").To("x")));
        Assert.Contains("WhatsApp", Fails(() => B().WhatsApp("").To("x")));
        Assert.Contains("nome e idioma", Fails(() => B().WhatsAppTemplate("aviso", "").To("x")));
        Assert.Contains("1000", Fails(() => B().Sms("a").To(Enumerable.Range(0, 1001).Select(i => $"+2588400{i}"))));
        Assert.Contains("1000", Fails(() => B().Sms("a").Contacts(Enumerable.Range(0, 1001).Select(i => $"k{i}"))));
        Assert.Throws<ArgumentException>(() => B().To("a", null!));
        Assert.Throws<ArgumentNullException>(() => B().Contacts((IEnumerable<string>)null!));

        // Phone format is the API's job: anything goes through.
        var ok = B().Sms("a").To(Enumerable.Range(0, 1000).Select(i => $"x{i}")).Build();
        Assert.Equal(1000, ok.Recipients.Count);
    }

    [Fact]
    public void IdempotencyKey_aceita_so_1_a_200_caracteres_ASCII_visiveis()
    {
        Assert.Throws<ArgumentException>(() => B().IdempotencyKey("com espaço"));
        Assert.Throws<ArgumentException>(() => B().IdempotencyKey(""));
        Assert.Throws<ArgumentException>(() => B().IdempotencyKey("fatura-ç"));
        Assert.Throws<ArgumentException>(() => B().IdempotencyKey(new string('x', 201)));
        Assert.Throws<ArgumentException>(() => B().IdempotencyKey(null!));
        var request = B().Sms("a").To("x").IdempotencyKey(new string('~', 200)).Build();
        Assert.Equal(200, request.IdempotencyKey!.Length);
        Assert.Equal("!FT/2026:1", B().Sms("a").To("x").IdempotencyKey("!FT/2026:1").Build().IdempotencyKey);
    }

    [Fact]
    public void WhatsApp_com_template_e_corpo_sem_campos_nulos()
    {
        var request = B().WhatsAppTemplate("aviso", "pt_PT", "Ana", "FT 1").Contacts("k1", "k2").Build();
        Assert.True(JsonNode.DeepEquals(JsonNode.Parse("""
            { "channels": [ { "channel": "whatsapp", "whatsapp_template": { "name": "aviso", "language": "pt_PT", "parameters": ["Ana", "FT 1"] } } ],
              "contact_ids": ["k1", "k2"] }
            """), BodyOf(request)));
        Assert.Empty(request.Recipients);
        Assert.Null(request.IgnoreQuietHours);
        Assert.Null(request.IdempotencyKey);

        var withText = B().WhatsAppTemplate("aviso", "pt_PT", new[] { "Ana" }, "Olá Ana").To("+258841234567").IgnoreQuietHours(false).Build();
        Assert.True(JsonNode.DeepEquals(JsonNode.Parse("""
            { "channels": [ { "channel": "whatsapp", "content": "Olá Ana", "whatsapp_template": { "name": "aviso", "language": "pt_PT", "parameters": ["Ana"] } } ],
              "recipients": ["+258841234567"], "ignore_quiet_hours": false }
            """), BodyOf(withText)));
    }

    [Fact]
    public void Pedido_construido_e_imutavel()
    {
        var builder = B().Sms("a").To("x");
        var request = builder.Build();
        builder.To("y").Email("s", "c");
        Assert.Single(request.Recipients);
        Assert.Single(request.Channels);
        Assert.Throws<NotSupportedException>(() => ((IList<string>)request.Recipients).Add("z"));
    }

    [Fact]
    public void ContactRequest_so_inclui_os_campos_definidos()
    {
        var empty = ContactRequest.Builder().Build();
        Assert.Equal("{}", JsonSerializer.Serialize(empty, NexoraJson.Options));

        var full = ContactRequest.Builder()
            .Name("Ana").Phone("+258841234567").Email("ana@x.co.mz").Status(ContactStatus.Active).ExternalId("CLI-1")
            .GroupId("g1").GroupIds("g2", "g3").Tag("vip").Tags(new List<string> { "maputo" })
            .Attributes(new Dictionary<string, string?> { ["plano"] = "premium", ["velho"] = null })
            .Build();
        Assert.True(JsonNode.DeepEquals(JsonNode.Parse("""
            { "name": "Ana", "phone": "+258841234567", "email": "ana@x.co.mz", "status": "active",
              "group_ids": ["g1", "g2", "g3"], "tags": ["vip", "maputo"],
              "attributes": { "plano": "premium", "velho": null }, "external_id": "CLI-1" }
            """), JsonNode.Parse(JsonSerializer.Serialize(full, NexoraJson.Options))));

        Assert.Throws<ArgumentException>(() => ContactRequest.Builder().Status(ContactStatus.Unrecognized));
        Assert.Throws<ArgumentException>(() => ContactRequest.Builder().Attribute(" ", "x"));
        Assert.Throws<ArgumentException>(() => ContactRequest.Builder().Tags("a", null!));
    }

    [Fact]
    public void Consultas_validam_paginas()
    {
        Assert.Throws<ArgumentOutOfRangeException>(() => MessageQuery.Builder().PageSize(500));
        Assert.Throws<ArgumentOutOfRangeException>(() => MessageQuery.Builder().PageSize(0));
        Assert.Throws<ArgumentOutOfRangeException>(() => MessageQuery.Builder().Page(0));
        Assert.Throws<ArgumentOutOfRangeException>(() => ContactQuery.Builder().PageSize(201));
        Assert.Throws<ArgumentOutOfRangeException>(() => ContactQuery.Builder().Page(-1));
        Assert.Throws<ArgumentException>(() => MessageQuery.Builder().Status(MessageStatus.Unrecognized));
        Assert.Throws<ArgumentException>(() => MessageQuery.Builder().Channel(Channel.Unrecognized));

        var query = ContactQuery.Builder().Search("ana").Page(1).PageSize(200).Build();
        Assert.Equal("ana", query.Search);
        Assert.Equal(200, query.PageSize);
        Assert.Null(query.Tag);
    }
}
