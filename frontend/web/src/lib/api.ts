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
// Wire types — verified against app/Http/Resources/DailySummaryResource.php.
// ---------------------------------------------------------------------------

export type SummaryLineKind =
  | 'regular_day'
  | 'regular_night'
  | 'overtime_day'
  | 'overtime_night'
  | 'holiday_unworked'
  | 'leave_with_pay'

export type DailySummaryLine = {
  kind: SummaryLineKind
  minutes: number
  applied_bp: number
}

/**
 * One priced day. `day_type` reuses `PayRuleDayType` (all five values, including
 * `ordinary`) rather than `DayTypeTag`'s four-value holiday-only `DayType` — a summary
 * prices every day an employee can work, ordinary days included, not just holidays.
 */
export type DailySummary = {
  date: string // YYYY-MM-DD
  day_type: PayRuleDayType
  is_rest_day: boolean
  scheduled_minutes: number
  is_art82_exempt: boolean
  worked_minutes: number
  late_minutes: number
  undertime_minutes: number
  unpaid_overtime_minutes: number
  status: string
  is_incomplete: boolean
  rule_version_id: string | null
  lines: DailySummaryLine[]
}

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

// The full backend App\Domain\Pay\DayType set — all FIVE values, including `ordinary`.
// This is deliberately wider than DayTypeTag's `DayType` (which is the holiday subset:
// a holiday is never `ordinary`). A pay rule must price every kind of day an employee
// can work, so `CreatePayRuleRequest` mandates exactly these five day_rates.
export type PayRuleDayType =
  | 'ordinary'
  | 'special_working'
  | 'special_non_working'
  | 'regular_holiday'
  | 'double_regular_holiday'

