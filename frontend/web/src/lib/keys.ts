/**
 * The query-key factory. Keys come from here, never a string literal, so that cache
 * invalidation is a typed prefix — `queryClient.invalidateQueries({ queryKey: keys.attendance.all() })`-
 * style calls (via `['attendance']`) match every month key, because TanStack Query
 * matches query keys by array prefix.
 *
 * Keep this minimal: only the keys the hooks actually consume. Do not add keys for
 * endpoints that don't exist yet.
 */

import type { ActivityFilters, AdminDepartmentListParams, AdminEmployeeListParams, AdminOfficeListParams } from './api'

export const keys = {
  session: () => ['session'] as const,
  employees: {
    all: () => ['employees'] as const,
  },
  attendance: {
    all: () => ['attendance'] as const,
    month: (month: string) => ['attendance', 'month', month] as const,
    summary: (month: string) => ['attendance', 'summary', month] as const,
  },
  holidays: {
    all: () => ['holidays'] as const,
    forOfficeYear: (officeId: string, year: number) => ['holidays', officeId, year] as const,
  },
  payRules: {
    all: () => ['pay-rules'] as const,
  },
  requests: {
    all: () => ['requests'] as const,
    mine: () => ['requests', 'mine'] as const,
    detail: (id: string) => ['requests', 'detail', id] as const,
    teamApprovals: () => ['requests', 'team-approvals'] as const,
    officeApprovals: () => ['requests', 'office-approvals'] as const,
  },
  leave: {
    types: (officeId: string) => ['leave', 'types', officeId] as const,
    myBalances: () => ['leave', 'my-balances'] as const,
    employeeBalances: (employeeId: string) => ['leave', 'employee-balances', employeeId] as const,
  },
  cutoffs: {
    list: (officeId: string) => ['cutoffs', officeId] as const,
  },
  payrollExport: {
    forPeriod: (periodId: string) => ['payroll-export', periodId] as const,
  },
  schedules: {
    templates: (officeId: string) => ['schedules', 'templates', officeId] as const,
    assignments: (officeId: string) => ['schedules', 'assignments', officeId] as const,
    overrides: (employeeId: string, month: string) => ['schedules', 'overrides', employeeId, month] as const,
    resolved: (employeeId: string, month: string) => ['schedules', 'resolved', employeeId, month] as const,
    // The prefix over every resolved query, for any employee/month. A template, assignment,
    // or office-default change alters what `ScheduleResolver` produces for potentially any
    // employee, so those mutations invalidate this whole prefix — not one employee's key.
    resolvedAll: () => ['schedules', 'resolved'] as const,
  },
  // The org tree (M8a). `offices`/`departments` take their list params as a trailing
  // key segment when present, but every mutation invalidates the no-params form
  // (`keys.admin.offices()`/`departments()`) — TanStack Query matches query keys by
  // array PREFIX, so invalidating the shorter key catches every params-filtered list
  // too, without the mutation needing to know which filter the screen is viewing.
  admin: {
    organizations: () => ['admin', 'organizations'] as const,
    offices: (params?: AdminOfficeListParams) =>
      params !== undefined ? (['admin', 'offices', params] as const) : (['admin', 'offices'] as const),
    departments: (params?: AdminDepartmentListParams) =>
      params !== undefined
        ? (['admin', 'departments', params] as const)
        : (['admin', 'departments'] as const),
    // The employee profiler (M8b). Same prefix-invalidation shape as offices/departments:
    // every write invalidates the no-params `employees()` key, which by array-prefix match
    // catches every params-filtered list too. `employee(id)` is the single-detail key.
    employees: (params?: AdminEmployeeListParams) =>
      params !== undefined ? (['admin', 'employees', params] as const) : (['admin', 'employees'] as const),
    employee: (id: string) => ['admin', 'employee', id] as const,
    // The audit viewer (M8c). Unlike offices/employees/departments above, there is no
    // no-params form to invalidate against — the viewer is read-only, nothing ever writes
    // through it — so every distinct filter set (page included) gets its own cache entry
    // rather than sharing a prefix meant for invalidation.
    activity: (filters?: ActivityFilters) =>
      filters !== undefined ? (['admin', 'activity', filters] as const) : (['admin', 'activity'] as const),
  },
}
