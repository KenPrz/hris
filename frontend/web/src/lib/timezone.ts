/**
 * The one centralised source for "the office's timezone."
 *
 * Punch times and "today" are calendar facts of the *office*, not the viewer — a punch at
 * 00:30 Asia/Manila belongs to the 30th no matter what zone the browser is in (see
 * `lib/date.ts`). The correct value is `employee.current_office_id`'s office's configured
 * timezone, but M3.5's `Session` type carries only the office *id* (a uuid) — no name, no
 * timezone — because there is no office-timezone lookup yet (that lands with the office
 * model in a later milestone).
 *
 * Known M3.5 limitation: every office in this deployment is Philippine today, so a fixed
 * `Asia/Manila` is the correct, if temporary, answer. This is the ONE place that stands
 * in for a real per-office lookup — every caller that needs "the office's timezone"
 * imports `OFFICE_TIME_ZONE` from here rather than reaching for
 * `Intl.DateTimeFormat().resolvedOptions().timeZone` (the *viewer's* zone, which a punch
 * must never be computed in) or re-declaring the literal elsewhere. Replace this constant
 * with a real lookup once the session or an API carries the office's own zone — nothing
 * else in the frontend should need to change.
 */
export const OFFICE_TIME_ZONE = 'Asia/Manila'
