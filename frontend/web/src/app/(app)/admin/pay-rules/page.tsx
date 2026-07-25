'use client'

/**
 * The sysadmin's view of the company-wide pay-rule matrix — a **matrix editor**, not a
 * calendar (unlike `/office/holidays`, which this scaffold otherwise mirrors: AppShell,
 * SectionHeader, loading/error via Skeleton/InlineNotification, a Dialog-driven create
 * flow with mutation + invalidation). There is no office picker: `PayRuleResource`'s
 * routes are gated by `is_system_admin`, not `OfficeScope` — a pay rule prices every
 * office the same way.
 *
 * Versions are immutable (no PATCH route on the backend) — a correction is always a new
 * version, read alongside the old ones, never an edit in place. "Currently effective" is
 * the version with the greatest `effective_from <= today`, computed client-side from
 * `usePayRules()`'s list rather than trusted to arrive in a particular order.
 *
 * The floor hint shown per cell in the New-version dialog is a client-side courtesy only
 * — `PAY_FLOOR_PERCENT` mirrors `config('hris.pay_floors')` as percentages so an admin
 * sees a problem before submitting. The server is the authority: a 422
 * `pay_rate_below_floor` is what actually blocks a below-floor write, and its
 * `details.violations` is what this screen renders against the offending cells after the
 * fact — the client hint and the server response use the same `multiplier` key shape
 * (`worked.{day_type}.not_rest` / `.rest`, `unworked.{day_type}`, or a bare scalar name)
 * so one lookup renders both.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'

import { AppShell } from '@/components/AppShell'
import { SectionHeader } from '@/components/SectionHeader'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Skeleton } from '@/components/ui/Skeleton'
import { TextInput } from '@/components/ui/TextInput'
import type { PayRule, PayRuleCreateInput, PayRuleDayRate, PayRuleDayType } from '@/lib/api'
import { ApiError } from '@/lib/api'
import { bpToPercent, percentToBp } from '@/lib/basisPoints'
import { todayInZone } from '@/lib/date'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'
import { useCreatePayRule, usePayRules } from '@/hooks/usePayRules'
import { useSession } from '@/hooks/useSession'

// The full backend App\Domain\Pay\DayType set, in display order. Deliberately NOT
// DayTypeTag's 4-value `DayType` (the holiday subset, no `ordinary`) — a pay rule prices
// every kind of day an employee can work, `ordinary` included.
const DAY_TYPES: PayRuleDayType[] = [
  'ordinary',
  'special_working',
  'special_non_working',
  'regular_holiday',
  'double_regular_holiday',
]

// Own label map — DayTypeTag's LABEL has no `ordinary` entry and would render `undefined`
// for that row.
const DAY_TYPE_LABEL: Record<PayRuleDayType, string> = {
  ordinary: 'Ordinary',
  special_working: 'Special (working)',
  special_non_working: 'Special (non-working)',
  regular_holiday: 'Regular holiday',
  double_regular_holiday: 'Double regular holiday',
}

type ScalarKey = 'overtime_ordinary' | 'overtime_premium' | 'night_diff'

const SCALAR_LABEL: Record<ScalarKey, string> = {
  overtime_ordinary: 'Overtime ordinary',
  overtime_premium: 'Overtime premium',
  night_diff: 'Night differential',
}

// Mirrors config('hris.pay_floors') (App\Domain\Pay\StatutoryFloor's floor matrix), as
// PERCENTAGES — a client-side hint only. The server is the authority; see the 422
// handling below.
const PAY_FLOOR_PERCENT: {
  worked: Record<PayRuleDayType, [number, number]> // [not_rest, rest]
  unworked: Record<PayRuleDayType, number>
  overtime_ordinary: number
  overtime_premium: number
  night_diff: number
} = {
  worked: {
    ordinary: [100, 130],
    special_working: [100, 130],
    special_non_working: [130, 150],
    regular_holiday: [200, 260],
    double_regular_holiday: [300, 390],
  },
  unworked: {
    ordinary: 0,
    special_working: 0,
    special_non_working: 0,
    regular_holiday: 100,
    double_regular_holiday: 200,
  },
  overtime_ordinary: 125,
  overtime_premium: 130,
  night_diff: 110,
}

/** The version with the greatest `effective_from <= today` — "currently effective" per
 * the M4c brief. Recomputed from the whole list rather than trusting arrival order, even
 * though the API returns it `effective_from` desc. */
