using System.Net.Http;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace E258Tech.NexoraSms.Tests;

public sealed class ClientTests : IDisposable
{
    private static readonly Regex UuidV4 = new("^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$");

    private static readonly object SendResult = new
    {
        channels = new[] { new { channel = "sms", campaign_id = "c1", recipients = 1, cost = 1.5m, status = "running" } },
        invalid = Array.Empty<string>(),
    };

    private readonly TestApi _api = new();

    public void Dispose() => _api.Dispose();

    private static SendMessageRequest SmsRequest() =>
        SendMessageRequest.Builder().Sms("Olá", "MINHALOJA").To("+258841234567").Build();

    private static void AssertJson(string expected, RecordedRequest request) =>
        Assert.True(JsonNode.DeepEquals(JsonNode.Parse(expected), request.Json), $"JSON enviado: {request.Body}");

    // ------------------------------------------------------------------ Client builder

    [Fact]
    public void Builder_exige_as_duas_chaves_com_o_prefixo_certo()
    {
        Assert.Contains("publicKey", Assert.Throws<InvalidOperationException>(() =>
            NexoraSmsClient.Builder().ApiKey(TestApi.ApiKey).Build()).Message);
        Assert.Contains("apiKey", Assert.Throws<InvalidOperationException>(() =>
            NexoraSmsClient.Builder().PublicKey(TestApi.PublicKey).Build()).Message);
        Assert.Contains("e258_nex_sms_pk_", Assert.Throws<InvalidOperationException>(() =>
            NexoraSmsClient.Builder().PublicKey("nxk_x").ApiKey(TestApi.ApiKey).Build()).Message);
        Assert.Contains("e258_nex_sms_ak_", Assert.Throws<InvalidOperationException>(() =>
            NexoraSmsClient.Builder().PublicKey(TestApi.PublicKey).ApiKey(TestApi.PublicKey).Build()).Message);
        Assert.Throws<ArgumentOutOfRangeException>(() => NexoraSmsClient.Builder().MaxRetries(-1));
        Assert.Throws<ArgumentOutOfRangeException>(() => NexoraSmsClient.Builder().Timeout(TimeSpan.Zero));
        Assert.Throws<ArgumentException>(() => NexoraSmsClient.Builder().BaseUrl("sms.e258tech.tech"));
        Assert.Throws<ArgumentException>(() => NexoraSmsClient.Builder().BaseUrl("ftp://sms.e258tech.tech"));
        Assert.Throws<ArgumentNullException>(() => NexoraSmsClient.Builder().HttpClient(null!));
    }

    [Fact]
    public void Builder_usa_os_valores_por_omissao()
    {
        using var client = NexoraSmsClient.Builder().PublicKey(TestApi.PublicKey).ApiKey(TestApi.ApiKey).Build();
        Assert.Equal("https://sms.e258tech.tech", client.BaseUrl);
        Assert.Equal(TimeSpan.FromSeconds(30), client.Timeout);
        Assert.Equal(2, client.MaxRetries);
        Assert.Equal("nexorasms-dotnet/0.1.0", client.UserAgent);

        using var custom = _api.Client(b => b.BaseUrl("https://exemplo.co.mz///").UserAgent("loja/1.0"));
        Assert.Equal("https://exemplo.co.mz", custom.BaseUrl);
        Assert.Equal("nexorasms-dotnet/0.1.0 loja/1.0", custom.UserAgent);
    }

    [Fact]
    public async Task Dispose_so_liberta_o_HttpClient_se_o_tiver_criado()
    {
        var client = _api.Client();
        client.Dispose();
        client.Dispose();
        await Assert.ThrowsAsync<ObjectDisposedException>(() => client.Wallet.GetAsync());

        // The HttpClient given to the builder is still usable.
        _api.Handler.Reply(200, new { });
        using var response = await _api.Http.GetAsync(TestApi.BaseUrl + "/ping");
        Assert.True(response.IsSuccessStatusCode);

        // A client that created its own HttpClient disposes it without throwing.
        NexoraSmsClient.Builder().PublicKey(TestApi.PublicKey).ApiKey(TestApi.ApiKey).Build().Dispose();
    }

