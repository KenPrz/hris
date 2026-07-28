# Feature Index

Every capability the system has, in the order it was built. Each entry is written for the
person who will *use* it, not the person who built it — this list is the spine the user
manual grows from. New features are appended as milestones ship; the milestone tag says
when each arrived.

For how the system is put together, see `docs/README.md`. For the plan of what's built and
what's next, see `docs/06-roadmap.md`.

---

## Foundations *(M0)*

- **The system runs as one web application.** A single site serves everyone — an employee
  checking their own record, a manager, an HR admin, a system administrator — with the
  screens each person sees decided by who they are. There is no separate app to install
  per role.
- **A health check.** `GET /api/v1/health` reports whether the application and its database
  are alive, with the database version — the first thing an operator checks when something
  seems wrong.
- **Every error speaks one language.** Whatever goes wrong — a missing page, an invalid
  form, a refused action — comes back in the same shape (`a code, a message, details`), so
  the apps never have to guess how to read a failure.

## Money and time, done right *(M1)*

These are not screens — they are the rules everything about pay obeys, built and proven
before any of it was wired to a button.

- **Time is counted in whole minutes, never decimal hours.** A shift is `7h 20m`, stored as
  `440` minutes — never `7.33`, which would drift a centavo here and there across a payroll
  and never reconcile.
- **Money is counted in whole centavos.** Every peso amount is an exact integer of
  centavos; there is one and only one place in the system where a fraction of a centavo can
  be rounded, so a payslip always adds up.
- **The Philippine premium-pay rules are built in.** The full DOLE matrix is encoded and
  verified cell by cell:
  - A **regular holiday** pays 200% worked (100% unworked); on a **rest day**, 260%.
  - A **special non-working day** pays 130% worked; on a rest day, a flat 150%.
  - A **double regular holiday** pays 300%; on a rest day, 390%.
  - **Overtime** adds 25% on an ordinary day, 30% on a premium day.
  - **Night-shift work** (10 p.m.–6 a.m.) adds 10% *on top of* whatever premium already
    applies — so holiday overtime at 2 a.m. compounds to 286%, not a flat 210%.
  - **Managerial employees and field personnel** (Art. 82-exempt) receive none of these
    premiums — and the system cannot compute a premium without first being told a person's
    status.
- **Shifts that cross midnight are handled.** A 10 p.m.–6 a.m. shift is understood as one
  span, and the night-differential window is found correctly across the midnight boundary.

## Company setup, people, and who-can-see-what *(M2)*

- **A three-level company structure.** The company is organized as **Organization →
  Office → Department**. An office is a branch or location; a department is a team within
  it.
- **Employee records, with or without a login.** Every employee has a record — an employee
  number, a hire date, their office and department. A record can exist **before** the
  person has a system login (a new hire being set up, or a worker who only ever punches a
  clock and never opens the portal).
- **Employment history is kept, not overwritten.** When someone is promoted, transferred,
  made exempt, or has a rate change, that is recorded as a **new dated entry** — the old one
  is never erased. Payroll for a past month always reads that month's facts, even after a
  later promotion.
- **Signing in.** Employees sign in with an email and password. Wrong credentials are
  refused identically whether the email exists or not (so the sign-in page can't be used to
  discover who has an account), and repeated attempts are rate-limited. Signing out truly
  ends the session.
- **"About me" at a glance.** Once signed in, a person's session tells the app exactly what
  they may see — their own employee record, whether they manage anyone, which offices they
  administer as HR, and which system-wide powers they hold.
- **Four levels of visibility, enforced everywhere.**
  - An **employee** sees only their own record.
  - A **manager** sees exactly their direct reports — no more, and being a manager is simply
    a fact of the org chart (whoever people report to), never a separate title to assign or
    forget.
  - An **HR admin** sees everyone in the office(s) they administer.
  - A **system administrator** sees everything.
  - Trying to view someone outside your visibility returns "not found" — not "forbidden" —
    so the org chart itself can't be probed by guessing.
