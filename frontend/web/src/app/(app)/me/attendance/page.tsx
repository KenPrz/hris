'use client'

/**
 * The screen everything else was built for: the daily punch action sits above the record
 * it produces. Direction is derived, never guessed — the next action is `out` when
 * today's punch count is odd, `in` when even (see `deriveNextDirection`) — and the button
 * label always matches what happens next, through to the state it produces.
 *
 * The hero always reflects TODAY, in `OFFICE_TIME_ZONE` — regardless of which month is
 * being browsed below it — because a punch always records "now." The ledger below shows
 * whichever month `?month=` names, defaulting to the current one.
 */

import { useEffect, useState } from 'react'
import { usePathname, useRouter, useSearchParams } from 'next/navigation'

import type { AttendanceLog, AttendanceMonth, PunchDirection } from '@/lib/api'
import { addMonths, currentMonth, monthLabel, timeInZone, todayInZone } from '@/lib/date'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'
import { formatDuration } from '@/lib/duration'
import { useMyAttendance } from '@/hooks/useMyAttendance'
import { usePunch } from '@/hooks/usePunch'
import { AppShell } from '@/components/AppShell'
import { SectionHeader } from '@/components/SectionHeader'
import { StatTile } from '@/components/StatTile'
import { EmptyState } from '@/components/EmptyState'
import { Button } from '@/components/ui/Button'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Skeleton } from '@/components/ui/Skeleton'
import { MonthCalendar } from '@/components/domain/MonthCalendar'

const MONTH_PATTERN = /^\d{4}-\d{2}$/

function parseViewedMonth(raw: string | null): string {
  return raw !== null && MONTH_PATTERN.test(raw) ? raw : currentMonth(OFFICE_TIME_ZONE)
}

function sortByPunchedAt(punches: AttendanceLog[]): AttendanceLog[] {
  return [...punches].sort((a, b) => new Date(a.punched_at).getTime() - new Date(b.punched_at).getTime())
}

/** Even count (including zero) → next punch is `in`; odd count → next punch is `out`. */
function deriveNextDirection(todaysPunches: AttendanceLog[]): PunchDirection {
  return todaysPunches.length % 2 === 0 ? 'in' : 'out'
}

/**
 * "Clocked out" for an empty day; otherwise names the last punch's direction and the
 * clock time it happened, in the office's zone — never any other formatting of a punch
 * time.
 */
function deriveStatusText(sortedTodaysPunches: AttendanceLog[], timeZone: string): string {
  if (sortedTodaysPunches.length === 0) return 'Clocked out'

  const last = sortedTodaysPunches[sortedTodaysPunches.length - 1]
  const time = timeInZone(last.punched_at, timeZone)

  return last.direction === 'in' ? `Clocked in since ${time}` : `Clocked out since ${time}`
}

/**
 * Sum of every completed in→out pair so far today, stopping at the first punch that
 * doesn't fit that shape (an open shift, an irregular sequence). Presentational only —
 * M5 owns the authoritative worked-time computation; this is "so far," honestly partial.
 */
function completedMinutesSoFar(sortedTodaysPunches: AttendanceLog[]): number {
  let totalMinutes = 0

  for (let i = 0; i + 1 < sortedTodaysPunches.length; i += 2) {
    const inPunch = sortedTodaysPunches[i]
    const outPunch = sortedTodaysPunches[i + 1]

    if (inPunch.direction !== 'in' || outPunch.direction !== 'out') break

    const spanMs = new Date(outPunch.punched_at).getTime() - new Date(inPunch.punched_at).getTime()
    if (spanMs < 0) break

    totalMinutes += Math.round(spanMs / 60_000)
  }

  return totalMinutes
}

function isMonthEmpty(month: AttendanceMonth): boolean {
  return Object.values(month).every((punches) => punches.length === 0)
}