function effectiveVersion(payRules: PayRule[], today: string): PayRule | null {
  let best: PayRule | null = null
  for (const rule of payRules) {
    if (rule.effective_from > today) continue
    if (best === null || rule.effective_from > best.effective_from) best = rule
  }
  return best
}

function versionsDesc(payRules: PayRule[]): PayRule[] {
  return [...payRules].sort((a, b) => (a.effective_from < b.effective_from ? 1 : -1))
}

type DayRatePercents = Record<PayRuleDayType, { worked: string; worked_rest: string; unworked: string }>

/** Seeds the New-version form from the currently-effective version (as percentages) — a
 * correction is usually "the same matrix, one cell changed," so starting from a blank
 * matrix every time would make the common case the tedious one. Falls back to the floor
 * itself when there is no effective version yet. */
function seedDayRates(effective: PayRule | null): DayRatePercents {
  const byType = new Map(effective?.day_rates.map((rate) => [rate.day_type, rate]))

  return Object.fromEntries(
    DAY_TYPES.map((dayType) => {
      const rate = byType.get(dayType)
      const floor = PAY_FLOOR_PERCENT.worked[dayType]

      return [
        dayType,
        {
          worked: String(rate !== undefined ? bpToPercent(rate.worked_bp) : floor[0]),
          worked_rest: String(rate !== undefined ? bpToPercent(rate.worked_rest_bp) : floor[1]),
          unworked: String(rate !== undefined ? bpToPercent(rate.unworked_bp) : PAY_FLOOR_PERCENT.unworked[dayType]),
        },
      ]
    }),
  ) as DayRatePercents
}

function isValidPercent(raw: string): boolean {
  return raw.trim() !== '' && !Number.isNaN(Number(raw))
}

function isBelowFloor(raw: string, floor: number): boolean {
  const value = Number(raw)
  return !Number.isNaN(value) && value < floor
}

interface PercentCellProps {
  id: string
  label: string
  value: string
  floor: number
  serverFlagged: boolean
  onChange: (value: string) => void
}

/** One editable percentage cell — a raw `<input type="number">` styled like `TextInput`
 * (which only covers text/email/password/date), flagged red when the client-side floor
 * hint fails OR the server's 422 named this exact cell. */
function PercentCell({ id, label, value, floor, serverFlagged, onChange }: PercentCellProps) {
  const flagged = isBelowFloor(value, floor) || serverFlagged

  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
      <label htmlFor={id} style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
        {label}
      </label>
      <input
        id={id}
        type="number"
        step={1}
        value={value}
        aria-invalid={flagged ? 'true' : undefined}
        onChange={(event) => onChange(event.target.value)}
        className="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)]"
        style={{
          background: 'var(--surface-1)',
          color: 'var(--ink)',
          border: 'none',
          borderBottom: `1px solid ${flagged ? 'var(--error)' : 'var(--field-border)'}`,
          borderRadius: 'var(--radius)',
          padding: 'calc(var(--sp-sm) - 1px) var(--sp-md)',
          font: 'var(--t-body)',
          letterSpacing: 'var(--ls-body)',
          width: '7ch',
        }}
      />
      <span
        style={{
          font: 'var(--t-caption)',
          letterSpacing: 'var(--ls-caption)',
          color: flagged ? 'var(--error)' : 'var(--ink-subtle)',
        }}
      >
        Min {floor}%
      </span>
    </div>
  )
}

/** `details.violations[].multiplier` keys, mirroring `StatutoryFloor::violations()` on
 * the backend exactly — this is the one place both the floor hint and the 422 response
 * are looked up by the same string. */
function workedKey(dayType: PayRuleDayType): string {
  return `worked.${dayType}.not_rest`
}
function workedRestKey(dayType: PayRuleDayType): string {
  return `worked.${dayType}.rest`
}
function unworkedKey(dayType: PayRuleDayType): string {
  return `unworked.${dayType}`
}