- **Onboarding is a system-administrator job.** Creating an employee, giving them a login
  (with a required real name), and recording an employment change are all done by a system
  administrator, through the app.

## Timekeeping *(M3)*

- **An attendance record that is never rewritten.** Every punch is a permanent, dated row —
  a clock-in, a clock-out — kept exactly as it happened. A correction is a new entry, never
  an edit, so the attendance history is the forensic record you can show a labor inspector.
  Each punch remembers which office it belonged to at the moment it was made, so a later
  transfer never changes what a past day looked like.

- **Clocking in and out.** A signed-in employee records a clock-in or a clock-out from the
  web with one action. The time is set by the server, not the device — you cannot backdate
  your own punch. If a shaky connection makes the app retry, the punch is only recorded
  once, never twice.
- **Off-network punches are flagged, not blocked.** If a punch comes from outside the
  office's approved network, it is still recorded — but marked for HR to review, with the
  reason. Nobody is ever locked out of clocking in because of where they happened to be;
  the labor rules care that the time was worked, and a supervisor sorts out anything that
  looks off. (The same applies to a location check, once a mobile app exists.)

- **HR can record punches on someone's behalf.** An HR admin can enter a punch for an
  employee in their office at a specific time — essential for the workers who only ever
  punch a clock and never sign in to the portal, and for fixing gaps when a device was
  down. This is strictly an HR tool: you can never enter a punch for *yourself* this way
  (your own attendance goes through clocking in, or a correction request), and HR can only
  do it for employees in the offices they administer.

- **Seeing your attendance.** An employee can pull up a month of their own punches,
  organized by the day each one falls on in their office's local time — so a night shift
  that ends after midnight shows its clock-out on the correct calendar day. A manager or HR
  admin can see the same for the people they oversee. This view shows the raw punches
  exactly as recorded, including any that were flagged for review; turning punches into paid
  hours is a later step.

## Correcting your own attendance *(M3.6)*

- **Filing a correction.** If you missed a punch, punched the wrong direction, or a punch
  shouldn't be there at all, you file a request — add a missing clock-in/out, void a wrong
  one, or amend one to the right time — with a required note explaining what happened. You
  cannot backdate your own clock-in the way a manual HR entry can; a correction always goes
  through this request, reviewed by someone else, never silently applied.
- **Attaching proof.** A correction can carry one supporting file — a photo, a PDF — kept
  private: only you, and whoever is deciding your request, can ever download it. It is never
  a public link.
- **Manager or HR approval.** Your direct manager or an HR admin over your office reviews and
  decides — approve, or reject with a required explanation of why. Nobody can approve their
  own request, no matter how broad their own reach otherwise is; trying to shows up exactly
  like the request doesn't exist. A request already decided refuses a second decision. You
  can withdraw your own pending request at any time before it's decided.
- **What approval actually does.** An approved *add* becomes a real punch, exactly like
  clocking in yourself, just recorded as a correction. An approved *void* means that a wrong
  punch is superseded — never edited or erased, since the raw attendance record is never
  rewritten (see above), but from that point on it's understood as not counting. An *amend*
  does both at once: the wrong punch is superseded and the corrected one takes its place.
- **Reviewing your own requests, and the queue you need to act on.** You can see every
  correction you've ever filed and its outcome. If you're a manager or HR admin, you see a
  queue of everyone else's pending requests you're allowed to decide — never your own, and
  never anyone outside who you already oversee.

## Using it from a browser *(M3.5)*

Everything above through M3.6 existed as an API only. This milestone gives it a real
screen — the sign-in page and the attendance screen — built in IBM's Carbon design
language. The office and admin screens it lacked have since arrived with the milestones
that own their data (holidays, schedules, and pay rules, all M4; filing and approving a
correction, M6a, below). Still absent: a roster of employees.

- **Signing in, for real.** A work email and password on a single sign-in page; a wrong
  password and an unknown email look identical, so the page itself can't be used to guess
  who has an account. Signing out ends the session immediately, even if the network is
  down at that exact moment.
