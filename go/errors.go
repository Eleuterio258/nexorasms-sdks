package nexorasms

import (
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"time"
)

// ErrorKind classifies an *APIError by HTTP status.
type ErrorKind string

// Kinds of API errors (see the SDK specification, section 6).
const (
	KindValidation        ErrorKind = "validation"         // 400
	KindAuthentication    ErrorKind = "authentication"     // 401
	KindInsufficientFunds ErrorKind = "insufficient_funds" // 402
	KindPermissionDenied  ErrorKind = "permission_denied"  // 403
	KindNotFound          ErrorKind = "not_found"          // 404
	KindConflict          ErrorKind = "conflict"           // 409
	KindRateLimit         ErrorKind = "rate_limit"         // 429
	KindServer            ErrorKind = "server"             // 5xx
	KindUnknown           ErrorKind = "unknown"            // any other non-2xx status
)

// APIError is an HTTP failure returned by NexoraSMS, carrying the ProblemDetails fields.
type APIError struct {
	StatusCode int
	Kind       ErrorKind
	Type       string
	Title      string
	Detail     string
	TraceID    string
	// Errors maps field names to validation messages (400).
	Errors map[string][]string
	// RetryAfter is the Retry-After header, when present.
	RetryAfter *time.Duration
}

// Error returns the title, or the detail, or "HTTP <status>".
func (e *APIError) Error() string {
	switch {
	case e.Title != "":
		return e.Title
	case e.Detail != "":
		return e.Detail
	default:
		return "HTTP " + strconv.Itoa(e.StatusCode)
	}
}

// ErrConnection is matched (errors.Is) by every *ConnectionError.
var ErrConnection = errors.New("nexorasms: connection error")

// ConnectionError is a network failure or timeout: there is no HTTP status.
type ConnectionError struct {
	Host    string
	Timeout bool
	Err     error
}

func (e *ConnectionError) Error() string {
	if e.Timeout {
		return fmt.Sprintf("Tempo limite excedido ao contactar %s.", e.Host)
	}
	return fmt.Sprintf("Não foi possível contactar %s.", e.Host)
}

// Unwrap returns the underlying transport error.
func (e *ConnectionError) Unwrap() error { return e.Err }

// Is reports whether target is ErrConnection.
func (e *ConnectionError) Is(target error) bool { return target == ErrConnection }

// ErrWebhookSignature is matched (errors.Is) by every *WebhookSignatureError.
var ErrWebhookSignature = errors.New("nexorasms: webhook signature error")

// WebhookSignatureError is a webhook whose signature, timestamp or body could not be verified.
type WebhookSignatureError struct {
	Reason string
}

func (e *WebhookSignatureError) Error() string { return e.Reason }

// Is reports whether target is ErrWebhookSignature.
func (e *WebhookSignatureError) Is(target error) bool { return target == ErrWebhookSignature }

// AsAPIError returns the *APIError in err's chain, if any.
func AsAPIError(err error) (*APIError, bool) {
	var apiErr *APIError
	ok := errors.As(err, &apiErr)
	return apiErr, ok
}

func isKind(err error, kind ErrorKind) bool {
	apiErr, ok := AsAPIError(err)
	return ok && apiErr.Kind == kind
}

// IsValidation reports a 400 response.
func IsValidation(err error) bool { return isKind(err, KindValidation) }

// IsAuthentication reports a 401 response.
func IsAuthentication(err error) bool { return isKind(err, KindAuthentication) }

// IsInsufficientFunds reports a 402 response (wallet balance too low).
func IsInsufficientFunds(err error) bool { return isKind(err, KindInsufficientFunds) }

// IsPermissionDenied reports a 403 response.
func IsPermissionDenied(err error) bool { return isKind(err, KindPermissionDenied) }

// IsNotFound reports a 404 response.
func IsNotFound(err error) bool { return isKind(err, KindNotFound) }

// IsConflict reports a 409 response.
func IsConflict(err error) bool { return isKind(err, KindConflict) }

// IsRateLimited reports a 429 response.
func IsRateLimited(err error) bool { return isKind(err, KindRateLimit) }

// IsServerError reports a 5xx response.
func IsServerError(err error) bool { return isKind(err, KindServer) }

// IsConnectionError reports a network failure or timeout.
func IsConnectionError(err error) bool { return errors.Is(err, ErrConnection) }

func kindFor(status int) ErrorKind {
	switch status {
	case 400:
		return KindValidation
	case 401:
		return KindAuthentication
	case 402:
		return KindInsufficientFunds
	case 403:
		return KindPermissionDenied
	case 404:
		return KindNotFound
	case 409:
		return KindConflict
	case 429:
		return KindRateLimit
	}
	if status >= 500 {
		return KindServer
	}
	return KindUnknown
}

type problemDetails struct {
	Type         string              `json:"type"`
	Title        string              `json:"title"`
	Detail       string              `json:"detail"`
	TraceID      string              `json:"traceId"`
	TraceIDSnake string              `json:"trace_id"`
	Errors       map[string][]string `json:"errors"`
}

// errorFor builds the *APIError for an HTTP failure from its status, body (ProblemDetails) and Retry-After.
func errorFor(status int, body []byte, retryAfter *time.Duration) *APIError {
	var p problemDetails
	// A body that is not ProblemDetails (or has unexpected field types) is tolerated: Unmarshal fills what it can.
	_ = json.Unmarshal(body, &p)
	traceID := p.TraceID
	if traceID == "" {
		traceID = p.TraceIDSnake
	}
	errs := p.Errors
	if errs == nil {
		errs = map[string][]string{}
	}
	return &APIError{
		StatusCode: status,
		Kind:       kindFor(status),
		Type:       p.Type,
		Title:      p.Title,
		Detail:     p.Detail,
		TraceID:    traceID,
		Errors:     errs,
		RetryAfter: retryAfter,
	}
}
