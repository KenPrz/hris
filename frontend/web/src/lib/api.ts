/**
 * The API client. The one place that knows the envelope from docs/03-api.md, so no
 * component ever unwraps `data` or branches on an HTTP status by hand.
 */

import type { DayType } from '@/components/domain/DayTypeTag'

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

// ---------------------------------------------------------------------------
// Wire type — verified against app/Http/Resources/EmployeeResource.php.
// ---------------------------------------------------------------------------

export type Employee = {
  id: string
  employee_no: string
  current_office_id: string | null
  current_department_id: string | null
  current_reports_to_id: string | null
  hired_at: string | null // YYYY-MM-DD
}

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

// ---------------------------------------------------------------------------
// Wire types — verified against app/Http/Resources/HolidayResource.php.
// ---------------------------------------------------------------------------

export type Holiday = {
  id: string
  office_id: string
  date: string // YYYY-MM-DD
  day_type: DayType
  name: string
}

export type HolidayCreateInput = { office_id: string; date: string; day_type: DayType; name: string }

// No `date` — a holiday's date is fixed once created; the backend rejects it on update.
export type HolidayUpdateInput = { day_type: DayType; name: string }

export type HolidayCloneInput = { office_id: string; from_year: number; to_year: number }

// ---------------------------------------------------------------------------
// Wire types — verified against app/Http/Resources/PayRuleResource.php.
// ---------------------------------------------------------------------------

export type PayRuleDayRate = {
  day_type: DayType
  worked_bp: number
  worked_rest_bp: number
  unworked_bp: number
}

export type PayRule = {
  id: string
  effective_from: string // YYYY-MM-DD
  overtime_ordinary_bp: number
  overtime_premium_bp: number
  night_diff_bp: number
  note: string | null
  day_rates: PayRuleDayRate[]
}

export type PayRuleCreateInput = {
  effective_from: string
  overtime_ordinary_bp: number
  overtime_premium_bp: number
  night_diff_bp: number
  note?: string | null
  day_rates: PayRuleDayRate[]
}

// ---------------------------------------------------------------------------
// Wire types — verified against app/Http/Resources/ShiftTemplateResource.php,
// ScheduleAssignmentResource.php, ScheduleOverrideResource.php, and
// ResolvedScheduleController::toWireShape (app/Http/Controllers/Office/Schedules/).
// ---------------------------------------------------------------------------

export type Weekday = 0 | 1 | 2 | 3 | 4 | 5 | 6
export type ScheduleSource = 'override' | 'employee' | 'department' | 'office_default'

export type ShiftDay = {
  weekday: Weekday
  is_rest: boolean
  start_minute: number | null
  end_minute: number | null
  break_minutes: number | null
}

export type ShiftTemplate = { id: string; office_id: string; name: string; days: ShiftDay[] }

export type ShiftTemplateCreateInput = { office_id: string; name: string; days: ShiftDay[] }

// No `office_id` — the office is fixed by the route-bound template, not the body.
export type ShiftTemplateUpdateInput = { name: string; days: ShiftDay[] }

export type ScheduleAssignment = {
  id: string
  shift_template_id: string
  employee_id: string | null
  department_id: string | null
  effective_from: string // YYYY-MM-DD
}

export type ScheduleAssignmentCreateInput = {
  shift_template_id: string
  employee_id?: string
  department_id?: string
  effective_from: string
}

export type ScheduleOverride = {
  id: string
  employee_id: string
  date: string // YYYY-MM-DD
  is_rest: boolean
  start_minute: number | null
  end_minute: number | null
  break_minutes: number | null
  note: string | null
}

export type ScheduleOverrideCreateInput = {
  employee_id: string
  date: string
  is_rest: boolean
  start_minute?: number | null
  end_minute?: number | null
  break_minutes?: number | null
  note?: string | null
}

// No `employee_id`/`date` — both are fixed by the route-bound override, not the body.
export type ScheduleOverrideUpdateInput = {
  is_rest: boolean
  start_minute?: number | null
  end_minute?: number | null
  break_minutes?: number | null
  note?: string | null
}