- **One clock-in/clock-out button that always knows what happens next.** The attendance
  screen leads with a single action: it reads "Clock in" or "Clock out" depending on
  whether you're already clocked in, and the moment you tap it, the screen tells you which
  state you're now in and how long you've been at it today. A shaky connection that
  retries the tap in the background never records a second punch for the one action you
  took.
- **Your month, laid out as a calendar.** Below the clock button, every day you've
  punched shows its actual clock-in and clock-out times — not a rolled-up number. A day
  that pairs up cleanly also shows its total for that day; a day with a missing punch or
  an odd number of punches shows exactly what was recorded and no total, rather than
  guess at one. You can step back and forward a month at a time to move through your
  history, independent of today's clock button.

## Holiday calendars *(M4a)*

- **Each office has its own holiday calendar, editable by HR.** An HR admin for an office
  can mark a calendar date as a special working day, a special non-working day, a regular
  holiday, or a double regular holiday (two regular holidays coinciding — rare, but the
  Labor Code recognizes it) — each with a name, like "Ninoy Aquino Day." Any day with no
  entry is an ordinary working day; nothing has to be marked "ordinary."
- **One office's calendar never leaks into another's.** Manila's holidays show up only on
  Manila's calendar; Cebu's HR admin can't see, edit, or even confirm the existence of a
  Manila holiday — trying returns "not found," identical to trying a holiday that was never
  created, so the attempt itself reveals nothing.
- **Cloning last year's calendar.** Since most Philippine holidays fall on the same
  month/day every year (a few movable ones, like Eid, don't), an HR admin can clone a whole
  year's holidays into the next with one action. Cloning skips any date the new year already
  has an entry for — running it twice, or after already adding a few dates by hand, never
  duplicates or overwrites anything.
- **On the calendar screen.** `/office/holidays` shows the same month-grid the attendance
  screen uses: click an empty day to add a holiday, click a marked day to edit it, and a
  "Clone from last year" button seeds the whole year at once.
- **Every change is logged.** Adding, editing, or removing a holiday records who did it and
  when, with the holiday itself as the logged subject — the same audit trail the system will
  one day show HR in full.
- **Now feeds pay (M5).** When M4a shipped, a holiday here was configuration only. The
  compute engine (M5) now reads the calendar — a special-non-working day reprices worked
  hours to 130% — and any edit to the calendar re-runs that pricing automatically for
  every already-computed day it affects.

## Shift templates and schedules *(M4b)*

- **A shift template is a reusable weekly shape.** An HR admin builds a 7-day week — say,
  Monday through Friday 8:00 to 18:00 with an hour's break, weekends off — once, and reuses
  it across however many people actually work that pattern, rather than setting hours
  person by person.
- **One template per office becomes that office's default.** Set it once, and anyone in the
  office with no more specific assignment falls back to it automatically — a new hire
  is scheduled correctly from day one without anyone having to remember to assign them
  anything.
- **A template can be assigned to a specific employee, effective a chosen date** — the way
  to schedule someone differently from their office's default, from a date forward, without
  losing the history of who was on what before. (Assigning by whole department is supported
  too, on the same screen's underlying API; there is no department picker on the screen
  itself yet, only an employee one.)
- **A per-date override handles the one-off** — a rest-day swap, covering someone else's
  shift on a Saturday and taking the following Monday off in exchange, or any single day
  that needs to differ from the template it would otherwise resolve to. It always wins over
  whatever a template or assignment would have said for that date.
- **A cross-midnight shift is a real, supported shape** — 17:00 to 03:00 the next day is one
  shift, not two, and it resolves correctly to how many minutes were actually worked.
- **The resolved calendar shows what actually applies, and why.** Pick an employee on
  `/office/schedules` and see, day by day, whether they're scheduled to work or rest, their
  hours if working, and which layer decided it — their own override, an assignment to them
  specifically, their department's assignment, or the office default — click any day to add
  or edit that day's override directly.
- **One office's schedules never leak into another's**, the same scoping rule the holiday
  calendar uses: a Cebu HR admin can't see, edit, or confirm the existence of a Manila
  shift template, assignment, or override — trying returns "not found," identical to
  trying one that was never created.
- **Every change is logged** — who built a template, who assigned it, who set an office
  default, who wrote an override — the same audit trail the holiday calendar's changes get.
- **Now feeds pay (M5).** When M4b shipped, a resolved schedule was configuration and a
  resolved answer only. The compute engine (M5) now reads it to decide whether a day was
  worked or rest and how many minutes were scheduled — and any change to a template, an
  assignment, or an override re-runs that pricing automatically for the days it touches.

## Pay rules *(M4c)*

- **A system administrator sets the company's pay rates, floored by law.** One matrix per
  effective date: how much extra an ordinary day, a special working day, a special
  non-working day, a regular holiday, and a double regular holiday pay — worked, worked on
  a rest day, and unworked — plus overtime and night-differential rates. Every rate is
  checked against the Labor Code's statutory minimum before it can be saved; a rate below
  the floor is refused outright, naming exactly which figure is too low, never silently
  accepted or merely warned about.
- **A rate change is always a new version, never an edit to an old one.** Correcting last
  year's rates means adding a new version effective from today (or whenever the change
  should take hold); a version is immutable once created — you supersede it, never rewrite
  it — so what applied on any past date is never in question. (A just-created mistake can be
  deleted outright; there is no edit-in-place.)
