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
  attendance: {
    all: () => ['attendance'] as const,
    month: (month: string) => ['attendance', 'month', month] as const,
  },
}