    // ------------------------------------------------------------------ Messages

    [Fact]
    public async Task Send_envia_chaves_JSON_snake_case_e_uma_IdempotencyKey_gerada()
    {
        _api.Handler.Reply(201, SendResult);
        using var client = _api.Client(b => b.UserAgent("loja/1.0"));
        var result = await client.Messages.SendAsync(SendMessageRequest.Builder()
            .Sms("Olá", "MINHALOJA")
            .Email("Assunto", "<p>Olá</p>")
            .To("+258841234567", "ana@x.co.mz")
            .Contact("k1")
            .IgnoreQuietHours()
            .Build());

        var request = Assert.Single(_api.Seen);
        Assert.Equal(HttpMethod.Post, request.Method);
        Assert.Equal("/api/v1/messages", request.PathAndQuery);
        Assert.Equal(TestApi.PublicKey, request.Header("X-Public-Key"));
        Assert.Equal(TestApi.ApiKey, request.Header("X-Api-Key"));
        Assert.Equal("application/json", request.Header("Accept"));
        Assert.Equal("application/json", request.Header("Content-Type"));
        Assert.Equal("nexorasms-dotnet/0.1.0 loja/1.0", request.Header("User-Agent"));
        Assert.Matches(UuidV4, request.Header("Idempotency-Key"));
        AssertJson("""
            {
              "channels": [
                { "channel": "sms", "content": "Olá", "sender": "MINHALOJA" },
                { "channel": "email", "subject": "Assunto", "content": "<p>Olá</p>" }
              ],
              "recipients": ["+258841234567", "ana@x.co.mz"],
              "contact_ids": ["k1"],
              "ignore_quiet_hours": true
            }
            """, request);

        var channel = Assert.Single(result.Channels);
        Assert.Equal(Channel.Sms, channel.Channel);
        Assert.Equal("c1", channel.CampaignId);
        Assert.Equal(1.5m, channel.Cost);
        Assert.Equal(CampaignStatus.Running, channel.Status);
        Assert.Empty(result.Invalid);
        Assert.Equal(request.Header("Idempotency-Key"), result.IdempotencyKey);
        Assert.False(result.Replayed);
    }

    [Fact]
    public async Task Send_gera_uma_chave_nova_por_chamada()
    {
        _api.Handler.Reply(201, SendResult).Reply(201, SendResult);
        using var client = _api.Client();
        var request = SmsRequest();
        var first = await client.Messages.SendAsync(request);
        var second = await client.Messages.SendAsync(request);
        Assert.NotEqual(first.IdempotencyKey, second.IdempotencyKey);
        Assert.Equal(first.IdempotencyKey, _api.Seen[0].Header("Idempotency-Key"));
        Assert.Equal(second.IdempotencyKey, _api.Seen[1].Header("Idempotency-Key"));
    }

    [Fact]
    public async Task Send_repete_em_503_e_429_com_a_mesma_chave_e_respeita_o_RetryAfter()
    {
        _api.Handler
            .Reply(503, new { title = "Indisponível", status = 503 })
            .Reply(429, new { title = "Devagar", status = 429 }, ("Retry-After", "2"))
            .Reply(201, SendResult, ("Idempotent-Replayed", "true"));
        using var client = _api.Client();
        var result = await client.Messages.SendAsync(
            SendMessageRequest.Builder().Sms("Olá").To("+258841234567").IdempotencyKey("fatura-123").Build());

        Assert.Equal(3, _api.Seen.Count);
        Assert.All(_api.Seen, r => Assert.Equal("fatura-123", r.Header("Idempotency-Key")));
        Assert.Equal(2, _api.Time.Delays.Count);
        Assert.InRange(_api.Time.Delays[0].TotalMilliseconds, 400, 600); // 0.5 s ± 20 %
        Assert.Equal(TimeSpan.FromSeconds(2), _api.Time.Delays[1]);
        Assert.True(result.Replayed);
        Assert.Equal("fatura-123", result.IdempotencyKey);
    }

