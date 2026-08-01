# M10b — Document management (design)

**Status:** brainstormed with the user 2026-08-01, approved, pending spec review
**Milestone:** M10b. Split into **M10b-a** (catalog) and **M10b-b** (files) — see Scope.
**Depends on:** M2 (`employees`, `offices`, `hr_admin_offices`, `EmployeeScope`, `OfficeScope`),
M3.6 (spatie/medialibrary on the RustFS-backed `attachments` disk, and the app-mediated stream
in `DownloadAttachmentController`), M10a (`EmployeePolicy`'s office-pivot authorization shape,
the `POST`-not-`PUT` multipart lesson, `authedBlobUrl`, `IdentificationScan`).

> **Hard dependency on PR #31 (`m10a-followups`).** M10b's seeder must be called from
> `hris:bootstrap-admin` *above* the System-Admin guard. M10a shipped that call below the
> guard, where it can never run on an already-bootstrapped production database; PR #31 hoists
> it. Implementing M10b on a base without #31 reproduces that bug in a second module.
> The `upload_max_filesize` 2M → 12M fix in the same PR is likewise load-bearing here —
> scanned contracts are larger than ID photos, not smaller.

## Goal

M10a gave every employee a personnel file: who they are, how to reach them, and their
government IDs. It cannot hold a *document* — a signed contract, an NBI clearance, a medical
certificate, a company policy. Those are the paper an HR department actually files, and the
questions it must answer about them are "where is it", "has it expired", and "who is missing
one".

M10b adds a document module: an admin-editable catalog of document kinds, files attached
polymorphically to employees or offices, and the two compliance reads that make the catalog
worth maintaining.

## Decisions

### 1. Spatie's `media` stays the file layer; the new table carries only what it doesn't

`document_files` is a `HasMedia` model with a single `file` collection, exactly like
`EmployeeIdentification`'s `scan` and `Request`'s `attachment`. It stores **no path, no disk,
no object key, and no `media` column.**

The alternative — a `media` id as a plain column, as the original sketch had — is a foreign
key spatie does not know about. Medialibrary's own delete observer would leave the row
dangling, and nothing in the database enforces the reference. Writing our own storage layer
instead would reimplement four things that already work and are already proven in production:
RustFS storage, mime/size validation, the app-mediated stream, and delete-time cleanup.

The link is spatie's own `media.model_id → document_files.id`, in the direction medialibrary
already maintains.

### 2. `DocumentBucket` is dropped; there is one grouping concept

The original sketch carried `DocumentCategory` ("the grouping of documents") *and*
`DocumentBucket` ("mass bucketing") *and* a `type_id` on `Document`. Three grouping concepts
for one taxonomy.

"Bucket" had three plausible readings — a retention class, an upload batch, or a second
taxonomy level — and none was needed to ship. It is dropped. If retention policy or bulk-upload
tracking is ever required, each is a well-defined addition to a schema that has room for it,
rather than a speculative table nobody can define today.

### 3. `type_id` and `DocumentCategory` were one concept named twice

The sketch defined no `DocumentType` table, so `type_id` was a dangling FK pointing at
whatever grouping table existed — and `DocumentCategory` was the only one. A `DocumentType`
would also have carried columns identical to `DocumentCategory`'s, which is the signature of
one concept drafted twice rather than two concepts.

The column is `category_id`, matching `EmployeeIdentification.category_id` from M10a. What
would have justified a separate table is *behaviour* — expiry rules, mime constraints,
required-ness. Those exist (decisions 4 and 5), but they are columns on `documents`, not a
second taxonomy.

### 4. Expiry is computed once from the kind, then frozen on the file

`documents.validity_months` (nullable) says how long a kind stays valid. `document_files`
carries `issued_on` and `expires_on`.

**`expires_on` is stored, not derived at read time.** When a client omits it and the kind has
a `validity_months`, the action computes `issued_on + validity_months` once, at write time.
After that it never moves.