interface NewVersionFormProps {
  seed: DayRatePercents
  seedScalars: Record<ScalarKey, string>
  submitting: boolean
  apiError: ApiError | null
  onCancel: () => void
  onSubmit: (input: PayRuleCreateInput) => void
}

/** Owns its own field state, mounted fresh each time the dialog opens (a `key` on the
 * caller) so a previous draft never leaks into the next one. */
function NewVersionForm({ seed, seedScalars, submitting, apiError, onCancel, onSubmit }: NewVersionFormProps) {
  const [effectiveFrom, setEffectiveFrom] = useState(todayInZone(OFFICE_TIME_ZONE))
  const [dayRates, setDayRates] = useState<DayRatePercents>(seed)
  const [scalars, setScalars] = useState<Record<ScalarKey, string>>(seedScalars)

  const violatingKeys =
    apiError?.code === 'pay_rate_below_floor'
      ? new Set(
          (apiError.details.violations as Array<{ multiplier: string }> | undefined ?? []).map((v) => v.multiplier),
        )
      : new Set<string>()

  const isDuplicateDate = apiError?.code === 'pay_rule_exists'
  const isUnknownError = apiError !== null && !isDuplicateDate && apiError.code !== 'pay_rate_below_floor'

  function updateCell(dayType: PayRuleDayType, field: 'worked' | 'worked_rest' | 'unworked', value: string): void {
    setDayRates((prev) => ({ ...prev, [dayType]: { ...prev[dayType], [field]: value } }))
  }

  function updateScalar(key: ScalarKey, value: string): void {
    setScalars((prev) => ({ ...prev, [key]: value }))
  }

  const hasInvalidInput =
    effectiveFrom.trim() === '' ||
    DAY_TYPES.some(
      (dayType) =>
        !isValidPercent(dayRates[dayType].worked) ||
        !isValidPercent(dayRates[dayType].worked_rest) ||
        !isValidPercent(dayRates[dayType].unworked),
    ) ||
    (Object.keys(scalars) as ScalarKey[]).some((key) => !isValidPercent(scalars[key]))

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (hasInvalidInput) return

    const day_rates: PayRuleDayRate[] = DAY_TYPES.map((dayType) => ({
      day_type: dayType,
      worked_bp: percentToBp(Number(dayRates[dayType].worked)),
      worked_rest_bp: percentToBp(Number(dayRates[dayType].worked_rest)),
      unworked_bp: percentToBp(Number(dayRates[dayType].unworked)),
    }))

    onSubmit({
      effective_from: effectiveFrom,
      overtime_ordinary_bp: percentToBp(Number(scalars.overtime_ordinary)),
      overtime_premium_bp: percentToBp(Number(scalars.overtime_premium)),
      night_diff_bp: percentToBp(Number(scalars.night_diff)),
      day_rates,
    })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <TextInput
        id="pay-rule-effective-from"
        label="Effective from"
        type="date"
        value={effectiveFrom}
        onChange={setEffectiveFrom}
        required
      />

      <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
        {DAY_TYPES.map((dayType) => (
          <div key={dayType} className="flex flex-col" style={{ gap: 'var(--sp-xs)' }}>
            <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
              {DAY_TYPE_LABEL[dayType]}
            </span>
            <div className="flex flex-wrap" style={{ gap: 'var(--sp-md)' }}>
              <PercentCell
                id={`pay-rule-${dayType}-worked`}
                label={`${DAY_TYPE_LABEL[dayType]} worked`}
                value={dayRates[dayType].worked}
                floor={PAY_FLOOR_PERCENT.worked[dayType][0]}
                serverFlagged={violatingKeys.has(workedKey(dayType))}
                onChange={(value) => updateCell(dayType, 'worked', value)}
              />
              <PercentCell
                id={`pay-rule-${dayType}-worked-rest`}
                label={`${DAY_TYPE_LABEL[dayType]} worked (rest day)`}
                value={dayRates[dayType].worked_rest}
                floor={PAY_FLOOR_PERCENT.worked[dayType][1]}
                serverFlagged={violatingKeys.has(workedRestKey(dayType))}
                onChange={(value) => updateCell(dayType, 'worked_rest', value)}
              />
              <PercentCell
                id={`pay-rule-${dayType}-unworked`}
                label={`${DAY_TYPE_LABEL[dayType]} unworked`}
                value={dayRates[dayType].unworked}
                floor={PAY_FLOOR_PERCENT.unworked[dayType]}
                serverFlagged={violatingKeys.has(unworkedKey(dayType))}
                onChange={(value) => updateCell(dayType, 'unworked', value)}
              />
            </div>
          </div>
        ))}
      </div>

      <div className="flex flex-wrap" style={{ gap: 'var(--sp-md)' }}>
        {(Object.keys(SCALAR_LABEL) as ScalarKey[]).map((key) => (
          <PercentCell
            key={key}
            id={`pay-rule-${key}`}
            label={SCALAR_LABEL[key]}
            value={scalars[key]}
            floor={PAY_FLOOR_PERCENT[key]}
            serverFlagged={violatingKeys.has(key)}
            onChange={(value) => updateScalar(key, value)}
          />
        ))}
      </div>

      {violatingKeys.size > 0 ? (
        <InlineNotification kind="error" title="One or more proposed rates fall below the statutory floor.">
          Fix the flagged cells above and try again.
        </InlineNotification>
      ) : null}

      {isDuplicateDate ? (
        <InlineNotification kind="error" title="A version already exists on that date.">
          Pick a different effective date, or edit that version&rsquo;s date instead.
        </InlineNotification>
      ) : null}

      {isUnknownError ? (
        <InlineNotification kind="error" title="That didn't save.">
          Check your connection and try again.
        </InlineNotification>
      ) : null}

      <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
        <Button type="submit" loading={submitting} disabled={submitting || hasInvalidInput}>
          Create version
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

interface MatrixTableProps {
  payRule: PayRule
}

/** The currently-effective version, read-only, as percentages. */
function MatrixTable({ payRule }: MatrixTableProps) {
  const byType = new Map(payRule.day_rates.map((rate) => [rate.day_type, rate]))
  const scalarBp: Record<ScalarKey, number> = {
    overtime_ordinary: payRule.overtime_ordinary_bp,
    overtime_premium: payRule.overtime_premium_bp,
    night_diff: payRule.night_diff_bp,
  }

  return (
    <div className="overflow-x-auto">
      <table style={{ borderCollapse: 'collapse', width: '100%' }}>
        <thead>
          <tr style={{ borderBottom: '1px solid var(--hairline)' }}>
            {['Day type', 'Worked', 'Worked (rest day)', 'Unworked'].map((heading) => (
              <th
                key={heading}
                scope="col"
                style={{
                  textAlign: 'left',
                  font: 'var(--t-body-sm)',
                  letterSpacing: 'var(--ls-body)',
                  color: 'var(--ink-muted)',
                  padding: 'var(--sp-xs) var(--sp-sm)',
                }}
              >
                {heading}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {DAY_TYPES.map((dayType) => {
            const rate = byType.get(dayType)
            return (
              <tr key={dayType} style={{ borderBottom: '1px solid var(--hairline)' }}>
                <th
                  scope="row"
                  style={{
                    textAlign: 'left',
                    font: 'var(--t-body)',
                    letterSpacing: 'var(--ls-body)',
                    color: 'var(--ink)',
                    padding: 'var(--sp-xs) var(--sp-sm)',
                  }}
                >
                  {DAY_TYPE_LABEL[dayType]}
                </th>
                <td style={{ padding: 'var(--sp-xs) var(--sp-sm)', font: 'var(--t-body)', letterSpacing: 'var(--ls-body)' }}>
                  {rate ? `${bpToPercent(rate.worked_bp)}%` : '—'}
                </td>
                <td style={{ padding: 'var(--sp-xs) var(--sp-sm)', font: 'var(--t-body)', letterSpacing: 'var(--ls-body)' }}>
                  {rate ? `${bpToPercent(rate.worked_rest_bp)}%` : '—'}
                </td>
                <td style={{ padding: 'var(--sp-xs) var(--sp-sm)', font: 'var(--t-body)', letterSpacing: 'var(--ls-body)' }}>
                  {rate ? `${bpToPercent(rate.unworked_bp)}%` : '—'}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>

      <div className="flex flex-wrap" style={{ gap: 'var(--sp-lg)', marginTop: 'var(--sp-md)' }}>
        {(Object.keys(SCALAR_LABEL) as ScalarKey[]).map((key) => (
          <div key={key} className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
            <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
              {SCALAR_LABEL[key]}
            </span>
            <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
              {bpToPercent(scalarBp[key])}%
            </span>
          </div>
        ))}
      </div>
    </div>
  )
}

export default function PayRulesPage() {
  const { session } = useSession()
  const payRulesQuery = usePayRules()
  const createMutation = useCreatePayRule()

  const [dialogOpen, setDialogOpen] = useState(false)

  const today = todayInZone(OFFICE_TIME_ZONE)
  const payRules = payRulesQuery.data ?? []
  const effective = effectiveVersion(payRules, today)

  const apiError = createMutation.error instanceof ApiError ? createMutation.error : null

  function closeDialog(): void {
    setDialogOpen(false)
    // Clear any 422/409 from the last attempt — TanStack keeps mutation.error until the
    // next mutate(), so without this the stale violation banner re-appears when the dialog
    // is cancelled and reopened.
    createMutation.reset()
  }

  function handleSubmit(input: PayRuleCreateInput): void {
    createMutation.mutate(input, { onSuccess: closeDialog })
  }

  const isSysAdmin = session?.is_system_admin ?? false

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader
          eyebrow="Admin"
          title="Pay rules"
          level={1}
          actions={isSysAdmin ? <Button onClick={() => setDialogOpen(true)}>New version</Button> : undefined}
        />

        {session !== null && !isSysAdmin ? (
          <InlineNotification kind="info" title="This account can't administer pay rules.">
            Pay rules are a system-admin-only screen.
          </InlineNotification>
        ) : payRulesQuery.isLoading ? (
          <Skeleton height="20rem" />
        ) : payRulesQuery.isError ? (
          <InlineNotification kind="error" title="Couldn't load pay rules.">
            Check your connection and try again.
          </InlineNotification>
        ) : (
          <>
            <SectionHeader title={effective !== null ? `Effective from ${effective.effective_from}` : 'Currently effective'} />

            {effective === null ? (
              <InlineNotification kind="info" title="No pay rule is effective yet.">
                Create a version with today or an earlier effective date to see it here.
              </InlineNotification>
            ) : (
              <MatrixTable payRule={effective} />
            )}

            <SectionHeader title="Version history" />
            {payRules.length === 0 ? (
              <InlineNotification kind="info" title="No versions yet.">
                Create the first one with &ldquo;New version&rdquo; above.
              </InlineNotification>
            ) : (
              <ul className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
                {versionsDesc(payRules).map((rule) => (
                  <li
                    key={rule.id}
                    className="flex items-center"
                    style={{ gap: 'var(--sp-sm)', font: 'var(--t-body)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
                  >
                    {rule.effective_from}
                    {rule.id === effective?.id ? (
                      <span style={{ font: 'var(--t-caption)', letterSpacing: 'var(--ls-caption)', color: 'var(--ink-subtle)' }}>
                        (effective now)
                      </span>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
          </>
        )}

        <Dialog open={dialogOpen} onClose={closeDialog} title="New pay rule version">
          {dialogOpen ? (
            <NewVersionForm
              key={payRules.length}
              seed={seedDayRates(effective)}
              seedScalars={{
                overtime_ordinary: String(effective ? bpToPercent(effective.overtime_ordinary_bp) : PAY_FLOOR_PERCENT.overtime_ordinary),
                overtime_premium: String(effective ? bpToPercent(effective.overtime_premium_bp) : PAY_FLOOR_PERCENT.overtime_premium),
                night_diff: String(effective ? bpToPercent(effective.night_diff_bp) : PAY_FLOOR_PERCENT.night_diff),
              }}
              submitting={createMutation.isPending}
              apiError={apiError}
              onCancel={closeDialog}
              onSubmit={handleSubmit}
            />
          ) : null}
        </Dialog>
      </div>
    </AppShell>
  )
}