    [Fact]
    public async Task Repeticoes_sem_RetryAfter_crescem_e_nao_passam_de_8s_e_RetryAfter_nao_passa_de_30s()
    {
        for (var i = 0; i < 7; i++)
            _api.Handler.Reply(503, new { });
        using var client = _api.Client(b => b.MaxRetries(6));
        await Assert.ThrowsAsync<ServerException>(() => client.Wallet.GetAsync());
        var delays = _api.Time.Delays.Select(d => d.TotalMilliseconds).ToList();
        Assert.Equal(6, delays.Count);
        Assert.InRange(delays[0], 400, 600);
        Assert.InRange(delays[1], 800, 1200);
        Assert.InRange(delays[2], 1600, 2400);
        Assert.InRange(delays[3], 3200, 4800);
        Assert.All(delays.Skip(4), d => Assert.InRange(d, 6400, 8000));

        _api.Handler.Reply(429, new { }, ("Retry-After", "120")).Reply(429, new { }, ("Retry-After", "120"));
        using var once = _api.Client(b => b.MaxRetries(1));
        var error = await Assert.ThrowsAsync<RateLimitException>(() => once.Wallet.GetAsync());
        Assert.Equal(TimeSpan.FromSeconds(30), _api.Time.Delays[^1]);
        Assert.Equal(TimeSpan.FromSeconds(120), error.RetryAfter);
    }

    [Fact]
    public async Task Nao_repete_em_400_nem_402_e_402_vira_InsufficientFunds_com_o_title()
    {
        _api.Handler.Reply(402, new { title = "Saldo insuficiente para este envio.", status = 402, traceId = "t1" });
        using var client = _api.Client();
        var funds = await Assert.ThrowsAsync<InsufficientFundsException>(() => client.Messages.SendAsync(SmsRequest()));
        Assert.Equal(402, funds.Status);
        Assert.Equal("Saldo insuficiente para este envio.", funds.Message);
        Assert.Equal("Saldo insuficiente para este envio.", funds.Title);
        Assert.Equal("t1", funds.TraceId);
        Assert.Single(_api.Seen);

        _api.Handler.Reply(400, new { title = "Pedido inválido", status = 400, errors = new { channels = new[] { "obrigatório" } } });
        var validation = await Assert.ThrowsAsync<ValidationException>(() => client.Messages.SendAsync(SmsRequest()));
        Assert.Equal("obrigatório", validation.Errors["channels"][0]);
        Assert.Equal(2, _api.Seen.Count);
        Assert.Empty(_api.Time.Delays);
    }

    [Fact]
    public async Task Desiste_depois_de_maxRetries_e_devolve_o_ultimo_erro()
    {
        for (var i = 0; i < 3; i++)
            _api.Handler.Reply(503, new { title = "Indisponível", status = 503 });
        using var client = _api.Client();
        var error = await Assert.ThrowsAsync<ServerException>(() => client.Messages.SendAsync(SmsRequest()));
        Assert.Equal("Indisponível", error.Message);
        Assert.Equal(3, _api.Seen.Count);

        _api.Handler.Reply(503, "");
        using var noRetries = _api.Client(b => b.MaxRetries(0));
        var bare = await Assert.ThrowsAsync<ServerException>(() => noRetries.Wallet.GetAsync());
        Assert.Equal("HTTP 503", bare.Message);
        Assert.Equal(4, _api.Seen.Count);
    }

    [Fact]
    public async Task Status_500_nao_se_repete_e_detail_serve_de_mensagem()
    {
        _api.Handler.Reply(500, new { detail = "Falha interna", status = 500 });
        using var client = _api.Client();
        var error = await Assert.ThrowsAsync<ServerException>(() => client.Wallet.GetAsync());
        Assert.Equal("Falha interna", error.Message);
        Assert.Null(error.Title);
        Assert.Single(_api.Seen);
    }

