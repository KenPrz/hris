/**
 * Calendar dates, string-based, end to end.
 *
 * The backend groups attendance by *office-local* calendar date and sends `YYYY-MM-DD`
 * keys (see `backend/app/Domain/Attendance/AttendanceMonth.php`). A punch at 00:30
 * Asia/Manila belongs to the 30th. If the browser parsed that into a `Date` and
 * formatted it back in the viewer's own local zone, a user in another timezone would
 * see punches shift a day — silently wrong payroll data.
 *
 * So: calendar dates stay `YYYY-MM-DD` (and `YYYY-MM`) strings, and month arithmetic
 * (`addMonths`, `daysInMonth`, `monthLabel`) is pure integer/string arithmetic — never
 * `Date` round-tripping. The only places a `Date` appears at all are:
 *   - constructed with an explicit UTC year/month/day and read back with UTC getters
 *     (`weekdayIndex`), which no host timezone can perturb, or
 *   - formatted via `Intl.DateTimeFormat` with an explicit `timeZone` (`todayInZone`,
 *     `timeInZone`), which reads the wall clock in the zone you pass, never the
 *     host's default zone. `currentMonth` is a thin slice of `todayInZone`.
 */

const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

function parseMonth(month: string): { year: number; monthNumber: number } {
  const [yearPart, monthPart] = month.split('-')
  return { year: Number(yearPart), monthNumber: Number(monthPart) }
}

function isLeapYear(year: number): boolean {
  return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0
}

function daysInMonthCount(year: number, monthNumber: number): number {
  const lengths = [31, isLeapYear(year) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]
  return lengths[monthNumber - 1]
}

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

/** `YYYY-MM-DD` for "today" as understood in `timeZone` — not the browser's own zone. */
export function todayInZone(timeZone: string): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

/** `YYYY-MM` for the current month as understood in `timeZone`. */
export function currentMonth(timeZone: string): string {
  return todayInZone(timeZone).slice(0, 7)
}

/**
 * Pure arithmetic on a `YYYY-MM` string — no day component, no `Date`. Handles year
 * rollover in both directions and any magnitude of `delta`.
 */
export function addMonths(month: string, delta: number): string {
  const { year, monthNumber } = parseMonth(month)

  // monthNumber is 1-12; work in a 0-based total-months-since-epoch-zero index so
  // modular arithmetic (including negative deltas) is a single division.
  const totalMonths = year * 12 + (monthNumber - 1) + delta
  const newYear = Math.floor(totalMonths / 12)
  const newMonthNumber = totalMonths - newYear * 12 + 1

  return `${newYear}-${pad2(newMonthNumber)}`
}

/** `'2026-07'` → `'July 2026'`. */
export function monthLabel(month: string): string {
  const { year, monthNumber } = parseMonth(month)
  return `${MONTH_NAMES[monthNumber - 1]} ${year}`
}

const MONTH_ABBR = MONTH_NAMES.map((name) => name.slice(0, 3))

function parseDate(date: string): { year: number; monthNumber: number; day: number } {
  const [yearPart, monthPart, dayPart] = date.split('-')
  return { year: Number(yearPart), monthNumber: Number(monthPart), day: Number(dayPart) }
}

/** Inclusive calendar-day count between two `YYYY-MM-DD` dates — pure integer arithmetic
 * via each date's Julian-ish epoch-day number, never a `Date` diff in milliseconds (which
 * would silently miscount across a DST spring-forward/fall-back if either date were ever
 * paired with a time). `daysBetweenInclusive('2026-08-10', '2026-08-12')` is `3`, not `2` —
 * a leave request spans BOTH endpoints. */
export function daysBetweenInclusive(start: string, end: string): number {
  const s = parseDate(start)
  const e = parseDate(end)
  const startEpochDay = Date.UTC(s.year, s.monthNumber - 1, s.day) / 86_400_000
  const endEpochDay = Date.UTC(e.year, e.monthNumber - 1, e.day) / 86_400_000
  return endEpochDay - startEpochDay + 1
}

/** `('2026-08-10', '2026-08-12')` → `'Aug 10–12'`; `('2026-07-30', '2026-08-02')` →
 * `'Jul 30 – Aug 2'`; `('2026-12-30', '2027-01-02')` → `'Dec 30, 2026 – Jan 2, 2027'`;
 * a single-day span → just `'Aug 10'`. Pure string parsing, no `Date` round-trip: these
 * are calendar dates already, not instants, so there is no host timezone to disagree with
 * (see this file's own doc comment on why that distinction matters). */
export function formatDateSpan(start: string, end: string): string {
  const s = parseDate(start)
  const e = parseDate(end)

  if (start === end) return `${MONTH_ABBR[s.monthNumber - 1]} ${s.day}`
  if (s.year !== e.year) {
    return `${MONTH_ABBR[s.monthNumber - 1]} ${s.day}, ${s.year} – ${MONTH_ABBR[e.monthNumber - 1]} ${e.day}, ${e.year}`
  }
  if (s.monthNumber !== e.monthNumber) {
    return `${MONTH_ABBR[s.monthNumber - 1]} ${s.day} – ${MONTH_ABBR[e.monthNumber - 1]} ${e.day}`
  }
  return `${MONTH_ABBR[s.monthNumber - 1]} ${s.day}–${e.day}`
}

