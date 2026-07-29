'use client'

/**
 * The employee profiler (M8b) — the admin roster plus its write paths, sysadmin-gated.
 * Thin, mirroring `useAdminOrgTree`: the query keys come from `keys.ts` so a mutation's
 * invalidation can never drift from the list/detail hooks' fetches.
 *
 * Like the org tree, the roster is a sysadmin-only screen rather than an office view, so
 * every write invalidates the WHOLE list prefix (`keys.admin.employees()`, no params) —
 * TanStack Query matches query keys by array prefix, so that one call also catches every
 * `?office=` filtered list. Writes that touch a single record (update / provision-user /
 * record-employment) ALSO invalidate that employee's detail key so an open profile refetches.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type {
  AdminEmployee,
  AdminEmployeeCreateInput,
  AdminEmployeeDetail,
  AdminEmployeeListParams,
  AdminEmployeeUpdateInput,
  CreatedEmployee,
  EmploymentInput,
  EmploymentRecord,
  ProvisionedUser,
  ProvisionUserInput,
  SetHrOfficesInput,
} from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useAdminEmployees(params?: AdminEmployeeListParams) {
  return useQuery<AdminEmployee[]>({
    queryKey: keys.admin.employees(params),
    queryFn: () => api.admin.employees.list(params),
  })
}

export function useAdminEmployee(id: string | null) {
  return useQuery<AdminEmployeeDetail>({
    queryKey: keys.admin.employee(id ?? ''),
    queryFn: () => api.admin.employees.show(id as string),
    enabled: id !== null,
  })
}

function useInvalidateEmployees() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: keys.admin.employees() })
}

export function useCreateEmployee() {
  const invalidate = useInvalidateEmployees()

  return useMutation<CreatedEmployee, unknown, AdminEmployeeCreateInput>({
    mutationFn: (body) => api.admin.employees.create(body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useUpdateEmployee() {
  const queryClient = useQueryClient()

  return useMutation<CreatedEmployee, unknown, { id: string; body: AdminEmployeeUpdateInput }>({
    mutationFn: ({ id, body }) => api.admin.employees.update(id, body),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: keys.admin.employees() })
      void queryClient.invalidateQueries({ queryKey: keys.admin.employee(id) })
    },
  })
}

export function useProvisionUser() {
  const queryClient = useQueryClient()

  return useMutation<ProvisionedUser, unknown, { id: string; body: ProvisionUserInput }>({
    mutationFn: ({ id, body }) => api.admin.employees.provisionUser(id, body),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: keys.admin.employees() })
      void queryClient.invalidateQueries({ queryKey: keys.admin.employee(id) })
    },
  })
}

export function useRecordEmployment() {
  const queryClient = useQueryClient()

  return useMutation<EmploymentRecord, unknown, { id: string; body: EmploymentInput }>({
    mutationFn: ({ id, body }) => api.admin.employees.recordEmployment(id, body),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: keys.admin.employees() })
      void queryClient.invalidateQueries({ queryKey: keys.admin.employee(id) })
    },
  })
}

// M8c: office admin access. Syncs hr_admin_offices + the HR Admin role in one write;
// the response is the refreshed detail, so invalidating (rather than merging locally)
// is enough for the panel to reflect the new office list + role membership.
export function useSetHrOffices() {
  const queryClient = useQueryClient()

  return useMutation<AdminEmployeeDetail, unknown, { id: string; body: SetHrOfficesInput }>({
    mutationFn: ({ id, body }) => api.admin.employees.setHrOffices(id, body),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: keys.admin.employees() })
      void queryClient.invalidateQueries({ queryKey: keys.admin.employee(id) })
    },
  })
}