- **Only a system administrator can touch this.** Unlike the holiday calendar or
  schedules, which any HR admin manages for their own office, pay rates apply to the whole
  company at once — there is no office to hand off to, so this is reserved for the one
  role with company-wide authority.
- **On the pay-rules screen.** `/admin/pay-rules` lists every version that has ever been
  set, newest first, with the one currently in effect called out; "New version" opens a
  full rate matrix to fill in, showing the statutory floor beside each figure as a guide
  before you even submit.
- **Every change is logged** — who set which version and when — the same audit trail the
  holiday calendar and schedules get.
- **Now feeds pay (M5).** When M4c shipped, these rates were configuration only. The
  compute engine (M5) now reads them to price every worked day, and a new rate version
  reprices every affected day automatically. **With this feature the configuration spine
  (M4) was complete** — holiday calendars, schedules, and pay rules all in place for that
  engine, which M5 delivered.

## The compute engine *(M5a, M5b)*

- **Every day's punches now turn into a priced total, automatically.** The moment an
  employee clocks in and out (or HR approves a correction to a missed punch), the system
  reads that day's schedule, checks whether it was a holiday, and prices exactly how many
  minutes were worked at exactly what rate — no one has to ask for it, and no one has to
  wait for a payroll run to see it.
- **The employee's own attendance screen shows both the punches and what they turned
  into.** `/me/attendance` already showed the raw clock-in/clock-out times (M3.5); it now
  also shows, for each day, a compact worked-hours total right in the calendar cell, and a
  full breakdown — how many minutes were regular time, how many were night differential,
  how many were overtime, plus the unworked-holiday premium on a paid holiday nobody worked,
  each at the percentage it priced — in a detail panel below the calendar when that day is
  selected. The raw punches are still right there alongside it;
  the computed total is additional, never a replacement.
- **The number is always premium-weighted hours, never a peso.** A regular hour reads
  100%; work on a special non-working holiday reads 130%; night hours compound on top of
  whatever the day itself is worth. Turning that percentage into an actual peso amount is a
  gross-to-net decision this system deliberately defers (see `docs/00-overview.md`) — what
  the employee sees here is exactly what was worked and at what premium, nothing more.
- **A manager or field employee who is exempt from overtime law (Art. 82) sees every hour
  price at a flat 100%** — even a holiday they worked, even hours that would otherwise
  qualify for overtime or night differential for anyone else. The exemption is not a
  smaller number tacked on after the fact; the engine simply never applies a premium to
  their time at all.
