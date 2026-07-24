/**
 * The query-key factory. Keys come from here, never a string literal, so that cache
 * invalidation is a typed prefix — `queryClient.invalidateQueries({ queryKey: keys.attendance.all() })`-
 * style calls (via `['attendance']`) match every month key, because TanStack Query
 * matches query keys by array prefix.
 *
 * Keep this minimal: only the keys the hooks actually consume. Do not add keys for
 * endpoints that don't exist yet.
 */

export const keys = {
  session: () => ['session'] as const,
  employees: {
    all: () => ['employees'] as const,
  },
  attendance: {
    all: () => ['attendance'] as const,
    month: (month: string) => ['attendance', 'month', month] as const,
  },
  holidays: {
    all: () => ['holidays'] as const,
    forOfficeYear: (officeId: string, year: number) => ['holidays', officeId, year] as const,
  },
  schedules: {
    templates: (officeId: string) => ['schedules', 'templates', officeId] as const,
    assignments: (officeId: string) => ['schedules', 'assignments', officeId] as const,
    overrides: (employeeId: string, month: string) => ['schedules', 'overrides', employeeId, month] as const,
    resolved: (employeeId: string, month: string) => ['schedules', 'resolved', employeeId, month] as const,
  },
}
