package nexorasms

import (
	"bytes"
	"context"
	cryptorand "crypto/rand"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math/rand/v2"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// Version of the SDK, sent in the User-Agent.
const Version = "0.1.0"

// DefaultBaseURL is the production API.
const DefaultBaseURL = "https://sms.e258tech.tech"

const (
	publicKeyPrefix   = "e258_nex_sms_pk_"
	apiKeyPrefix      = "e258_nex_sms_ak_"
	defaultTimeout    = 30 * time.Second
	defaultMaxRetries = 2
	maxRetryAfter     = 30 * time.Second
	maxBackoff        = 8 * time.Second
	baseBackoff       = 500 * time.Millisecond
)

// sleepFunc waits d or until ctx is done.
type sleepFunc func(ctx context.Context, d time.Duration) error

func realSleep(ctx context.Context, d time.Duration) error {
	timer := time.NewTimer(d)
	defer timer.Stop()
	select {
	case <-timer.C:
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

// ------------------------------------------------------------------ Builder

// ClientBuilder configures a Client. Setters never fail; Build validates everything.
type ClientBuilder struct {
	publicKey     string
	apiKey        string
	baseURL       string
	timeout       time.Duration
	maxRetries    int
	userAgent     string
	httpClient    *http.Client
	sleep         sleepFunc
	timeoutSet    bool
	maxRetriesSet bool
}

// NewClientBuilder starts the configuration of a Client.
func NewClientBuilder() *ClientBuilder {
	return &ClientBuilder{baseURL: DefaultBaseURL, timeout: defaultTimeout, maxRetries: defaultMaxRetries}
}

// PublicKey sets the public key (X-Public-Key), e258_nex_sms_pk_…
func (b *ClientBuilder) PublicKey(value string) *ClientBuilder { b.publicKey = value; return b }

// APIKey sets the secret key (X-Api-Key), e258_nex_sms_ak_… Keep it on the server only.
func (b *ClientBuilder) APIKey(value string) *ClientBuilder { b.apiKey = value; return b }

// BaseURL overrides the API address (default https://sms.e258tech.tech). Trailing slashes are removed.
func (b *ClientBuilder) BaseURL(value string) *ClientBuilder {
	b.baseURL = strings.TrimRight(value, "/")
	return b
}

// Timeout sets the per-attempt timeout (default 30 s).
func (b *ClientBuilder) Timeout(d time.Duration) *ClientBuilder {
	b.timeout, b.timeoutSet = d, true
	return b
}

// MaxRetries sets the retries after network errors, 429, 502–504 and 409 with Retry-After (default 2; 0 disables).
func (b *ClientBuilder) MaxRetries(n int) *ClientBuilder {
	b.maxRetries, b.maxRetriesSet = n, true
	return b
}

// UserAgent sets extra text appended to the SDK's User-Agent (e.g. "loja-online/2.3").
func (b *ClientBuilder) UserAgent(value string) *ClientBuilder { b.userAgent = value; return b }

// HTTPClient sets a custom *http.Client (proxies, transports). The builder's Timeout still applies per attempt.
func (b *ClientBuilder) HTTPClient(c *http.Client) *ClientBuilder { b.httpClient = c; return b }

// withSleep replaces the wait between retries (tests).
func (b *ClientBuilder) withSleep(fn sleepFunc) *ClientBuilder { b.sleep = fn; return b }

// Build validates the configuration and returns an immutable Client, safe for concurrent use.
func (b *ClientBuilder) Build() (*Client, error) {
	if !strings.HasPrefix(b.publicKey, publicKeyPrefix) {
		return nil, fmt.Errorf("publicKey em falta ou inválida: deve começar por %s.", publicKeyPrefix)
	}
	if !strings.HasPrefix(b.apiKey, apiKeyPrefix) {
		return nil, fmt.Errorf("apiKey em falta ou inválida: deve começar por %s.", apiKeyPrefix)
	}
	parsed, err := url.Parse(b.baseURL)
	if err != nil || (parsed.Scheme != "http" && parsed.Scheme != "https") || parsed.Host == "" {
		return nil, fmt.Errorf("baseURL inválida: %q (use http:// ou https://).", b.baseURL)
	}
	if b.timeout <= 0 {
		return nil, errors.New("timeout deve ser uma duração positiva.")
	}
	if b.maxRetries < 0 {
		return nil, errors.New("maxRetries deve ser um inteiro >= 0.")
	}
	httpClient := b.httpClient
	if httpClient == nil {
		httpClient = &http.Client{}
	}
	sleep := b.sleep
	if sleep == nil {
		sleep = realSleep
	}
	userAgent := "nexorasms-go/" + Version
	if b.userAgent != "" {
		userAgent += " " + b.userAgent
	}
	c := &Client{
		publicKey:  b.publicKey,
		apiKey:     b.apiKey,
		baseURL:    b.baseURL,
		host:       parsed.Host,
		timeout:    b.timeout,
		maxRetries: b.maxRetries,
		userAgent:  userAgent,
		httpClient: httpClient,
		sleep:      sleep,
	}
	c.Messages = &MessagesService{client: c}
	c.Contacts = &ContactsService{client: c}
	c.Wallet = &WalletService{client: c}
	c.Campaigns = &CampaignsService{client: c}
	return c, nil
}

// ------------------------------------------------------------------ Client

// Client talks to the NexoraSMS API. Create it with NewClientBuilder; it is immutable and safe for concurrent use.
type Client struct {
	Messages  *MessagesService
	Contacts  *ContactsService
	Wallet    *WalletService
	Campaigns *CampaignsService

	publicKey  string
	apiKey     string
	baseURL    string
	host       string
	timeout    time.Duration
	maxRetries int
	userAgent  string
	httpClient *http.Client
	sleep      sleepFunc
}

// BaseURL returns the API address in use.
func (c *Client) BaseURL() string { return c.baseURL }

type queryParam struct{ key, value string }

type apiRequest struct {
	method    string
	path      string
	query     []queryParam
	body      any
	headers   map[string]string
	retryable bool
}

// do sends the request, retrying when allowed, and decodes a 2xx JSON body into out (if not nil).
func (c *Client) do(ctx context.Context, r apiRequest, out any) (http.Header, error) {
	target := c.baseURL + r.path
	if len(r.query) > 0 {
		parts := make([]string, 0, len(r.query))
		for _, q := range r.query {
			parts = append(parts, url.QueryEscape(q.key)+"="+url.QueryEscape(q.value))
		}
		target += "?" + strings.Join(parts, "&")
	}
	var payload []byte
	if r.body != nil {
		var err error
		if payload, err = json.Marshal(r.body); err != nil {
			return nil, fmt.Errorf("nexorasms: não foi possível serializar o pedido: %w", err)
		}
	}

	for attempt := 0; ; attempt++ {
		status, header, body, err := c.attempt(ctx, r, target, payload)
		if err != nil {
			if errors.Is(err, ErrConnection) && ctx.Err() == nil && r.retryable && attempt < c.maxRetries {
				if serr := c.sleep(ctx, backoff(attempt)); serr != nil {
					return nil, &ConnectionError{Host: c.host, Err: serr}
				}
				continue
			}
			return nil, err
		}
		if status >= 200 && status < 300 {
			if out != nil && len(bytes.TrimSpace(body)) > 0 {
				if err := json.Unmarshal(body, out); err != nil {
					return header, fmt.Errorf("nexorasms: resposta inválida da API: %w", err)
				}
			}
			return header, nil
		}

		retryAfter := parseRetryAfter(header.Get("Retry-After"), time.Now())
		transient := status == 429 || status == 502 || status == 503 || status == 504 || (status == 409 && retryAfter != nil)
		if r.retryable && transient && attempt < c.maxRetries {
			wait := backoff(attempt)
			if retryAfter != nil {
				wait = min(*retryAfter, maxRetryAfter)
			}
			if serr := c.sleep(ctx, wait); serr != nil {
				return nil, errorFor(status, body, retryAfter)
			}
			continue
		}
		return nil, errorFor(status, body, retryAfter)
	}
}

// attempt performs one HTTP exchange with the per-attempt timeout.
func (c *Client) attempt(ctx context.Context, r apiRequest, target string, payload []byte) (int, http.Header, []byte, error) {
	attemptCtx, cancel := context.WithTimeout(ctx, c.timeout)
	defer cancel()
	var bodyReader io.Reader
	if payload != nil {
		bodyReader = bytes.NewReader(payload)
	}
	req, err := http.NewRequestWithContext(attemptCtx, r.method, target, bodyReader)
	if err != nil {
		return 0, nil, nil, fmt.Errorf("nexorasms: pedido inválido: %w", err)
	}
	req.Header.Set("X-Public-Key", c.publicKey)
	req.Header.Set("X-Api-Key", c.apiKey)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", c.userAgent)
	if payload != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	for k, v := range r.headers {
		req.Header.Set(k, v)
	}
	resp, err := c.httpClient.Do(req)
	if err != nil {
		return 0, nil, nil, c.connectionError(attemptCtx, err)
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return 0, nil, nil, c.connectionError(attemptCtx, err)
	}
	return resp.StatusCode, resp.Header, body, nil
}

func (c *Client) connectionError(attemptCtx context.Context, err error) error {
	var netErr net.Error
	timedOut := errors.Is(attemptCtx.Err(), context.DeadlineExceeded) || (errors.As(err, &netErr) && netErr.Timeout())
	return &ConnectionError{Host: c.host, Timeout: timedOut, Err: err}
}

func backoff(attempt int) time.Duration {
	base := baseBackoff << min(attempt, 10)
	if base > maxBackoff {
		base = maxBackoff
	}
	return time.Duration(float64(base) * (0.8 + rand.Float64()*0.4))
}

// parseRetryAfter reads Retry-After as seconds or an HTTP date; nil when absent or invalid.
func parseRetryAfter(value string, now time.Time) *time.Duration {
	value = strings.TrimSpace(value)
	if value == "" {
		return nil
	}
	if seconds, err := strconv.ParseFloat(value, 64); err == nil {
		if seconds < 0 {
			return nil
		}
		d := time.Duration(seconds * float64(time.Second))
		return &d
	}
	if at, err := http.ParseTime(value); err == nil {
		d := at.Sub(now)
		if d < 0 {
			d = 0
		}
		d = d.Round(time.Second)
		return &d
	}
	return nil
}

// newUUID returns a random UUID v4.
func newUUID() string {
	var b [16]byte
	if _, err := cryptoRandRead(b[:]); err != nil {
		panic("nexorasms: crypto/rand failed: " + err.Error())
	}
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}

var cryptoRandRead = cryptorand.Read

// pathSegment URL-encodes an identifier for use in a path.
func pathSegment(id string) (string, error) {
	if id == "" {
		return "", errors.New("Identificador em falta.")
	}
	return url.PathEscape(id), nil
}