- **A day nobody could finish tallying honestly says so.** Clock in with no matching
  clock-out, and the day shows zero worked hours and an "incomplete" flag — never a guessed
  number. Filing an adjustment (M3.6) to add the missing punch is how that day gets a real
  total.
- **A config edit that changes pay automatically refreshes every day it affects — no one
  has to ask for it, and nothing has to be re-punched.** *(M5b)* Editing a holiday, adding
  a new pay-rate version, or changing a shift template, an assignment, an override, or an
  office's default schedule each automatically re-prices every already-computed day that
  change could have touched. HR doesn't run a "recompute" button and doesn't have to know
  which days were affected — the system enqueues the recompute itself, the moment the
  config change is saved.
- **Nothing about the raw punch record ever changes.** A recompute only ever touches the
  computed summary — the priced total and its breakdown — never the punch itself. The
  attendance ledger a labor inspector would be shown stays byte-for-byte the same before
  and after, no matter how many times a day's price is recalculated.
- **A closed period is never silently reopened by a recompute.** A day whose pay has
  already been locked for a closed cutoff (M7a, below) is skipped, not
  recalculated — a config change never quietly rewrites numbers that have already been
  finalized.
- **Every recompute is itself an audited event.** Adding, editing, cloning, or removing a
  holiday; a new pay-rate version; any shift-template, assignment, override, or
  office-default change — each one records what triggered the recompute, how many days it
  affected, and whether it finished successfully, the same audit discipline every other
  config change in the system already gets.
- **Two consecutive night shifts are counted correctly, not double-counted.** A punch that
  falls right at the boundary between one overnight shift's day and the next one's is now
  attributed to exactly one of the two days, never both — a fix to how a *repeating*
  overnight schedule is read that this milestone's range-wide recompute exposed. **With
  this feature, M5 — the compute engine — is complete**: every priced day is now correct
  both the moment it's punched (M5a) and every time afterward that the configuration
  pricing it changes (M5b).

## Filing and approving requests *(M6a)*

Correcting your own attendance (M3.6) has existed as an API since before M5; this is the
milestone that gives it a screen, and generalizes the approval machinery underneath it so
leave and overtime pre-authorization (later milestones) plug into the same spine, the same
two queues, and the same request card rather than each getting their own.

- **Filing a correction, from the attendance screen you already use.** A form off
  `/me/attendance` files an add, void, or amend — the same three operations M3.6 always
  supported — with a required note and an optional supporting file, without leaving the
  calendar you're looking at the missing or wrong punch on.
- **My requests.** `/me/requests` lists every request you've ever filed, its current
  status, and — once decided — the outcome. A request still pending can be withdrawn from
  here at any time before someone acts on it.
- **Two approval queues, not one.** `/team/approvals` is a manager's queue — every pending
  request from someone who reports to them, and nothing else. `/office/approvals` is an HR
  admin's — every pending request from someone in an office they administer. The two are
  independent: a request from someone who is both your direct report and in an office you
  HR-administer shows up on both, not once; approving it on either one decides it, and it
  drops off both immediately. Both queues use the same card and the same decide action —
  approve, or reject with a required reason — because a manager and an HR admin are
  deciding the identical thing, just reached through a different relationship to the
  requester.
- **Still one decision, not two.** Filing, then one authorized approver deciding, is the
  whole flow today — there is no separate "manager approves, then HR approves" hand-off
  yet. That two-step chain is coming with leave (a future milestone), which is the first
  request type that actually needs it; attendance corrections don't, so M6a didn't build
  a second step nobody was using.
- **A system admin with no direct reports and no HR-administered office sees neither
  queue.** That's deliberate, not an oversight: the two queues are scoped by an actual
  relationship to the requester (org chart, or office administration), and "is a system
  admin" isn't one — a sysadmin who also happens to manage people or administer an office
  sees the same queues anyone in that position would.

---

## Leave — setup and balances *(M6b-a)*

The foundation the leave *request* (a later milestone) will need before it can exist: a
per-office catalog of leave types, an HR admin's ability to manually credit an employee's
balance, and a way for anyone entitled to see it to read it back. **Nothing here lets an
employee take leave yet** — there is no leave request, no approval, no accrual job; every
balance moves only because HR deliberately granted it.

