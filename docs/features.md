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

## Timekeeping *(M3 — in progress)*

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

---

*(Employees correcting their **own** missed punches, through a request their manager or HR
approves, is a dedicated feature coming in its own milestone. Turning punches into computed
pay — the schedules, holidays, and premium-rate engine — follows after that.)*
