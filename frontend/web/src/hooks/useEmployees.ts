'use client'

/**
 * Every employee the current actor's scope covers (`EmployeeScope::visibleTo`) — there is
 * no office-scoped list endpoint, so a caller that needs "this office's employees" (e.g.
 * the schedule-assignment target picker on `/office/schedules`) filters the result on
 * `current_office_id` itself. One query for the whole app: nothing here narrows by office,
 * so every caller shares the same cache entry.
 */

import { useQuery } from '@tanstack/react-query'

import type { Employee } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useEmployees() {
  return useQuery<Employee[]>({
    queryKey: keys.employees.all(),
    queryFn: () => api.employees.list(),
  })
}