/** Ticks once a second so the hero's clock reads live, in the office's zone. */
function useClockText(timeZone: string): string {
  const [nowIso, setNowIso] = useState(() => new Date().toISOString())

  useEffect(() => {
    const id = setInterval(() => setNowIso(new Date().toISOString()), 1000)
    return () => clearInterval(id)
  }, [])

  return timeInZone(nowIso, timeZone)
}

const DIRECTION_LABEL: Record<PunchDirection, string> = {
  in: 'Clock in',
  out: 'Clock out',
}

export default function AttendancePage() {
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()

  const viewedMonth = parseViewedMonth(searchParams.get('month'))
  const thisMonth = currentMonth(OFFICE_TIME_ZONE)
  const today = todayInZone(OFFICE_TIME_ZONE)

  // Same query key when browsing the current month — TanStack Query dedupes it into one
  // request, so this never doubles the fetch in the common case.
  const heroQuery = useMyAttendance(thisMonth)
  const viewedQuery = useMyAttendance(viewedMonth)
  const punchMutation = usePunch()

  const clockText = useClockText(OFFICE_TIME_ZONE)

  function navigateToMonth(nextMonth: string) {
    router.replace(`${pathname}?month=${nextMonth}`)
  }

  const todaysPunches = sortByPunchedAt(heroQuery.data?.[today] ?? [])
  const nextDirection = deriveNextDirection(todaysPunches)
  const statusText = deriveStatusText(todaysPunches, OFFICE_TIME_ZONE)
  const totalMinutesSoFar = completedMinutesSoFar(todaysPunches)

  function handlePunch() {
    punchMutation.mutate(nextDirection)
  }

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader eyebrow="Me" title="Attendance" />

        <section
          style={{
            background: 'var(--surface-1)',
            borderRadius: 'var(--radius)',
            padding: 'var(--sp-lg)',
            display: 'flex',
            flexDirection: 'column',
            gap: 'var(--sp-md)',
          }}
        >
          {heroQuery.isLoading ? (
            <Skeleton height="6rem" />
          ) : heroQuery.isError ? (
            <InlineNotification kind="error" title="Couldn't load today's attendance.">
              Check your connection and try again.
            </InlineNotification>
          ) : (
            <>
              <div className="flex flex-wrap" style={{ gap: 'var(--sp-md)' }}>
                <StatTile label="Now" value={clockText} />
                <StatTile label="Status" value={statusText} />
                <StatTile label="Today" value={formatDuration(totalMinutesSoFar)} />
              </div>

              {punchMutation.isError ? (
                <InlineNotification kind="error" title="That punch didn't go through.">
                  Check your connection and try again.
                </InlineNotification>
              ) : null}

              <div>
                <Button
                  onClick={handlePunch}
                  loading={punchMutation.isPending}
                  disabled={punchMutation.isPending}
                >
                  {DIRECTION_LABEL[nextDirection]}
                </Button>
              </div>
            </>
          )}
        </section>

        <SectionHeader
          title={monthLabel(viewedMonth)}
          actions={
            <>
              <Button variant="ghost" onClick={() => navigateToMonth(addMonths(viewedMonth, -1))}>
                Previous month
              </Button>
              <Button variant="ghost" onClick={() => navigateToMonth(addMonths(viewedMonth, 1))}>
                Next month
              </Button>
            </>
          }
        />

        {viewedQuery.isLoading ? (
          <Skeleton height="20rem" />
        ) : viewedQuery.isError ? (
          <InlineNotification kind="error" title="Couldn't load this month's attendance.">
            Check your connection and try again.
          </InlineNotification>
        ) : isMonthEmpty(viewedQuery.data ?? {}) ? (
          <EmptyState title="No punches this month">
            Nothing recorded for {monthLabel(viewedMonth)} yet.
          </EmptyState>
        ) : (
          <MonthCalendar month={viewedMonth} days={viewedQuery.data ?? {}} timeZone={OFFICE_TIME_ZONE} />
        )}
      </div>
    </AppShell>
  )
}