This is the same rule that makes `employment_records` effective-dated: a later configuration
change must not rewrite what was true. If HR changes NBI Clearance from 6 months to 12,
**already-filed clearances must not silently gain six months of validity** — the certificate in
the drawer says what it says. A stored value also lets HR override the arithmetic when the
document's own printed expiry disagrees with it, which happens often enough to matter.

`validity_months IS NULL` means the kind never expires — a signed contract, a company policy.
`expires_on IS NULL` on a file of an expiring kind means nobody supplied an issue date, and is
surfaced as *unknown*, never as *valid*.

Expiry was the single largest gap when this design was measured against industry-standard
HRIS products. The documents that matter most are exactly the ones that lapse: clearances,
medical certificates, PRC licences, permits.

### 5. `is_required` needs `applies_to`, so both are on `documents`

`is_required` alone is ambiguous across two owner types. "Which employees are missing a
required document?" would report every office as missing an NBI clearance, and every employee
as missing a business permit.

`documents.applies_to` is `'employee'`, `'office'`, or `NULL` (both). It does two jobs: it
scopes the missing-required query, and it filters the upload dropdown so nobody files a
business permit against a person.

This partially revises the config-only answer in decision 6 below, and the split is
deliberate: **config
decides which *models* may own documents** (an engineering concern — a new owner type needs
routes, a policy, and UI regardless), while **`applies_to` decides which *kinds* apply to
which** (a company's own business, changing without a deploy). Each sits on the side of
`04-backend-conventions.md`'s config-vs-database line where it belongs.

### 6. Documentable types are a config whitelist. There is NO Laravel morph map.

> **Amended 2026-08-01, during Task 2.** The original decision registered
> `Relation::morphMap()` so the database would store `'employee'` instead of
> `App\Models\Employee`. That was wrong, and the reasoning behind it was incomplete. It is
> recorded here rather than quietly rewritten, because the mistake is instructive.

`config/documents.php` whitelists which models may own documents:

```php
'documentable' => [
    'employee' => Employee::class,
    'office'   => Office::class,
],
```

It is used for validation, routing, and wire serialization. It is **not** passed to
`Relation::morphMap()`.

**Why not.** `Relation::morphMap()` is process-global. It does not scope to one table — it
changes `getMorphClass()` for every morph in the application. The original analysis checked
spatie's `media` table, found neither `Employee` nor `Office` in it, and concluded the map was
safe. It missed the second morphing package: **spatie/activitylog**.

`Employee` and `Office` both use `LogsActivity`. `activity_log.subject_type` holds full class
names today (`App\Models\EmployeeProfile`, `App\Models\EmployeeIdentification`, …), and that
column is **exposed on the API** (`ActivityResource`) and **filterable**
(`ListActivityController`). Registering a global map would make every *new* Office or Employee
audit row write `'office'` while every historical row kept the FQCN — a filter that silently
misses half the data in both directions, and a live API-contract change, for a module that has
nothing to do with the audit viewer. It broke five existing tests, which is how it was caught.

Backfilling `activity_log` to match was rejected out of hand: this system's audit trail is
evidence, and rewriting historical rows to suit a new module's storage preference is precisely
the thing the append-only discipline exists to prevent.

**So `document_files.documentable_type` stores the full class name**, exactly as `media` and
`activity_log` already do. The consistency is the point — this is the third polymorphic table
in the schema and it now behaves like the other two.

**The wire contract is unaffected.** `DocumentFileResource` maps the stored class back to its
config alias, so the API still says `"documentable_type": "employee"`. Clients never see a
class name; only the database does.

What is genuinely lost is database-level rename safety: moving `App\Models\Employee` to another
namespace would orphan rows. That cost is already accepted twice in this schema, and the
mitigation is a data migration at rename time rather than a global setting that reaches into an
unrelated module's storage.

This is the codebase's **first application-owned polymorphic relation**. The only two
`uuidMorphs` in the schema today are vendor-published (Sanctum's `personal_access_tokens`,
spatie's `media`).

### 7. Two permissions: `document.manage` and `document.manage.self`

```
document.manage       + owner in the actor's hr_admin_offices  ->  upload, read, delete
document.manage.self  + the employee IS the actor              ->  upload, read. NOT delete.
neither                                                        ->  nothing
```

`document.manage` is office-scoped through the `hr_admin_offices` pivot — the same two-axis
model M10a uses, where the role grants the verb and the pivot grants the reach. A Cebu HR Admin
cannot touch a Manila employee's contract. For office-owned documents the same permission is
scoped by `OfficeScope`, exactly as holidays, schedules, and leave types already are. A System
Admin gets everything through `Gate::before`.

**The self tier permits writing, which M10a's equivalent does not.** M10a denies self-edit of
PII because changing your own birthdate or bank account is a separation-of-duties problem.
Filing your own NBI clearance is not — it is ordinary self-service. But **deletion is excluded**:
removing a filed document is HR's act, which keeps the personnel file append-ish in the same
spirit as punches and corrections.

**Managers get no document access at all**, consistent with M10a, where the redacted manager
view carries no identifications and no dependents.

Both permission names are dotted. That is not cosmetic: spatie registers its own `Gate::before`
granting any ability whose *name* matches a held permission, so a permission named
`viewDocuments` would silently grant a policy ability of that name and bypass the office pivot
entirely. `RbacSeeder` carries a reserved-words comment about this (PR #31).

### 8. Every upload is a new row

There is **no unique constraint on `(document_id, documentable_type, documentable_id)`**, and
that absence is the design. An employee can hold several files of the same kind — last year's
contract and this year's, an expired clearance and its replacement — ordered by upload date.

Contrast M10a's `unique(employee_id, category_id)`, which exists because one employee has
exactly one TIN. A document kind is not a slot.

No version chains, no `is_current` flag: both are machinery for a problem an ordered list with
expiry dates already solves.

### 9. `sha256` records integrity; it does not deduplicate

Stored at upload from the real bytes, as a plain column with **no unique index**. It exists so a
file can later be proven unaltered — the thing an inspector actually asks for.

Deduplicating on it was rejected: the same PDF legitimately attaches to two employees (a shared
policy, a counter-signed contract), and sharing one stored object across rows makes deletion
ambiguous about whose delete removes the file.

### 10. M10a's identification scans stay where they are

An identification scan is 1:1 with a specific numbered ID, guaranteed by
`unique(employee_id, category_id)` and paired with the number it depicts. Folding it into a
general document table would lose both.

Two mechanisms, each fitting its job, both on the same RustFS disk with the same stream pattern
and the same size limit.

## Schema

```sql
create table document_categories (
  id          uuid primary key default uuidv7(),
  code        text not null unique,
  name        text not null,
  description text,
  created_at  timestamptz,
  updated_at  timestamptz
);

create table documents (
  id              uuid primary key default uuidv7(),
  code            text not null unique,
  name            text not null,
  description     text,
  category_id     uuid not null references document_categories(id),
  applies_to      text,                    -- 'employee' | 'office' | null (both)
  is_required     boolean not null default false,
  validity_months integer,                 -- null = never expires
  created_at      timestamptz,
  updated_at      timestamptz
);

create table document_files (
  id                uuid primary key default uuidv7(),
  document_id       uuid not null references documents(id),
  documentable_type text not null,          -- morph alias: 'employee' | 'office'
  documentable_id   uuid not null,
  uploaded_by       uuid references users(id) on delete set null,
  sha256            text not null,
  issued_on         date,
  expires_on        date,
  created_at        timestamptz,
  updated_at        timestamptz
);
create index document_files_documentable on document_files (documentable_type, documentable_id);
create index document_files_document_id  on document_files (document_id);
create index document_files_expires_on   on document_files (expires_on) where expires_on is not null;
```

`document_files` implements `HasMedia` with a **single-file** collection `file` on the
`attachments` disk, accepting `pdf,jpg,jpeg,png` up to 10 MB — the limits `Request`'s
`attachment` and `EmployeeIdentification`'s `scan` already use.

`singleFile()` is per **row**, and does not contradict decision 8. One `document_files` row
holds exactly one file; an employee holding three NBI clearances over three years holds three
rows. The collection constraint means re-uploading against the *same row* replaces rather than
accumulates — which is the correction case, not the renewal case. A renewal is a new row.

**Deleting a `documents` or `document_categories` row that still has files is refused**, never
cascaded. Losing a signed contract because someone tidied the catalog is not an acceptable
failure mode.

## API

```
GET    /documents/catalog                                any authenticated; honours applies_to
GET    /admin/document-categories                        \
POST   /admin/document-categories                         |
PATCH  /admin/document-categories/{category}              |  catalog CRUD
DELETE /admin/document-categories/{category}              |  document.manage
GET    /admin/documents                                   |
POST   /admin/documents                                   |
PATCH  /admin/documents/{document}                        |
DELETE /admin/documents/{document}                       /

GET    /admin/documents/expiring?within_days=30          compliance, office-scoped
GET    /admin/documents/missing                          compliance, office-scoped

GET    /employees/{employee}/documents                   self or HR-in-office
POST   /employees/{employee}/documents                   multipart
DELETE /employees/{employee}/documents/{file}            HR only, never self
GET    /employees/{employee}/documents/{file}/download   app-mediated stream

GET    /offices/{office}/documents                       OfficeScope + document.manage
POST   /offices/{office}/documents                       multipart
DELETE /offices/{office}/documents/{file}
GET    /offices/{office}/documents/{file}/download
```

**Uploads are `POST`, never `PUT`.** PHP parses a multipart body only on `POST`; a
`PUT multipart/form-data` arrives with an empty `$_FILES` and the file vanishes with no error.
`CLAUDE.md` records this from M10a.

**Downloads are app-mediated streams, never object URLs or presigned links.** RustFS publishes
no ports in production precisely because every file goes out through the app.

**Denials are 404, not 403**, throughout — the owner id is in the URL, and a
403-for-real/404-for-nonexistent split lets any authenticated user enumerate ids.

**Validation failures are 400**, not Laravel's default 422: `bootstrap/app.php` maps
`ValidationException` to 400 with `error.code = 'validation_failed'`, and `03-api.md` reserves
422 for structurally-fine-but-semantically-rejected requests.

The employee's own document list returns `expires_on` and a derived status, so self-service can
show "your NBI clearance expires in 12 days" without a separate endpoint. The two
`/admin/documents/*` compliance reads are scoped to the caller's `hr_admin_offices`.

An upload whose `document_id` has an `applies_to` incompatible with the owner type is rejected
at validation, not silently accepted.

**`GET /admin/documents/expiring` and `/missing` must be registered before any parameterised
`/admin/documents/{document}` GET route.** No such show route exists in this design, so there
is no collision today — but whoever adds one will find `expiring` bound as a `{document}` id
and 404ing on model resolution. Register literal segments first.

Note the two ways to read the catalog are deliberate and serve different callers:
`GET /documents/catalog` is the lightweight, any-authenticated read that populates upload
dropdowns and honours `applies_to`; `GET /admin/documents` is the CRUD index behind
`document.manage`.

## Seeding

`DocumentCatalogSeeder` ships a Philippine starter set with real behavioural values:

| Kind | applies_to | required | validity |
| --- | --- | --- | --- |
| NBI Clearance | employee | yes | 6 months |
| Medical Certificate | employee | yes | 12 months |
| Employment Contract | employee | yes | never |
| 201 File | employee | no | never |
| Company Policy | both | no | never |
| Business Permit | office | yes | 12 months |

Called from `hris:bootstrap-admin` **above** the System-Admin guard, so an already-bootstrapped
production database gains the catalog on an M10b deploy.

**Idempotent by insert-if-absent (`firstOrCreate` on `code`), NOT by overwrite** — amended
2026-08-01 during Task 4. The original text said `updateOrCreate`, copied from
`ProfileCatalogSeeder`. That is correct there and wrong here: identification categories are
fixed by Philippine law and no UI edits them, so rewriting them every run is a no-op. This
catalog is admin-editable, and `hris:bootstrap-admin`'s own docblock instructs ops to re-run it
whenever a later milestone adds catalog data — so `updateOrCreate` would silently reset an HR
Admin's edit (an NBI Clearance changed to 12 months, say) on the next deploy.

The trade this accepts: a later milestone cannot change a seeded *default* through this seeder.
That is correct for an admin-editable catalog — admin edits outrank seed defaults, and a real
default change should ship as an explicit migration that says so.

Unlike M10a's identification categories — fixed by Philippine law — these are a *starting point*
for an admin-editable catalog, not the whole of it.

## Frontend

- A **Documents section** on `/employees/{id}/profile`, beside the personnel file: list with
  expiry status, upload, download. Visible to self and in-scope HR; absent for managers.
- A **Documents section** on the office admin screen.
- An **admin catalog screen** for categories and kinds, including `applies_to`, `is_required`,
  and `validity_months`.
- A **compliance view**: expiring soon, and missing required, for the actor's offices.

Uploads reuse `ProfileForm`'s multipart pattern; preview and download reuse `authedBlobUrl` and
`IdentificationScan`. Dates on the wire are `YYYY-MM-DD` strings. Every colour, spacing value,
and radius reads a `var(--*)` from `carbon.css`.

## Testing

The policy matrix is the test that matters, and it now spans two owner types: self, in-scope HR
Admin, out-of-scope HR Admin, manager, stranger, and System Admin, against every route —
asserting **404** specifically on denial, and asserting a manager's response contains no
document data at all.

Beyond that:

- `expires_on` computed from `validity_months` at write time.
- **`expires_on` frozen when `validity_months` later changes** — the history-rewrite guard, and
  the sharpest test in the milestone.
- `applies_to` filtering the catalog, and rejecting a mismatched upload.
- The missing-required query respecting `applies_to`, so offices are not reported as missing
  employee-only documents.
- Office scoping on both compliance reads.
- Deleting a catalog row that has files is refused.
- Media attaches **after** the DB transaction commits, per the ordering PR #31 established —
  a rollback must not leave a row pointing at a deleted object.

Tests run against real PostgreSQL, as always.

## Scope

Larger than M10a, which ran 16 tasks: three tables, nineteen routes, four screens, a morph map,
two permissions, and catalog CRUD that M10a did not have. This roadmap already splits large
milestones (M4a/b/c, M6a/b/c, M8a/b/c), and M10b follows suit.

| | Scope | Ends with |
| --- | --- | --- |
| **M10b-a** | Schema, morph map, permissions, policy, catalog CRUD, seeder, catalog admin screen | A configurable but empty document system |
| **M10b-b** | Upload / list / download / delete for both owner types, the two Documents sections, the compliance view | The feature working |

Each gets its own plan and implementation cycle. **This spec covers both**; the implementation
plan that follows covers M10b-a only.

## Deferred

| Item | Trigger that revives it |
| --- | --- |
| **Retention policy / auto-purge** | A Data Privacy Act 2012 review, or a legal hold requirement. `DocumentBucket`'s most defensible reading was a retention class; it is dropped for now and this is where it would return. |
| **Bulk upload** | HR filing forty scanned contracts at once. The other defensible reading of "bucket" — an upload batch with its own id and timestamp. |
| **Per-category access control** | A document class with a different audience than the rest — a payslip archive, or a disciplinary file a direct manager must not read. Today's model is manage-or-nothing. |
| **E-signature** | A contract that must be signed in-system rather than uploaded after signing. An integration, not a module. |
| **Expiry notifications** | Someone asking to be emailed rather than having to open the compliance view. The data is already there; only the delivery is missing. |
| **Document search across owners** | Enough documents that browsing per-employee stops working. |