- **Leave types, configured per office.** `/office/leave-types` lets HR list, create, and
  edit the leave types available in the offices they administer — name, whether it's paid,
  whether it requires a supporting attachment, whether it banks a balance an employee can
  spend from or is an event entitlement that doesn't (Maternity, Paternity, Solo Parent,
  VAWC, Magna Carta — tied to a qualifying event, never a number that runs low), whether
  it's convertible to cash, and a max carryover. A fresh office starts with the Philippine
  statutory set already seeded, plus company Vacation and Sick Leave — nothing to configure
  before HR can grant a single day. A type is retired by marking it inactive; there is no
  delete, the same "archive, never remove" rule the rest of the system's config follows.
- **HR grants leave manually, logged.** `POST /leave/grants` credits an employee's balance
  in a leave type — 5 days, say — and every grant is one row in an append-only ledger with
  a required reason, never a number silently bumped up. A re-grant is a second logged row,
  not an edit of the first. Granting only works for a type that actually banks a balance:
  trying to grant into an event entitlement (Maternity and the like) is refused outright —
  there is no balance there to credit.
- **Balances, shown in readable days.** `/me/leave` shows every leave type an employee can
  see a balance for, in both raw minutes and the day/hour/minute breakdown people actually
  think in — "5 days," not "2400 minutes." A manager or HR admin can read the same breakdown
  for anyone within their scope (a direct report, or anyone in an office they administer) the
  same way M2's employee directory already works: an employee outside that scope looks
  exactly like one that doesn't exist, never a "you're not allowed" that confirms they do.
  A type nobody has been granted into yet still shows up, at zero — the type existing and a
  balance existing are two different questions.

**Deliberately not here yet:** an employee filing a leave request, a manager or HR deciding
one, the two-step manager-then-HR approval chain leave will need (the M6a approval screens
only know a single decider today), tenure-based accrual, carryover running automatically at
year-end, cashing out unused leave, and the compute engine reading a leave day at all — a
day taken as leave prices exactly as any other day would today, because nothing files one
yet.

---

## Requesting leave (M6b-b)

Everything M6b-a's foundation was for: an employee can now actually take leave, and the
approval it needs runs through two people, not one — the first request type where that's
true.

- **File a leave request, right from your own balance.** The same `/me/leave` screen that
  shows your balances now has a form: pick a leave type you actually hold a balance for,
  a date range, full day or half day, and a note — the same shape as filing an attendance
  correction (M6a), so nothing new to learn. The amount it will cost is computed from the
  *scheduled working days* in that range, not the calendar days — a weekend or a rest day
  inside the range is never charged, and you can't inflate or shrink your own request by
  typing a different number, because there isn't one to type.
- **Two decisions, not one.** Filing lands the request with your manager first. Once they
  approve, it moves to HR — your balance is completely untouched at this point, because
  nothing is actually debited until the SECOND, final decision. HR's approval is what
  actually spends the balance and marks the days as paid leave; if HR rejects instead, your
  balance never moves at all, exactly as if the whole thing had never happened.
- **The two queues from M6a now genuinely take turns.** A freshly-filed leave request shows
  up on your manager's `/team/approvals`, and NOT yet on HR's `/office/approvals` — HR has
  nothing to decide until the manager clears it. The moment the manager approves, the
  request disappears from the manager's queue and appears on HR's — the same `<RequestCard>`
  and decide action either queue already used for attendance corrections, now showing an
  "Awaiting HR" tag while it waits on the second hop.
- **A manager who's also HR still needs someone else.** If the same person administers the
  requester's office AND is their manager, they still can't clear both decisions
  themselves — the system requires the second decision come from someone else in HR, a
  genuine second pair of eyes, not just a formality.
- **The approved days show up as paid leave, not a gap.** Once HR approves, the days in the
  range appear on the employee's month calendar priced at 100% — a `leave_with_pay` day,
  the same place a worked day or a paid holiday would show its own breakdown — never a
  blank space that looks like an unexplained absence.