/** Every `YYYY-MM-DD` in `month`, in order — pure string/integer arithmetic. */
export function daysInMonth(month: string): string[] {
  const { year, monthNumber } = parseMonth(month)
  const count = daysInMonthCount(year, monthNumber)

  const days: string[] = []
  for (let day = 1; day <= count; day++) {
    days.push(`${month}-${pad2(day)}`)
  }
  return days
}

/**
 * 0 = Monday … 6 = Sunday (the calendar grid starts Monday).
 *
 * Built from a `Date` constructed with an explicit UTC year/month/day and read back
 * with `getUTCDay()` — the host's local timezone never enters into it.
 */
export function weekdayIndex(date: string): number {
  const [yearPart, monthPart, dayPart] = date.split('-')
  const utcDate = new Date(Date.UTC(Number(yearPart), Number(monthPart) - 1, Number(dayPart)))

  // getUTCDay(): Sunday=0 .. Saturday=6. Shift so Monday=0 .. Sunday=6.
  return (utcDate.getUTCDay() + 6) % 7
}

/**
 * `HH:mm` (24-hour) for a punch's ISO instant, rendered in its office's `timeZone`.
 *
 * `iso` MUST carry an explicit offset or `Z` — the backend's `toIso8601String()` always
 * does. A bare local-looking timestamp would be parsed against the host's zone, which is
 * exactly the day-shift this module exists to prevent.
 */
export function timeInZone(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-GB', {
    timeZone,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(new Date(iso))
}

/** `timeZone`'s UTC offset, in minutes east of UTC, AT `instantMs` — read via `Intl`'s
 * `longOffset` (`"GMT+08:00"` / `"GMT-05:00"`), never hardcoded. Minutes, not a string,
 * so `toIsoInZone` can arithmetic on it to correct a DST-boundary guess. */
function offsetMinutesAt(instantMs: number, timeZone: string): number {
  const offsetName =
    new Intl.DateTimeFormat('en-US', { timeZone, timeZoneName: 'longOffset' })
      .formatToParts(new Date(instantMs))
      .find((part) => part.type === 'timeZoneName')?.value ?? 'GMT+00:00'

  const match = /^GMT([+-])(\d{2}):(\d{2})$/.exec(offsetName)
  if (!match) return 0

  const sign = match[1] === '-' ? -1 : 1
  return sign * (Number(match[2]) * 60 + Number(match[3]))
}

/** Minutes east of UTC → `"+08:00"` / `"-04:00"`. */
function formatOffset(offsetMinutes: number): string {
  const sign = offsetMinutes < 0 ? '-' : '+'
  const absMinutes = Math.abs(offsetMinutes)
  const hh = String(Math.floor(absMinutes / 60)).padStart(2, '0')
  const mm = String(absMinutes % 60).padStart(2, '0')
  return `${sign}${hh}:${mm}`
}

/**
 * The inverse of `timeInZone`: a `YYYY-MM-DD` date + an `HH:mm` wall-clock time, both
 * understood as local to `timeZone`, combined into an ISO8601 instant carrying an
 * EXPLICIT offset — the shape `AttendanceLog.punched_at`'s wire type demands (see
 * `timeInZone`'s doc comment) and what `CorrectionForm` sends as `CorrectionInput.punched_at`.
 *
 * The offset is read from `Intl`'s `longOffset` for `timeZone`, not hardcoded — but a
 * single lookup isn't enough to be DST-safe: there's no `Date` for "this wall-clock time
 * in this zone" to hand `Intl` until the offset is already known, so the first lookup has
 * to guess an instant (the wall-clock digits read AS IF they were UTC). Near a transition
 * that guess can land on the wrong side of the boundary and return the PRE-transition
 * offset for a wall-clock time that's actually POST-transition (e.g. 2026-03-08 03:30
 * America/New_York: the naive guess is 03:30Z, still before that date's 07:00Z
 * spring-forward, so it reads EST/-05:00 when 03:30 local that day is unambiguously
 * EDT/-04:00). So: read the offset at the guess, apply it to get a corrected instant, then
 * re-read the offset AT THAT corrected instant and use that one if it differs. One
 * re-check is enough — a transition moves the clock by at most a couple of hours, well
 * within what a single re-derivation resolves. Every office today is fixed Asia/Manila
 * (+08:00 year-round, no DST, see `lib/timezone.ts`), so this only matters once a
 * DST-observing office zone exists — but it's correct for one today, not just documented
 * as a future TODO.
 */
export function toIsoInZone(date: string, time: string, timeZone: string): string {
  const [yearPart, monthPart, dayPart] = date.split('-').map(Number)
  const [hourPart, minutePart] = time.split(':').map(Number)

  const guessUtcMs = Date.UTC(yearPart, monthPart - 1, dayPart, hourPart, minutePart)

  const firstOffsetMinutes = offsetMinutesAt(guessUtcMs, timeZone)
  const correctedUtcMs = guessUtcMs - firstOffsetMinutes * 60_000
  const secondOffsetMinutes = offsetMinutesAt(correctedUtcMs, timeZone)

  // The two disagree exactly when the guess landed on the wrong side of a transition —
  // the offset AT the corrected instant is the one that actually applies to this
  // wall-clock time.
  const offsetMinutes = secondOffsetMinutes !== firstOffsetMinutes ? secondOffsetMinutes : firstOffsetMinutes

  return `${date}T${time}:00${formatOffset(offsetMinutes)}`
}
