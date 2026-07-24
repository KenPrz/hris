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

/** `HH:mm` (24-hour) for a punch's ISO instant, rendered in its office's `timeZone`. */
export function timeInZone(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-GB', {
    timeZone,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(new Date(iso))
}