- **Withdraw it yourself, right up until it's decided.** `/me/requests` still lets you pull
  back a leave request you filed, whether it's waiting on your manager OR already sitting
  with HR — only once someone has actually decided it (approved or rejected) does
  withdrawing stop being an option.

**Deliberately not here yet:** a half-day request still debits half a day correctly but
computes as a full day's paid leave; the payroll export (M7b, below) now rolls a closed
period's summary lines into a per-employee report, but pricing a paid leave day *into* those
lines is still a separate open item; and the chain still tops out at two decisions — there is
no third hop for anything, because nothing needs one yet.

## Pre-authorizing overtime *(M6c)*

Overtime is only paid when it was approved in advance — and only up to the minutes that were
approved. Working late by itself earns nothing extra; a pre-authorization is what turns some
of those extra minutes into paid overtime, and the rest stays visible as unpaid time rather
than quietly vanishing or being paid anyway.

- **File an overtime request for a day you'll work long.** Any employee can file for their
  own record — pick the date and the number of hours (in quarter-hour steps) you expect to
  work past your schedule, with a required note. It's the same kind of self-service filing as
  a leave request or an attendance correction, not something only an admin can do.
- **One approval, on either queue.** Overtime is single-hop, like an attendance correction:
  the moment you file, it shows up on **both** your manager's `/team/approvals` and your
  office HR's `/office/approvals`, and whichever of them decides first settles it — no
  two-step manager-then-HR hand-off. Approving it writes nothing to any balance; the approved
  request itself is simply the cap the compute engine reads for that day.
- **The engine pays the smaller of worked and approved.** On a day you actually worked past
  your schedule, an approved request caps the paid overtime at its approved minutes — approve
  one hour of a two-hour-long day and exactly one hour is paid as overtime. Whatever you
  worked beyond the cap is recorded as **unpaid overtime**, a visible number on the day, never
  paid and never silently dropped.
- **No pre-authorization means no paid overtime.** Work a long day without an approved request
  and the engine pays zero overtime for it — the whole excess shows up as unpaid overtime.
  That's the strict model: overtime is a thing you ask for first, not something the clock
  grants you by itself.
- **Managers and field staff (Art. 82-exempt) are unaffected.** They earn no overtime premium
  to begin with, so an overtime request neither helps nor applies — the same exemption that
  already zeroes their holiday and night-differential premiums.

**Now rolled into the export:** the paid-overtime hours and the unpaid-overtime total surfaced
on the day are carried into the payroll export (M7b, below) — the paid overtime as its own
`(kind, applied_bp)` line, the unpaid-overtime minutes as a period total.

---

*(That completes M6 — the whole request-and-approval subsystem: attendance corrections, leave,
and overtime pre-authorization, all on one shared spine, two queues, and request card.)*

## Closing a cutoff period *(M7a)*

A pay period eventually has to stop moving so the numbers can be trusted and paid. M7a gives
HR that control: each office runs on the standard PH semi-monthly calendar — the 1st–15th and
the 16th–end-of-month — and HR can **close** a period once it's settled.

- **HR closes a semi-monthly period.** An HR admin closes the current (or a past) window for an
  office they administer. Closing **freezes every day in it**: each daily summary in the window
  flips to *locked*, and the numbers stop changing. The employee sees their locked days on
  their own attendance breakdown, exactly as computed — nothing is hidden or reshaped.
- **A period won't close over an open problem.** If any day in the window is still *incomplete*
  (a missing clock-out), or any leave, overtime, or attendance-correction request touching an
  in-period day is still awaiting a decision, the close is **refused and tells HR exactly what
  to resolve first** — the specific incomplete dates and the pending requests. You close a
  clean period, never one with loose ends silently frozen inside it.
- **A closed period refuses approvals on its days.** Once a period is closed, approving a
  request that would change a day inside it is **refused** — the day is locked, and the correct
  move is to reopen the period, not to force the change through. Filing a request is still
  allowed; it just can't be approved onto a frozen day until the period reopens.