export type ResolvedDay = {
  is_rest: boolean
  start_minute: number | null
  end_minute: number | null
  break_minutes: number | null
  scheduled_minutes: number
  source: ScheduleSource
}

/** Keyed by YYYY-MM-DD, one entry per day of the requested month. */
export type ResolvedMonth = Record<string, ResolvedDay>

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
  // No query params — the endpoint scopes to the actor via EmployeeScope::visibleTo and
  // returns every employee that scope covers (which may span more than one office).
  // Callers that need "this office's employees" filter client-side on current_office_id.
  employees: {
    list: () => request<Employee[]>('/employees'),
  },
  myAttendance: (month: string) => request<AttendanceMonth>(`/me/attendance?month=${month}`),
  punch: (direction: PunchDirection, idempotencyKey: string) =>
    request<AttendanceLog>('/attendance/punch', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Idempotency-Key': idempotencyKey },
      body: JSON.stringify({ direction }),
    }),
  holidays: {
    list: (office: string, year: number) =>
      request<Holiday[]>(`/office/holidays?office=${office}&year=${year}`),
    create: (body: HolidayCreateInput) =>
      request<Holiday>('/office/holidays', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    update: (id: string, body: HolidayUpdateInput) =>
      request<Holiday>(`/office/holidays/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    delete: (id: string) => request<undefined>(`/office/holidays/${id}`, { method: 'DELETE' }),
    clone: (body: HolidayCloneInput) =>
      request<Holiday[]>('/office/holidays/clone', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
  },
  payRules: {
    list: () => request<PayRule[]>('/admin/pay-rules'),
    create: (body: PayRuleCreateInput) =>
      request<PayRule>('/admin/pay-rules', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    get: (id: string) => request<PayRule>(`/admin/pay-rules/${id}`),
    delete: (id: string) => request<undefined>(`/admin/pay-rules/${id}`, { method: 'DELETE' }),
  },
  shiftTemplates: {
    list: (office: string) => request<ShiftTemplate[]>(`/office/shift-templates?office=${office}`),
    create: (body: ShiftTemplateCreateInput) =>
      request<ShiftTemplate>('/office/shift-templates', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    get: (id: string) => request<ShiftTemplate>(`/office/shift-templates/${id}`),
    update: (id: string, body: ShiftTemplateUpdateInput) =>
      request<ShiftTemplate>(`/office/shift-templates/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    delete: (id: string) => request<undefined>(`/office/shift-templates/${id}`, { method: 'DELETE' }),
  },
  scheduleAssignments: {
    list: (params: { office: string; employee?: string; department?: string }) => {
      const query = new URLSearchParams({ office: params.office })
      if (params.employee !== undefined) query.set('employee', params.employee)
      if (params.department !== undefined) query.set('department', params.department)
      return request<ScheduleAssignment[]>(`/office/schedule-assignments?${query.toString()}`)
    },
    create: (body: ScheduleAssignmentCreateInput) =>
      request<ScheduleAssignment>('/office/schedule-assignments', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    delete: (id: string) => request<undefined>(`/office/schedule-assignments/${id}`, { method: 'DELETE' }),
  },
  scheduleOverrides: {
    list: (params: { office: string; employee: string; month: string }) =>
      request<ScheduleOverride[]>(
        `/office/schedule-overrides?office=${params.office}&employee=${params.employee}&month=${params.month}`,
      ),
    create: (body: ScheduleOverrideCreateInput) =>
      request<ScheduleOverride>('/office/schedule-overrides', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    update: (id: string, body: ScheduleOverrideUpdateInput) =>
      request<ScheduleOverride>(`/office/schedule-overrides/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    delete: (id: string) => request<undefined>(`/office/schedule-overrides/${id}`, { method: 'DELETE' }),
  },
  officeDefaultTemplate: {
    set: (body: { office_id: string; template_id: string }) =>
      request<{ id: string; default_shift_template_id: string }>('/office/default-template', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
  },
  resolvedSchedule: {
    get: (employee: string, month: string) =>
      request<ResolvedMonth>(`/office/schedule/resolved?employee=${employee}&month=${month}`),
  },
}
