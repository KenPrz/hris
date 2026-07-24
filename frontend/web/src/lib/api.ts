/**
 * The API client. The one place that knows the envelope from docs/03-api.md, so no
 * component ever unwraps `data` or branches on an HTTP status by hand.
 */

import { clearToken, emitLogout, getToken } from './session'

/** Success is always `{ data: ... }`; errors are always `{ error: ... }`. Never both. */
export type ApiSuccess<T> = { data: T }

export type ApiErrorBody = {
  error: {
    /** Stable and machine-readable — branch on this, never on `message`. */
    code: string
    /** Human-readable. May change freely; never parse it. */
    message: string
    details: Record<string, unknown>
  }
}

/**
 * A failed request. Carries the stable `code` so callers can branch without touching
 * HTTP status codes or message text.
 */
export class ApiError extends Error {
  // Explicit fields rather than constructor parameter properties: the tsconfig sets
  // `erasableSyntaxOnly`, so type syntax must never emit runtime code.
  readonly code: string
  readonly status: number
  readonly details: Record<string, unknown>

  constructor(code: string, message: string, status: number, details: Record<string, unknown> = {}) {
    super(message)
    this.name = 'ApiError'
    this.code = code
    this.status = status
    this.details = details
  }
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const token = getToken()

  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(init?.headers as Record<string, string> | undefined),
    ...(token !== null ? { Authorization: `Bearer ${token}` } : {}),
  }

  let response: Response

  try {
    response = await fetch(`/api/v1${path}`, { ...init, headers })
  } catch (cause) {
    // The network never reached us. That is a real, expected state the UI has to show
    // rather than swallow.
    throw new ApiError('network_unreachable', 'Cannot reach the server.', 0, {
      cause: String(cause),
    })
  }

  const body: unknown = response.status === 204 ? null : await response.json().catch(() => null)

  if (!response.ok) {
    if (response.status === 401) {
      // The session is dead — server-side or by an expired/revoked token. Clear it and
      // tell the app before the caller ever sees the rejection, so a redirect to /login
      // can happen unconditionally on this code, not on every call site.
      clearToken()
      emitLogout()
    }

    if (isErrorBody(body)) {
      throw new ApiError(body.error.code, body.error.message, response.status, body.error.details)
    }
    throw new ApiError('unexpected_response', `Unexpected ${response.status} from ${path}.`, response.status)
  }

  if (response.status === 204) return undefined as T

  if (!isSuccessBody<T>(body)) {
    throw new ApiError('unexpected_response', `Malformed response from ${path}.`, response.status)
  }

  return body.data
}

function isErrorBody(body: unknown): body is ApiErrorBody {
  // `'error' in body` is not enough: `{"error": null}` passes it and then `body.error.code`
  // throws a TypeError, which is not an ApiError and defeats the whole point of this
  // module — that every failed request rejects with something the UI can branch on.
  if (typeof body !== 'object' || body === null || !('error' in body)) return false

  const error: unknown = (body as { error: unknown }).error

  return typeof error === 'object' && error !== null && typeof (error as { code: unknown }).code === 'string'
}

function isSuccessBody<T>(body: unknown): body is ApiSuccess<T> {
  return typeof body === 'object' && body !== null && 'data' in body
}

// ---------------------------------------------------------------------------
// Wire types — verified against app/Http/Resources/HealthResource.php.
// ---------------------------------------------------------------------------

export type Health = {
  healthy: boolean
  app_version: string
  database: { ok: boolean; version: string | null; reason: string | null }
}

// ---------------------------------------------------------------------------
// Wire types — verified against app/Http/Resources/SessionResource.php,
// app/Actions/Auth/BuildSession.php + SessionData.php, and
// app/Http/Resources/AttendanceLogResource.php.
// ---------------------------------------------------------------------------

export type PunchDirection = 'in' | 'out'
export type PunchSource = 'web' | 'manual' | 'device' | 'adjustment'
export type PunchVerification = 'verified' | 'flagged'

export type AttendanceLog = {
  id: string
  employee_id: string
  office_id: string
  punched_at: string // ISO8601 WITH offset
  direction: PunchDirection
  source: PunchSource
  verification: PunchVerification
  flag_reason: string | null
}

/** Keyed by office-local YYYY-MM-DD — the grouping AttendanceMonth::group produces. */
export type AttendanceMonth = Record<string, AttendanceLog[]>

export type Session = {
  user: { id: string; email: string; name: string }
  employee: {
    id: string
    employee_no: string
    current_office_id: string | null
    current_department_id: string | null
  } | null
  is_system_admin: boolean
  has_reports: boolean
  // Verified against BuildSession::execute: `hrOffices: $user->hrAdminOffices()
  // ->pluck('offices.id')->all()` — a list of office UUIDs, not objects.
  hr_offices: string[]
  permissions: string[]
}

export const api = {
  health: (): Promise<Health> => request<Health>('/health'),
  login: (email: string, password: string) =>
    request<{ token: string; user: Session['user'] }>('/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    }),
  logout: () => request<null>('/logout', { method: 'POST' }),
  me: () => request<Session>('/me'),
  myAttendance: (month: string) => request<AttendanceMonth>(`/me/attendance?month=${month}`),
  punch: (direction: PunchDirection, idempotencyKey: string) =>
    request<AttendanceLog>('/attendance/punch', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Idempotency-Key': idempotencyKey },
      body: JSON.stringify({ direction }),
    }),
}