- **Reopening is deliberate and audited.** HR can **reopen** a closed period, which unlocks
  every day back to computed and lets those refused approvals go through — but it **requires a
  reason**, and that reason is recorded in the audit log. Reopening is a visible, accountable
  act, not a quiet escape hatch.
- **The raw punch log never moves.** Closing, locking, and reopening only ever touch the
  *derived* daily numbers — the append-only record of who punched when, which a labor inspector
  would be shown, is never altered by any of it.

## Exporting a closed period for payroll *(M7b)*

Once a period is closed and its numbers are frozen, HR can **export** it — a per-employee
earnings breakdown, ready to hand to whoever cuts the checks.

- **HR exports a closed period's earnings breakdown.** For an office they administer, HR pulls
  the whole closed period at once: every employee who worked in it, each with their total worked,
  late, undertime, and unpaid-overtime minutes, and a **line-by-line breakdown of the paid hours**
  — regular time, the overtime and premium hours, each holiday or rest-day bucket — as *minutes*
  and the *basis points* (the multiplier) that applies, tagged with the exact pay-rule version
  that priced it. The employee's base rate rides along for reference.
- **It reconciles, day-for-day, against the calendar.** The export is not a separate calculation
  — it is the same frozen daily summaries the employee already sees on their own attendance
  breakdown, added up over the period. Every line and every total ties back to the calendar
  exactly; there is no place for a number to appear in payroll that the employee couldn't see in
  their own days.
- **Hours and multipliers, not pesos.** The export deliberately stops at minutes and basis
  points — it hands payroll the *hours* and the *rules*, and the actual peso gross-to-net is done
  downstream. This HRIS owns the hours; it does not print the paycheck.
- **Only a closed period exports, and it's reproducible.** An open period can't be exported —
  there's nothing final to hand over yet — and because a closed period's numbers are frozen,
  exporting it twice yields the exact same result every time. If a correction is needed, HR
  reopens the period (audited, with a reason), fixes it, closes again, and re-exports.

**Deliberately not here yet:** a downloadable **CSV/spreadsheet or PDF** (the export is
structured data today); the **peso gross** earnings (that's downstream payroll); a **draft** of
an open period; and a **full roster** including employees with no attendance in the window (only
those who actually worked in-period appear).

---

*(Cutoffs, period locking, and the payroll export are done — M7 is complete.)*

## Building the company's org tree *(M8a)*

A company's shape isn't fixed at seed time — a new branch opens, a department is renamed, an
office is retired. M8a lets a **system administrator** build and reshape the whole
organization tree from the app: organizations, the offices under them, and the departments
under those.

- **A system administrator builds the org tree.** Create an organization, add offices under
  it, add departments under an office — each on its own admin screen, with the parent chosen
  from the tier above. Names and codes (a short office code like `MNL`, a department code like
  `OPS`) are editable after the fact. Office codes are unique company-wide; a department code
  only has to be unique within its office, so `OPS` can name Operations in both Manila and
  Cebu.
- **Retire, don't delete.** An office or department that closes is **archived**, never removed
  — the row, its history, and every employee record that ever pointed at it stay intact, so a
  closed office's past payroll and attendance are never orphaned. Archived nodes drop out of
  the normal lists but can be shown with a toggle, and **un-archived** to bring them back.
  There is no delete anywhere in here.
- **Only a system administrator, company-wide.** Unlike holiday calendars or schedules — which
  each office's HR admin manages for their own office — the org tree is global structure, so
  it's a system-administrator-only surface. An HR admin or a plain employee who tries is simply
  refused.
- **Every change is audited.** Creating, renaming, archiving, or un-archiving any node writes
  an audit-log entry naming who did it — so the company's structural history is recoverable
  even before the dedicated audit-log viewer (later in M8) arrives.

**Still to come in M8:** the multi-step employee profiler (onboarding a person through a
guided wizard), role management, assigning which offices an HR admin covers, and the
activity-log viewer.