    [Fact]
    public async Task Conflito_409_so_se_repete_com_RetryAfter()
    {
        _api.Handler
            .Reply(409, new { title = "Em processamento", status = 409 }, ("Retry-After", "1"))
            .Reply(201, SendResult);
        using var client = _api.Client();
        await client.Messages.SendAsync(SmsRequest());
        Assert.Equal(2, _api.Seen.Count);
        Assert.Equal(TimeSpan.FromSeconds(1), _api.Time.Delays.Single());

        _api.Handler.Reply(409, new { title = "Conteúdo diferente", status = 409 });
        await Assert.ThrowsAsync<ConflictException>(() => client.Messages.SendAsync(SmsRequest()));
        Assert.Equal(3, _api.Seen.Count);
    }

    [Fact]
    public async Task Mapeia_401_403_404_e_429()
    {
        using var client = _api.Client();
        _api.Handler.Reply(401, new { title = "Unauthorized", status = 401 });
        await Assert.ThrowsAsync<AuthenticationException>(() => client.Wallet.GetAsync());
        _api.Handler.Reply(403, new { title = "Sem permissão", status = 403 });
        await Assert.ThrowsAsync<PermissionDeniedException>(() => client.Wallet.GetAsync());
        _api.Handler.Reply(404, new { title = "Mensagem não encontrada.", status = 404 });
        var notFound = await Assert.ThrowsAsync<NotFoundException>(() => client.Messages.GetAsync("m1"));
        Assert.Equal("Mensagem não encontrada.", notFound.Message);
        _api.Handler.Reply(429, new { }).Reply(429, new { }).Reply(429, new { }, ("Retry-After", "5"));
        var limited = await Assert.ThrowsAsync<RateLimitException>(() => client.Wallet.GetAsync());
        Assert.Equal(TimeSpan.FromSeconds(5), limited.RetryAfter);
        Assert.IsAssignableFrom<NexoraSmsException>(limited);
        _api.Handler.Reply(418, "<html>teapot</html>");
        var other = await Assert.ThrowsAsync<NexoraSmsException>(() => client.Wallet.GetAsync());
        Assert.Equal(typeof(NexoraSmsException), other.GetType());
        Assert.Equal("HTTP 418", other.Message);
    }

    [Fact]
    public async Task Erro_de_rede_vira_ConnectionException_depois_das_repeticoes()
    {
        for (var i = 0; i < 3; i++)
            _api.Handler.Throw(new HttpRequestException("ligação recusada"));
        using var client = _api.Client();
        var error = await Assert.ThrowsAsync<ConnectionException>(() => client.Wallet.GetAsync());
        Assert.Null(error.Status);
        Assert.IsType<HttpRequestException>(error.InnerException);
        Assert.Contains("nexorasms.test", error.Message);
        Assert.Equal(3, _api.Seen.Count);
    }

    [Fact]
    public async Task Pedidos_nao_idempotentes_nao_se_repetem_em_erro_de_rede()
    {
        using var client = _api.Client();
        _api.Handler.Throw(new HttpRequestException("falhou"));
        await Assert.ThrowsAsync<ConnectionException>(() => client.Contacts.CreateAsync(ContactRequest.Builder().Name("Ana").Build()));
        Assert.Single(_api.Seen);
        _api.Handler.Throw(new HttpRequestException("falhou"));
        await Assert.ThrowsAsync<ConnectionException>(() => client.Contacts.UpdateAsync("k1", ContactRequest.Builder().Name("Ana").Build()));
        Assert.Equal(2, _api.Seen.Count);
        _api.Handler.Throw(new HttpRequestException("falhou"));
        await Assert.ThrowsAsync<ConnectionException>(() => client.Contacts.RecordConsentAsync("k1", Channel.Sms, ConsentStatus.Revoked, "erp"));
        Assert.Equal(3, _api.Seen.Count);
        _api.Handler.Reply(503, new { });
        await Assert.ThrowsAsync<ServerException>(() => client.Contacts.CreateAsync(ContactRequest.Builder().Name("Ana").Build()));
        Assert.Equal(4, _api.Seen.Count);

        // PUT upsert is idempotent: retried.
        _api.Handler.Throw(new HttpRequestException("falhou")).Reply(200, new { id = "k1" });
        var contact = await client.Contacts.UpsertByExternalIdAsync("CLI-1", ContactRequest.Builder().Name("Ana").Build());
        Assert.Equal("k1", contact.Id);
        Assert.Equal(6, _api.Seen.Count);
    }

