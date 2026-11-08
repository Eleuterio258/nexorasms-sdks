// Errors raised by the SDK. API failures carry the ProblemDetails fields returned by NexoraSMS.

export class NexoraSmsError extends Error {
  /**
   * @param {string} message
   * @param {{ status?: number, title?: string, detail?: string, traceId?: string, errors?: Record<string, string[]>, retryAfter?: number, cause?: unknown }} [info]
   */
  constructor(message, info = {}) {
    super(message, info.cause !== undefined ? { cause: info.cause } : undefined)
    this.name = new.target.name
    this.status = info.status
    this.title = info.title
    this.detail = info.detail
    this.traceId = info.traceId
    this.errors = info.errors ?? {}
    this.retryAfter = info.retryAfter
  }
}

export class ValidationError extends NexoraSmsError {}
export class AuthenticationError extends NexoraSmsError {}
export class InsufficientFundsError extends NexoraSmsError {}
export class PermissionDeniedError extends NexoraSmsError {}
export class NotFoundError extends NexoraSmsError {}
export class ConflictError extends NexoraSmsError {}
export class RateLimitError extends NexoraSmsError {}
export class ServerError extends NexoraSmsError {}
/** Network failure or timeout: no HTTP status. */
export class ConnectionError extends NexoraSmsError {}
/** A webhook whose signature or timestamp could not be verified. */
export class WebhookSignatureError extends Error {
  constructor(message) { super(message); this.name = 'WebhookSignatureError' }
}

const byStatus = {
  400: ValidationError,
  401: AuthenticationError,
  402: InsufficientFundsError,
  403: PermissionDeniedError,
  404: NotFoundError,
  409: ConflictError,
  429: RateLimitError,
}

/** Builds the error for an HTTP failure from its status, body (ProblemDetails) and Retry-After. */
export function errorFor(status, body, retryAfter) {
  const problem = body && typeof body === 'object' ? body : {}
  const ErrorType = byStatus[status] ?? (status >= 500 ? ServerError : NexoraSmsError)
  const message = problem.title || problem.detail || `HTTP ${status}`
  return new ErrorType(message, {
    status,
    title: problem.title,
    detail: problem.detail,
    traceId: problem.traceId ?? problem.trace_id,
    errors: problem.errors,
    retryAfter,
  })
}
