import type { ResolvedDay, ScheduleSource } from '@/lib/api'
import { minutesToHHMM } from '@/lib/minutes'
import { Tag } from '../Tag'
import type { TagKind } from '../Tag'

export interface ResolvedDayCellProps {
  resolved: ResolvedDay | undefined
}

const SOURCE_LABEL: Record<ScheduleSource, string> = {
  override: 'Override',
  employee: 'Employee',
  department: 'Department',
  office_default: 'Office default',
}

// Monochrome, like DayTypeTag — an override is the one source worth calling out (it's the
// thing an admin just did to this specific day); every other tier reads as the same
// neutral "this is how it resolved" fact.
const SOURCE_KIND: Record<ScheduleSource, TagKind> = {
  override: 'success',
  employee: 'neutral',
  department: 'neutral',
  office_default: 'neutral',
}

/**
 * One resolved day's cell content for the `/office/schedules` resolved calendar — shows
 * exactly what `ScheduleResolver` produced (rest, or the shift's actual hours) and which
 * precedence tier produced it, never an invented total. Mirrors `DayCell`'s honesty:
 * `scheduled_minutes` is not redone here, only the wall-clock hours the day actually
 * carries. A day outside the loaded month (`resolved === undefined`) renders blank rather
 * than guessing at a state.
 */
export function ResolvedDayCell({ resolved }: ResolvedDayCellProps) {
  if (resolved === undefined) return null

  const hoursText =
    resolved.start_minute !== null && resolved.end_minute !== null
      ? `${minutesToHHMM(resolved.start_minute)}–${minutesToHHMM(resolved.end_minute)}${
          resolved.end_minute >= 1440 ? ' +1' : ''
        }`
      : null

  return (
    <div className="flex flex-col items-start" style={{ gap: 'var(--sp-xxs)' }}>
      <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
        {resolved.is_rest ? 'Rest' : hoursText}
      </span>

      <Tag kind={SOURCE_KIND[resolved.source]}>{SOURCE_LABEL[resolved.source]}</Tag>
    </div>
  )
}