    [Fact]
    public async Task Tempo_limite_vira_ConnectionException_e_cancelamento_do_chamador_propaga()
    {
        _api.Handler.Hang();
        using var client = _api.Client(b => b.Timeout(TimeSpan.FromMilliseconds(50)).MaxRetries(0));
        var timeout = await Assert.ThrowsAsync<ConnectionException>(() => client.Wallet.GetAsync());
        Assert.Contains("Tempo limite", timeout.Message);

        using var cts = new CancellationTokenSource();
        cts.Cancel();
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => client.Wallet.GetAsync(cts.Token));

        _api.Handler.Hang();
        using var later = new CancellationTokenSource(TimeSpan.FromMilliseconds(50));
        using var patient = _api.Client(b => b.Timeout(TimeSpan.FromSeconds(30)));
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => patient.Wallet.GetAsync(later.Token));
    }

    [Fact]
    public async Task Estimate_get_e_list_com_query_string()
    {
        using var client = _api.Client();
        _api.Handler.Reply(200, new
        {
            channels = new[] { new { channel = "sms", recipients = 1, segments = 1, encoding = "gsm7", unit_price = 1.5m, cost = 1.5m } },
            total = 1.5m,
            available = 10.25m,
            invalid = new[] { "+2581" },
        });
        var estimate = await client.Messages.EstimateAsync(SmsRequest());
        Assert.Equal("/api/v1/messages/estimate", _api.Seen[0].PathAndQuery);
        Assert.Null(_api.Seen[0].Header("Idempotency-Key"));
        Assert.Equal(1.5m, estimate.Channels[0].UnitPrice);
        Assert.Equal(MessageEncoding.Gsm7, estimate.Channels[0].Encoding);
        Assert.Equal(10.25m, estimate.Available);
        Assert.Equal("+2581", estimate.Invalid.Single());

        _api.Handler.Reply(200, """
            {"id":"m1","campaign_id":"c1","channel":"sms","destination":"+258841234567","status":"delivered","cost":1.5,"parts":1,
             "created_at":"2026-11-08T10:15:00Z","accepted_at":"2026-11-08T10:15:01Z","delivered_at":null,
             "attempts":[{"part_number":1,"attempt_number":1,"status":"in_flight","provider_message_id":"p1","started_at":"2026-11-08T10:15:00+02:00"}],
             "sender":"MINHALOJA","campo_novo":{"x":1}}
            """);
        var message = await client.Messages.GetAsync("m1");
        Assert.Equal("/api/v1/messages/m1", _api.Seen[1].PathAndQuery);
        Assert.Equal(MessageStatus.Delivered, message.Status);
        Assert.Equal(new DateTimeOffset(2026, 11, 8, 10, 15, 0, TimeSpan.Zero), message.CreatedAt);
        Assert.Null(message.DeliveredAt);
        Assert.Equal(AttemptStatus.InFlight, message.Attempts[0].Status);
        Assert.Equal(TimeSpan.FromHours(2), message.Attempts[0].StartedAt.Offset);
        Assert.Equal("MINHALOJA", message.Sender);

        _api.Handler.Reply(200, new
        {
            items = new[] { new { id = "m1", campaign_id = "c1", attempts = new[] { new { part_number = 1, status = "in_flight" } } } },
            page = 2,
            page_size = 10,
            total = 11,
        });
        var page = await client.Messages.ListAsync(MessageQuery.Builder()
            .CampaignId("c1").Status(MessageStatus.Failed).Channel(Channel.WhatsApp).Search("ana silva").Page(2).PageSize(10).Build());
        Assert.Equal("/api/v1/messages?campaign_id=c1&status=failed&channel=whatsapp&search=ana%20silva&page=2&page_size=10",
            _api.Seen[2].PathAndQuery);
        Assert.Equal(2, page.PageNumber);
        Assert.Equal(10, page.PageSize);
        Assert.Equal(11, page.Total);
        Assert.Equal(1, page.Items[0].Attempts[0].PartNumber);

        _api.Handler.Reply(200, new { items = Array.Empty<object>(), page = 1, page_size = 50, total = 0 });
        await client.Messages.ListAsync();
        Assert.Equal("/api/v1/messages", _api.Seen[3].PathAndQuery);

        _api.Handler.Reply(200, new { items = Array.Empty<object>(), page = 1, page_size = 50, total = 0 });
        await client.Contacts.ListAsync(ContactQuery.Builder().Tag("vip").Status(ContactStatus.Active).GroupId("g1").Build());
        Assert.Equal("/api/v1/contacts?group_id=g1&tag=vip&status=active", _api.Seen[4].PathAndQuery);
    }

    [Fact]
    public async Task Valores_desconhecidos_de_enum_viram_Unrecognized_e_campos_novos_sao_ignorados()
    {
        _api.Handler.Reply(200, """
            {"id":"m1","channel":"telegram","status":"queued_again","attempts":[{"status":"Accepted"}],"extra":[1,2]}
            """);
        using var client = _api.Client();
        var message = await client.Messages.GetAsync("m1");
        Assert.Equal(Channel.Unrecognized, message.Channel);
        Assert.Equal(MessageStatus.Unrecognized, message.Status);
        Assert.Equal(AttemptStatus.Accepted, message.Attempts[0].Status);

        _api.Handler.Reply(200, """{"id":"m2","status":"unknown","attempts":[{"status":"unknown"}]}""");
        var unknown = await client.Messages.GetAsync("m2");
        Assert.Equal(MessageStatus.Unknown, unknown.Status);
        Assert.Equal(AttemptStatus.Unknown, unknown.Attempts[0].Status);
    }

    [Fact]
    public async Task Resposta_de_sucesso_invalida_vira_NexoraSmsException()
    {
        _api.Handler.Reply(200, "isto não é json");
        using var client = _api.Client();
        var error = await Assert.ThrowsAsync<NexoraSmsException>(() => client.Wallet.GetAsync());
        Assert.Equal(200, error.Status);
        Assert.Contains("inválida", error.Message);
    }

    // ------------------------------------------------------------------ Contacts

    [Fact]
    public async Task Upsert_por_id_externo_codifica_o_id_e_mantem_os_nomes_dos_atributos()
    {
        _api.Handler.Reply(201, new
        {
            id = "k1",
            external_id = "CLI/42 A",
            status = "active",
            attributes = new Dictionary<string, string> { ["data_vencimento"] = "2026-12-01", ["PlanoAntigo"] = "x" },
            group_ids = Array.Empty<string>(),
            consents = new[] { new { channel = "sms", status = "granted", source = "erp", recorded_at = "2026-11-08T10:00:00Z" } },
            created_at = "2026-11-08T10:15:00Z",
        });
        using var client = _api.Client();
        var contact = await client.Contacts.UpsertByExternalIdAsync("CLI/42 A", ContactRequest.Builder()
            .Name("Ana").Phone("841234567").Tags("vip", "maputo")
            .Attribute("data_vencimento", "2026-12-01").Attribute("velho", null)
            .Build());

        var request = _api.Seen.Single();
        Assert.Equal(HttpMethod.Put, request.Method);
        Assert.Equal("/api/v1/contacts/external/CLI%2F42%20A", request.PathAndQuery);
        AssertJson("""
            { "name": "Ana", "phone": "841234567", "tags": ["vip", "maputo"],
              "attributes": { "data_vencimento": "2026-12-01", "velho": null } }
            """, request);
        Assert.Equal("CLI/42 A", contact.ExternalId);
        Assert.Equal(ContactStatus.Active, contact.Status);
        Assert.Equal("2026-12-01", contact.Attributes["data_vencimento"]);
        Assert.Equal("x", contact.Attributes["PlanoAntigo"]);
        Assert.Equal(ConsentStatus.Granted, contact.Consents[0].Status);
        Assert.Empty(contact.Tags);
    }

    [Fact]
    public async Task Update_so_envia_os_campos_definidos_e_consentimento_valida_o_estado()
    {
        using var client = _api.Client();
        _api.Handler.Reply(200, new { id = "k1" });
        await client.Contacts.UpdateAsync("k1", ContactRequest.Builder().Status(ContactStatus.Inactive).Tags().Build());
        Assert.Equal(HttpMethod.Patch, _api.Seen[0].Method);
        Assert.Equal("/api/v1/contacts/k1", _api.Seen[0].PathAndQuery);
        AssertJson("""{ "status": "inactive", "tags": [] }""", _api.Seen[0]);

        _api.Handler.Reply(200, new { id = "k1" });
        await client.Contacts.RecordConsentAsync("k1", Channel.Sms, ConsentStatus.Revoked, "erp");
        Assert.Equal("/api/v1/contacts/k1/consents", _api.Seen[1].PathAndQuery);
        AssertJson("""{ "channel": "sms", "status": "revoked", "source": "erp" }""", _api.Seen[1]);

        await Assert.ThrowsAsync<ArgumentException>(() =>
            client.Contacts.RecordConsentAsync("k1", Channel.Sms, ConsentStatus.Unrecognized, "erp"));
        await Assert.ThrowsAsync<ArgumentException>(() =>
            client.Contacts.RecordConsentAsync("k1", Channel.Unrecognized, ConsentStatus.Granted, "erp"));
        await Assert.ThrowsAsync<ArgumentException>(() => client.Contacts.GetAsync(" "));
        Assert.Equal(2, _api.Seen.Count);

        _api.Handler.Reply(201, new { id = "k2" });
        await client.Contacts.CreateAsync(ContactRequest.Builder().Email("ana@x.co.mz").ExternalId("CLI-2").GroupId("g1").Build());
        Assert.Equal(HttpMethod.Post, _api.Seen[2].Method);
        AssertJson("""{ "email": "ana@x.co.mz", "group_ids": ["g1"], "external_id": "CLI-2" }""", _api.Seen[2]);
    }

    // ------------------------------------------------------------------ Wallet and campaigns

    [Fact]
    public async Task Carteira_e_campanhas()
    {
        using var client = _api.Client();
        _api.Handler.Reply(200, new { currency = "MZN", balance = 10.10m, reserved = 1m, available = 9.10m });
        var wallet = await client.Wallet.GetAsync();
        Assert.Equal(9.10m, wallet.Available);
        Assert.Equal("MZN", wallet.Currency);

        _api.Handler.Reply(200, new
        {
            items = new[] { new { id = "t1", type = "debit", amount = 1.5m, business_reference = "r1", created_at = "2026-11-08T10:00:00Z" } },
            page = 1,
            page_size = 20,
            total = 1,
        });
        var transactions = await client.Wallet.TransactionsAsync(1, 20);
        Assert.Equal("/api/v1/wallet/transactions?page=1&page_size=20", _api.Seen[1].PathAndQuery);
        Assert.Equal("r1", transactions.Items[0].BusinessReference);
        Assert.Equal(WalletTransactionType.Debit, transactions.Items[0].Type);
        await Assert.ThrowsAsync<ArgumentOutOfRangeException>(() => client.Wallet.TransactionsAsync(pageSize: 201));

        _api.Handler.Reply(200, new { id = "c1", name = "Promo", channel = "email", status = "paused", ignore_quiet_hours = true, delivered = 3 });
        var campaign = await client.Campaigns.GetAsync("c1");
        Assert.Equal("/api/v1/campaigns/c1", _api.Seen[2].PathAndQuery);
        Assert.Equal(CampaignStatus.Paused, campaign.Status);
        Assert.Equal(Channel.Email, campaign.Channel);
        Assert.True(campaign.IgnoreQuietHours);
        Assert.Equal(3, campaign.Delivered);
        Assert.Null(campaign.Failed);

        _api.Handler.Reply(200, new { campaign_id = "c1", status = "completed", messages = new { delivered = 3 }, unique_clicks = 1, estimated_cost = 4.5m });
        var stats = await client.Campaigns.StatsAsync("c1");
        Assert.Equal("/api/v1/campaigns/c1/stats", _api.Seen[3].PathAndQuery);
        Assert.Equal(3, stats.Messages["delivered"]);
        Assert.Equal(1, stats.UniqueClicks);
        Assert.Equal(4.5m, stats.EstimatedCost);
    }
}