export type PayRuleDayRate = {
  day_type: PayRuleDayType
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

// ---------------------------------------------------------------------------
// Wire types — verified against app/Http/Resources/LeaveTypeResource.php,
// LeaveBalanceResource.php, and LeaveLedgerResource.php, and the leave_ledger
// migration's CHECK constraints (entry_type, source).
// ---------------------------------------------------------------------------

export type LeaveUnitName = 'day' | 'half_shift' | 'hour' | 'minute'

export type LeaveType = {
  id: string
  office_id: string
  name: string
  code: string | null
  is_paid: boolean
  requires_attachment: boolean
  deducts_balance: boolean
  is_cash_convertible: boolean
  max_carryover_minutes: number | null
  is_active: boolean
}

// `office_id` is required to create a type (it belongs to an office) but UpdateLeaveTypeController
// never reads it from the body — the route-bound type fixes it — so it is optional here rather
// than a second, near-duplicate input type.
export type LeaveTypeInput = Omit<LeaveType, 'id' | 'office_id'> & { office_id?: string }

export type LeaveBalance = {
  leave_type: LeaveType
  balance_minutes: number
  balance_readable: { days: number; hours: number; minutes: number }
}

export type LeaveEntryType = 'credit' | 'debit'
export type LeaveLedgerSource = 'manual_grant'

export type LeaveLedgerEntry = {
  id: string
  employee_id: string
  leave_type_id: string
  entry_type: LeaveEntryType
  minutes: number
  reason: string
  source: LeaveLedgerSource
  created_by: string
  created_at: string // ISO8601
}

export type LeaveGrantInput = {
  employee_id: string
  leave_type_id: string
  amount: number
  unit: LeaveUnitName
  reason: string
}

// ---------------------------------------------------------------------------
// Wire types — verified against app/Http/Resources/RequestResource.php,
// app/Http/Controllers/Attendance/SubmitController.php,
// app/Http/Controllers/Leave/SubmitLeaveRequestController.php, and
// app/Http/Controllers/Overtime/SubmitOvertimeRequestController.php. `RequestResource#detail`
// branches on `type`: an `attendance_adjustment` request carries `AttendanceAdjustmentDetail`,
// a `leave` request carries `LeaveRequestDetail`, and an `overtime` request carries
// `OvertimeRequestDetail`. `RequestDetail` is their union (plus `null`, which the resource
// returns for a still-unbacked corner) so every consumer of `RequestRecord.detail` narrows on
// `request.type` before reading type-specific fields.
// ---------------------------------------------------------------------------

export type RequestState = 'pending' | 'manager_approved' | 'approved' | 'rejected' | 'cancelled'
export type RequestType = 'attendance_adjustment' | 'leave' | 'overtime'
export type AdjustmentOperation = 'add' | 'void' | 'amend'

export type AttendanceAdjustmentDetail = {
  operation: AdjustmentOperation
  target_log_id: string | null
  direction: PunchDirection | null
  punched_at: string | null // ISO8601
}

export type LeaveDayPart = 'full' | 'half'

export type LeaveRequestDetail = {
  leave_type_id: string
  start_date: string // YYYY-MM-DD
  end_date: string // YYYY-MM-DD
  day_part: LeaveDayPart
  amount_minutes: number
}

export type OvertimeRequestDetail = {
  date: string // YYYY-MM-DD
  minutes: number
}

export type RequestDetail = AttendanceAdjustmentDetail | LeaveRequestDetail | OvertimeRequestDetail | null

export type RequestRecord = {
  id: string
  type: RequestType
  state: RequestState
  note: string
  employee_id: string
  detail: RequestDetail
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  has_attachment: boolean
}

export type CorrectionInput = {
  operation: AdjustmentOperation
  note: string
  target_log_id?: string
  direction?: PunchDirection
  punched_at?: string // ISO8601
  attachment?: File | null
}

export type LeaveRequestInput = {
  leave_type_id: string
  start_date: string // YYYY-MM-DD
  end_date: string // YYYY-MM-DD
  day_part: LeaveDayPart
  note: string
  attachment?: File | null
}

export type OvertimeRequestInput = {
  date: string // YYYY-MM-DD
  hours: number
  note: string
}

// ---------------------------------------------------------------------------
// Wire types — verified against app/Http/Resources/CutoffPeriodResource.php and
// app/Http/Controllers/Cutoff/ListCutoffsController.php. The list returns every stored
// period PLUS one synthetic "current window" entry whose `id` is null — the office's
// still-running, not-yet-persisted window (a CutoffPeriod row exists only once CloseCutoff
// has touched it). That null-id entry is the "Close current period" target: a close is
// keyed by `period_start`, not by id, so a null id never blocks it. Only a stored (closed)
// period carries a real id, which is what a reopen needs for its route binding.
// ---------------------------------------------------------------------------

export type CutoffState = 'open' | 'closed'

export type CutoffPeriod = {
  id: string | null
  office_id: string
  start_date: string // YYYY-MM-DD
  end_date: string // YYYY-MM-DD
  state: CutoffState
  closed_by: string | null
  closed_at: string | null // ISO8601, or null while open
}

// A close targets a window by its start date (the synthetic current-window entry has no id
// to key off), never by id — mirrors CloseCutoffRequest's { office_id, period_start }.
export type CutoffCloseInput = { office_id: string; period_start: string }

// Wire types — verified against app/Http/Resources/PayrollExportResource.php. Reuses
// SummaryLineKind (DailySummaryResource's line kinds) rather than redefining it — a payroll
// earnings line and a daily summary line carry the same closed set of kinds.
export type PayrollEarningsLine = {
  kind: SummaryLineKind
  applied_bp: number
  rule_version_id: string | null
  minutes: number
}

export type PayrollEmployeeExport = {
  employee: { id: string; employee_no: string; base_rate_cents: number | null }
  base_rate_segments: { effective_from: string; base_rate_cents: number }[]
  totals: { worked_minutes: number; late_minutes: number; undertime_minutes: number; unpaid_overtime_minutes: number }
  lines: PayrollEarningsLine[]
  has_incomplete_days: boolean
}

export type PayrollExport = {
  period: { id: string; office_id: string; start_date: string; end_date: string; state: 'open' | 'closed' }
  employees: PayrollEmployeeExport[]
}

/** The shape of `error.details` on a `cutoff_has_unresolved_exceptions` 422 (CloseCutoff's
 * strict gate). Both lists point an operator at exactly what still blocks the close. */
export type CutoffUnresolvedDetails = {
  incomplete_dates: string[]
  pending_request_ids: string[]
}

// ---------------------------------------------------------------------------
// Wire types — verified against app/Http/Resources/OrganizationResource.php,
// OfficeResource.php, DepartmentResource.php, and their Create/Update FormRequests
// (M8a: the org tree). Archive-never-delete: `archived_at` is the only lifecycle field
// on Office/Department (Organization has none — it is never archived), toggled by the
// dedicated archive/unarchive endpoints rather than a DELETE route.
// ---------------------------------------------------------------------------

export type Organization = {
  id: string
  name: string
  legal_name: string | null
  tin: string | null
  timezone: string
}

// Create and update take the identical full-object shape (both FormRequests validate
// the same fields, PATCH included) — verified against CreateOrganizationRequest and
// UpdateOrganizationRequest, which mirror each other field-for-field.
export type OrganizationCreateInput = {
  name: string
  legal_name?: string | null
  tin?: string | null
  timezone: string
}

export type OrganizationUpdateInput = OrganizationCreateInput

export type Office = {
  id: string
  organization_id: string
  name: string
  code: string
  timezone: string
  geofence_lat: number | null
  geofence_lng: number | null
  geofence_radius_m: number | null
  ip_allowlist: string[] | null
  default_shift_template_id: string | null
  archived_at: string | null // ISO8601, or null while active
}

// Create and update take the identical full-object shape — verified against
// CreateOfficeRequest and UpdateOfficeRequest, which mirror each other field-for-field.
export type OfficeCreateInput = {
  organization_id: string
  name: string
  code: string
  timezone: string
  geofence_lat?: number | null
  geofence_lng?: number | null
  geofence_radius_m?: number | null
  ip_allowlist?: string[] | null
  default_shift_template_id?: string | null
}

export type OfficeUpdateInput = OfficeCreateInput

export type Department = {
  id: string
  office_id: string
  name: string
  code: string
  archived_at: string | null // ISO8601, or null while active
}

// Create and update take the identical full-object shape — verified against
// CreateDepartmentRequest and UpdateDepartmentRequest, which mirror each other
// field-for-field.
export type DepartmentCreateInput = { office_id: string; name: string; code: string }

export type DepartmentUpdateInput = DepartmentCreateInput

export type AdminOfficeListParams = { include_archived?: boolean; organization?: string }
export type AdminDepartmentListParams = { include_archived?: boolean; office?: string }

// ---------------------------------------------------------------------------
// Wire types — verified against app/Http/Resources/EmployeeListResource.php,
// EmployeeDetailResource.php, EmployeeResource.php, EmploymentRecordResource.php,
// and their Create/Update/RecordEmployment/ProvisionUser FormRequests + controllers
// (M8b: the employee profiler). All is_system_admin-gated. The three name-carrying
// resources share id/employee_no/{first,middle,last,suffix}_name/full_name; the list
// adds current_office_id/current_department_id/has_user, the detail adds hired_at +
// a resolved current_employment block (or null for a brand-new hire with no record yet).
// ---------------------------------------------------------------------------

// The three employment types the wizard offers. The backend validates `employment_type`
// as a free string, so this is the frontend's own closed set, not a server-enforced enum.
export type EmploymentType = 'regular' | 'probationary' | 'contractual'

export type AdminEmployee = {
  id: string
  employee_no: string
  first_name: string
  middle_name: string | null
  last_name: string
  name_suffix: string | null
  full_name: string
  current_office_id: string | null
  current_department_id: string | null
  has_user: boolean
}

// One employment segment — the body POST /employees/{id}/employment (RecordEmployment)
// takes, and the `employment` sub-block CreateEmployee nests. `reports_to_id` is optional
// (nullable on the wire); everything else is required to price a day.
export type EmploymentInput = {
  effective_from: string // YYYY-MM-DD
  office_id: string
  department_id: string
  reports_to_id?: string | null
  employment_type: string
  is_art82_exempt: boolean
  base_rate_cents: number
}

export type AdminEmployeeDetail = {
  id: string
  employee_no: string
  first_name: string
  middle_name: string | null
  last_name: string
  name_suffix: string | null
  full_name: string
  hired_at: string | null // YYYY-MM-DD
  has_user: boolean
  current_employment: {
    office_id: string
    department_id: string
    employment_type: string
    is_art82_exempt: boolean
    base_rate_cents: number
    reports_to_id: string | null
    effective_from: string | null // YYYY-MM-DD
  } | null
}

// POST /admin/employees returns EmployeeResource — a wider name+current-pointers shape
// than the list resource (it carries current_reports_to_id + hired_at, but no has_user).
// The wizard only reads `.id`, but the type stays faithful to the endpoint's real payload.
export type CreatedEmployee = {
  id: string
  employee_no: string
  first_name: string
  middle_name: string | null
  last_name: string
  name_suffix: string | null
  full_name: string
  current_office_id: string | null
  current_department_id: string | null
  current_reports_to_id: string | null
  hired_at: string | null // YYYY-MM-DD
}

export type AdminEmployeeCreateInput = {
  employee_no: string
  organization_id: string
  hired_at: string // YYYY-MM-DD
  first_name: string
  middle_name?: string | null
  last_name: string
  name_suffix?: string | null
  employment: EmploymentInput
}

// No employee_no/organization — employee_no is immutable after creation and this endpoint
// only edits the name fields (UpdateEmployeeRequest).
export type AdminEmployeeUpdateInput = {
  first_name: string
  middle_name?: string | null
  last_name: string
  name_suffix?: string | null
}

// ---------------------------------------------------------------------------
// Wire types — verified against app/Http/Resources/ActivityResource.php and
// app/Http/Controllers/Admin/ListActivityController.php (M8c: the audit viewer).
// `properties` is Spatie activitylog's arbitrary per-event payload — a bag of whatever
// the logging call captured, so it stays `Record<string, unknown>` rather than a closed
// shape. `meta` is the hand-built pagination block the controller returns instead of
// Laravel's default paginated-resource wrapper (see that controller's own comment).
// ---------------------------------------------------------------------------

export type ActivityEntry = {
  id: string
  log_name: string
  description: string
  event: string
  subject_type: string | null
  subject_id: string | null
  causer_id: string | null
  properties: Record<string, unknown>
  created_at: string // ISO8601
}

export type ActivityPage = {
  data: ActivityEntry[]
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}

export type ActivityFilters = {
  log_name?: string
  event?: string
  subject_type?: string
  causer_id?: string
  from?: string
  to?: string
  page?: number
}

export type ProvisionUserInput = { email: string; password: string; name: string }

// POST /admin/employees/{id}/user returns UserResource.
export type ProvisionedUser = { id: string; name: string; email: string }

// The employment segment POST /employees/{id}/employment returns (EmploymentRecordResource).
export type EmploymentRecord = {
  id: string
  employee_id: string
  effective_from: string | null // YYYY-MM-DD
  office_id: string
  department_id: string
  reports_to_id: string | null
  employment_type: string
  is_art82_exempt: boolean
  base_rate_cents: number
}

export type AdminEmployeeListParams = { office?: string }

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
  attendance: {
    summary: (month: string) => request<DailySummary[]>(`/me/attendance/summary?month=${month}`),
  },
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
  leave: {
    types: (office: string) => request<LeaveType[]>(`/office/leave-types?office=${office}`),
    createType: (body: LeaveTypeInput) =>
      request<LeaveType>('/office/leave-types', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    updateType: (id: string, body: LeaveTypeInput) =>
      request<LeaveType>(`/office/leave-types/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    setLeaveDay: (office_id: string, minutes_per_leave_day: number) =>
      request<{ id: string; minutes_per_leave_day: number }>('/office/leave-day', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ office_id, minutes_per_leave_day }),
      }),
    myBalances: () => request<LeaveBalance[]>('/me/leave'),
    employeeBalances: (employeeId: string) => request<LeaveBalance[]>(`/employees/${employeeId}/leave`),
    grant: (body: LeaveGrantInput) =>
      request<LeaveLedgerEntry>('/leave/grants', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    // Multipart, mirroring `api.adjustments.submit`: build FormData and DO NOT set
    // Content-Type — the browser must set the multipart boundary itself.
    submitRequest: (input: LeaveRequestInput) => {
      const form = new FormData()
      form.set('leave_type_id', input.leave_type_id)
      form.set('start_date', input.start_date)
      form.set('end_date', input.end_date)
      form.set('day_part', input.day_part)
      form.set('note', input.note)
      if (input.attachment) form.set('attachment', input.attachment)
      return request<RequestRecord>('/leave/requests', { method: 'POST', body: form })
    },
  },
  overtime: {
    submitRequest: (input: OvertimeRequestInput) =>
      request<RequestRecord>('/overtime/requests', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(input),
      }),
  },
  requests: {
    mine: () => request<RequestRecord[]>('/requests'),
    get: (id: string) => request<RequestRecord>(`/requests/${id}`),
    teamApprovals: () => request<RequestRecord[]>('/team/approvals'),
    officeApprovals: () => request<RequestRecord[]>('/office/approvals'),
    approve: (id: string) => request<RequestRecord>(`/requests/${id}/approve`, { method: 'POST' }),
    reject: (id: string, decision_note: string) =>
      request<RequestRecord>(`/requests/${id}/reject`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ decision_note }),
      }),
    cancel: (id: string) => request<RequestRecord>(`/requests/${id}/cancel`, { method: 'POST' }),
  },
  cutoffs: {
    list: (office: string) => request<CutoffPeriod[]>(`/office/cutoffs?office=${office}`),
    close: (body: CutoffCloseInput) =>
      request<CutoffPeriod>('/office/cutoffs/close', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    reopen: (id: string, body: { reason: string }) =>
      request<CutoffPeriod>(`/office/cutoffs/${id}/reopen`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    export: (periodId: string) => request<PayrollExport>(`/office/cutoffs/${periodId}/export`),
  },
  adjustments: {
    // Multipart: build FormData and DO NOT set Content-Type — the browser must set the
    // multipart boundary itself. `request` only adds Accept + Authorization, so a FormData
    // body passes through with the right content type. Matches SubmitController's fields.
    submit: (input: CorrectionInput) => {
      const form = new FormData()
      form.set('operation', input.operation)
      form.set('note', input.note)
      if (input.target_log_id !== undefined) form.set('target_log_id', input.target_log_id)
      if (input.direction !== undefined) form.set('direction', input.direction)
      if (input.punched_at !== undefined) form.set('punched_at', input.punched_at)
      if (input.attachment) form.set('attachment', input.attachment)
      return request<RequestRecord>('/attendance/adjustments', { method: 'POST', body: form })
    },
  },
  admin: {
    // The org tree's root — global config, sysadmin-gated. Mirrors `payRules` above:
    // no office to scope by, so `list` takes no params.
    organizations: {
      list: () => request<Organization[]>('/admin/organizations'),
      create: (body: OrganizationCreateInput) =>
        request<Organization>('/admin/organizations', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        }),
      update: (id: string, body: OrganizationUpdateInput) =>
        request<Organization>(`/admin/organizations/${id}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        }),
    },
    // The org tree's second tier. Archive-never-delete: no `delete`, only the two
    // dedicated transitions on `archived_at` — mirrors ArchiveController/UnarchiveController,
    // both POST with no body.
    offices: {
      list: (params?: AdminOfficeListParams) => {
        const query = new URLSearchParams()
        if (params?.include_archived) query.set('include_archived', 'true')
        if (params?.organization !== undefined) query.set('organization', params.organization)
        const qs = query.toString()
        return request<Office[]>(`/admin/offices${qs !== '' ? `?${qs}` : ''}`)
      },
      create: (body: OfficeCreateInput) =>
        request<Office>('/admin/offices', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        }),
      update: (id: string, body: OfficeUpdateInput) =>
        request<Office>(`/admin/offices/${id}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        }),
      archive: (id: string) => request<Office>(`/admin/offices/${id}/archive`, { method: 'POST' }),
      unarchive: (id: string) => request<Office>(`/admin/offices/${id}/unarchive`, { method: 'POST' }),
    },
    // The org tree's third tier — same archive-never-delete shape as offices above, keyed
    // by `office` (not `organization`) on the list endpoint.
    departments: {
      list: (params?: AdminDepartmentListParams) => {
        const query = new URLSearchParams()
        if (params?.include_archived) query.set('include_archived', 'true')
        if (params?.office !== undefined) query.set('office', params.office)
        const qs = query.toString()
        return request<Department[]>(`/admin/departments${qs !== '' ? `?${qs}` : ''}`)
      },
      create: (body: DepartmentCreateInput) =>
        request<Department>('/admin/departments', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        }),
      update: (id: string, body: DepartmentUpdateInput) =>
        request<Department>(`/admin/departments/${id}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        }),
      archive: (id: string) => request<Department>(`/admin/departments/${id}/archive`, { method: 'POST' }),
      unarchive: (id: string) =>
        request<Department>(`/admin/departments/${id}/unarchive`, { method: 'POST' }),
    },
    // The employee profiler (M8b), sysadmin-gated. Distinct from the top-level
    // self-service `api.employees` (the actor's own visible roster): this is the admin
    // roster, keyed by `office`, plus the create/update/provision-user/record-employment
    // write paths. Mirrors the JSON-POST style of `offices` above.
    employees: {
      list: (params?: AdminEmployeeListParams) => {
        const query = new URLSearchParams()
        if (params?.office !== undefined) query.set('office', params.office)
        const qs = query.toString()
        return request<AdminEmployee[]>(`/admin/employees${qs !== '' ? `?${qs}` : ''}`)
      },
      show: (id: string) => request<AdminEmployeeDetail>(`/admin/employees/${id}`),
      create: (body: AdminEmployeeCreateInput) =>
        request<CreatedEmployee>('/admin/employees', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        }),
      update: (id: string, body: AdminEmployeeUpdateInput) =>
        request<CreatedEmployee>(`/admin/employees/${id}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        }),
      provisionUser: (id: string, body: ProvisionUserInput) =>
        request<ProvisionedUser>(`/admin/employees/${id}/user`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        }),
      recordEmployment: (id: string, body: EmploymentInput) =>
        request<EmploymentRecord>(`/admin/employees/${id}/employment`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        }),
    },
    // The audit viewer (M8c), sysadmin-gated. Every filter is optional — only non-empty
    // values are sent, mirroring `offices.list`'s query-string construction — and `page`
    // is a number rather than a string, so it's coerced with `String()` rather than `set`.
    activity: {
      list: (filters?: ActivityFilters) => {
        const query = new URLSearchParams()
        if (filters?.log_name !== undefined && filters.log_name !== '') query.set('log_name', filters.log_name)
        if (filters?.event !== undefined && filters.event !== '') query.set('event', filters.event)
        if (filters?.subject_type !== undefined && filters.subject_type !== '')
          query.set('subject_type', filters.subject_type)
        if (filters?.causer_id !== undefined && filters.causer_id !== '') query.set('causer_id', filters.causer_id)
        if (filters?.from !== undefined && filters.from !== '') query.set('from', filters.from)
        if (filters?.to !== undefined && filters.to !== '') query.set('to', filters.to)
        if (filters?.page !== undefined) query.set('page', String(filters.page))
        const qs = query.toString()
        return request<ActivityPage>(`/admin/activity${qs !== '' ? `?${qs}` : ''}`)
      },
    },
  },
}
