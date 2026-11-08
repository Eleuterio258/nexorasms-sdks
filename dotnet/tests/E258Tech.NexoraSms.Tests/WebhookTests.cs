using System.Security.Cryptography;
using System.Text;

namespace E258Tech.NexoraSms.Tests;

public sealed class WebhookTests
{
    private const string Secret = "whsec_test";
    private const string Body = """{"id":"evt_1","type":"ping","created_at":"2026-11-08T10:20:00Z","data":{"message":"ping"}}""";
    private const long T = 1762597200;
    private const string V1 = "cde593b98434dd7cd2944443131e9bf1bf47bc37630a621aa9481d76ab38b77e";
    private const string Header = "t=1762597200,v1=" + V1;

    private static WebhookVerifier Verifier(long nowSeconds = T, TimeSpan? tolerance = null) =>
        new(Secret, tolerance, new FakeTimeProvider(DateTimeOffset.FromUnixTimeSeconds(nowSeconds)));

    private static string Sign(string body, long t = T) =>
        Convert.ToHexString(HMACSHA256.HashData(Encoding.UTF8.GetBytes(Secret), Encoding.UTF8.GetBytes($"{t}.{body}"))).ToLowerInvariant();

    [Fact]
    public void Vector_fixo_valido()
    {
        Assert.Equal(V1, Sign(Body));
        var webhookEvent = Verifier().Verify(Encoding.UTF8.GetBytes(Body), Header);
        Assert.Equal("evt_1", webhookEvent.Id);
        Assert.Equal(WebhookEventTypes.Ping, webhookEvent.Type);
        Assert.Equal(new DateTimeOffset(2026, 11, 8, 10, 20, 0, TimeSpan.Zero), webhookEvent.CreatedAt);
        Assert.Equal("ping", webhookEvent.Data.GetProperty("message").GetString());
        Assert.False(webhookEvent.IsMessageEvent);
        Assert.Throws<InvalidOperationException>(() => webhookEvent.GetMessageEvent());

        // Same result from the string overload and with spaces around the parts.
        Assert.Equal("evt_1", Verifier().Verify(Body, $" t={T} , v1={V1} ").Id);
    }

    [Fact]
    public void Assinatura_errada_corpo_alterado_e_segredo_errado()
    {
        Assert.Contains("inválida", Assert.Throws<WebhookVerificationException>(() =>
            Verifier().Verify(Body, $"t={T},v1={new string('0', 64)}")).Message);
        Assert.Contains("inválida", Assert.Throws<WebhookVerificationException>(() =>
            Verifier().Verify(Body + " ", Header)).Message);
        Assert.Throws<WebhookVerificationException>(() => Verifier().Verify(Body, $"t={T},v1={V1.ToUpperInvariant()}"));
        Assert.Throws<WebhookVerificationException>(() => Verifier().Verify(Body, $"t={T + 1},v1={V1}"));
        Assert.Throws<WebhookVerificationException>(() => Verifier().Verify(Body, $"t={T},v1={V1[..10]}"));
        var other = new WebhookVerifier("whsec_outro", null, new FakeTimeProvider(DateTimeOffset.FromUnixTimeSeconds(T)));
        Assert.Throws<WebhookVerificationException>(() => other.Verify(Body, Header));
    }

    [Fact]
    public void Expirado_fora_da_tolerancia()
    {
        Assert.Contains("janela", Assert.Throws<WebhookVerificationException>(() => Verifier(T + 301).Verify(Body, Header)).Message);
        Assert.Contains("janela", Assert.Throws<WebhookVerificationException>(() => Verifier(T - 301).Verify(Body, Header)).Message);
        Assert.Equal("evt_1", Verifier(T - 300).Verify(Body, Header).Id);
        Assert.Equal("evt_1", Verifier(T + 300).Verify(Body, Header).Id);
        Assert.Throws<WebhookVerificationException>(() => Verifier(T + 61, TimeSpan.FromSeconds(60)).Verify(Body, Header));
        Assert.Equal("evt_1", Verifier(T + 3600, TimeSpan.FromHours(2)).Verify(Body, Header).Id);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("v1=" + V1)]
    [InlineData("t=1762597200")]
    [InlineData("t=1762597200,v1=")]
    [InlineData("t=abc,v1=" + V1)]
    [InlineData("t=-1762597200,v1=" + V1)]
    [InlineData("1762597200," + V1)]
    public void Cabecalho_mal_formado(string? header)
    {
        var error = Assert.Throws<WebhookVerificationException>(() => Verifier().Verify(Body, header));
        Assert.Contains("mal formado", error.Message);
    }

    [Fact]
    public void Corpo_que_nao_e_JSON()
    {
        const string body = "não é json";
        var error = Assert.Throws<WebhookVerificationException>(() => Verifier().Verify(body, $"t={T},v1={Sign(body)}"));
        Assert.Contains("JSON", error.Message);
    }

    [Fact]
    public void Evento_de_mensagem()
    {
        const string body = """
            {"id":"e2","type":"message.delivered","created_at":"2026-11-08T10:00:00Z",
             "data":{"message_id":"m1","campaign_id":"c1","contact_id":"k1","external_id":"CLI-1","channel":"sms",
                     "status":"delivered","cost":1.50,"occurred_at":"2026-11-08T10:15:03Z"}}
            """;
        var webhookEvent = Verifier().Verify(body, $"t={T},v1={Sign(body)}");
        Assert.True(webhookEvent.IsMessageEvent);
        var message = webhookEvent.GetMessageEvent();
        Assert.Equal("m1", message.MessageId);
        Assert.Equal("c1", message.CampaignId);
        Assert.Equal("k1", message.ContactId);
        Assert.Equal("CLI-1", message.ExternalId);
        Assert.Equal(Channel.Sms, message.Channel);
        Assert.Equal(MessageStatus.Delivered, message.Status);
        Assert.Equal(1.50m, message.Cost);
        Assert.Equal(new DateTimeOffset(2026, 11, 8, 10, 15, 3, TimeSpan.Zero), message.OccurredAt);
    }

    [Fact]
    public void Construtor_valida_o_segredo_e_usa_o_relogio_do_sistema()
    {
        Assert.Throws<ArgumentException>(() => new WebhookVerifier(""));
        Assert.Throws<ArgumentOutOfRangeException>(() => new WebhookVerifier(Secret, TimeSpan.FromSeconds(-1)));
        // System clock: the 2025 vector timestamp is far outside the window.
        Assert.Throws<WebhookVerificationException>(() => new WebhookVerifier(Secret).Verify(Body, Header));
        var now = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        Assert.Equal("evt_1", new WebhookVerifier(Secret).Verify(Body, $"t={now},v1={Sign(Body, now)}").Id);
    }
}
