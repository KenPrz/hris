'use client'

/**
 * The org tree (M8a) — organizations, offices, departments. Thin, like `useCutoffs` /
 * `useCloseCutoff`: the query keys come from `keys.ts` so every mutation's invalidation
 * can never drift from the list hooks' fetches.
 *
 * Unlike `useHolidays`/`useShiftTemplates`, these lists are not scoped to a single office
 * a screen is already viewing — they're the sysadmin-only screens that manage the tree
 * itself — so every mutation invalidates the WHOLE list prefix (`keys.admin.offices()`,
 * no params) rather than one office/year combination. TanStack Query matches query keys
 * by array prefix, so that one invalidation call also catches every params-filtered list
 * (e.g. `keys.admin.offices({ organization: id })`) without the mutation needing to know
 * which filter a given screen happens to be viewing.
 *
 * Archive-never-delete: offices and departments have `archive`/`unarchive` mutations,
 * never a delete — mirrors the backend's two dedicated POST endpoints, no DELETE route.
 * Organizations have neither; the org tree's root is never archived.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type {
  AdminDepartmentListParams,
  AdminOfficeListParams,
  Department,
  DepartmentCreateInput,
  DepartmentUpdateInput,
  Office,
  OfficeCreateInput,
  OfficeUpdateInput,
  Organization,
  OrganizationCreateInput,
  OrganizationUpdateInput,
} from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

// ---------------------------------------------------------------------------
// Organizations
// ---------------------------------------------------------------------------

export function useOrganizations() {
  return useQuery<Organization[]>({
    queryKey: keys.admin.organizations(),
    queryFn: () => api.admin.organizations.list(),
  })
}

function useInvalidateOrganizations() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: keys.admin.organizations() })
}

export function useCreateOrganization() {
  const invalidate = useInvalidateOrganizations()

  return useMutation<Organization, unknown, OrganizationCreateInput>({
    mutationFn: (body) => api.admin.organizations.create(body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useUpdateOrganization() {
  const invalidate = useInvalidateOrganizations()

  return useMutation<Organization, unknown, { id: string; body: OrganizationUpdateInput }>({
    mutationFn: ({ id, body }) => api.admin.organizations.update(id, body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

// ---------------------------------------------------------------------------
// Offices
// ---------------------------------------------------------------------------

export function useOffices(params?: AdminOfficeListParams) {
  return useQuery<Office[]>({
    queryKey: keys.admin.offices(params),
    queryFn: () => api.admin.offices.list(params),
  })
}

function useInvalidateOffices() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: keys.admin.offices() })
}

export function useCreateOffice() {
  const invalidate = useInvalidateOffices()

  return useMutation<Office, unknown, OfficeCreateInput>({
    mutationFn: (body) => api.admin.offices.create(body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useUpdateOffice() {
  const invalidate = useInvalidateOffices()

  return useMutation<Office, unknown, { id: string; body: OfficeUpdateInput }>({
    mutationFn: ({ id, body }) => api.admin.offices.update(id, body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useArchiveOffice() {
  const invalidate = useInvalidateOffices()

  return useMutation<Office, unknown, string>({
    mutationFn: (id) => api.admin.offices.archive(id),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useUnarchiveOffice() {
  const invalidate = useInvalidateOffices()

  return useMutation<Office, unknown, string>({
    mutationFn: (id) => api.admin.offices.unarchive(id),
    onSuccess: () => {
      void invalidate()
    },
  })
}

// ---------------------------------------------------------------------------
// Departments
// ---------------------------------------------------------------------------

export function useDepartments(params?: AdminDepartmentListParams) {
  return useQuery<Department[]>({
    queryKey: keys.admin.departments(params),
    queryFn: () => api.admin.departments.list(params),
  })
}

function useInvalidateDepartments() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: keys.admin.departments() })
}

export function useCreateDepartment() {
  const invalidate = useInvalidateDepartments()

  return useMutation<Department, unknown, DepartmentCreateInput>({
    mutationFn: (body) => api.admin.departments.create(body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useUpdateDepartment() {
  const invalidate = useInvalidateDepartments()

  return useMutation<Department, unknown, { id: string; body: DepartmentUpdateInput }>({
    mutationFn: ({ id, body }) => api.admin.departments.update(id, body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useArchiveDepartment() {
  const invalidate = useInvalidateDepartments()

  return useMutation<Department, unknown, string>({
    mutationFn: (id) => api.admin.departments.archive(id),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useUnarchiveDepartment() {
  const invalidate = useInvalidateDepartments()

  return useMutation<Department, unknown, string>({
    mutationFn: (id) => api.admin.departments.unarchive(id),
    onSuccess: () => {
      void invalidate()
    },
  })
}
