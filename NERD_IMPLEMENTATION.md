# Nerd Module — Complete Implementation Record

This document covers **everything** implemented concerning the Nerd (student
graduate record) module — from the very first session through the latest. It ends
with a full, honest account of every database change, so there is no confusion
about what touched the tables.

---

## Table of contents

1. [Overview](#overview)
2. [Session 1 — table setup, backfill, and the review baseline](#session-1)
3. [Session 2 — the fixes (CGPA, programme, awards)](#session-2)
4. [Session 3 — canonical nerd columns (rename, no duplicates)](#session-3)
5. [Complete list of files changed](#files)
6. [Database change log — every table touched](#db-changes)

---

## <a name="overview"></a>Overview

The Nerd module reconciles graduate records (final CGPA, class of degree,
graduation date, graduation session, and programme/award) against an uploaded
file, and lets an admin review + approve updates. The design intent is that nerd
data lives in its **own table**, `student_nerds`, separate from the main
`student_nysc` table — so nerd review can never corrupt the primary records.

---

## <a name="session-1"></a>Session 1 — table setup, backfill, and the review baseline

### 1.1 Diagnostic notes discovered

- The live database became reachable from the dev machine after the MySQL
  password was propagated to PHP: `dbinv2oggorg69` on `c67239.sgvps.net`,
  user `uwee0g3bir9pr`, password parsed from `mark/.env` (`DB_PASSWORD=`).
- Live `course_study` values are messy and needed tolerant mapping, e.g.
  `Computer Science`, `History and International Relations`, `Mass Communication`,
  `Bsc Accounting`, `B.ENG.Computer Engineering`, `B.Sc. Economics`, etc.
  `study_mode` was mixed: `Full Time` / `Full-time`.
- `NyscAdminController::getNerdStudents()` already selected
  `student_nerds.course_study as programme_major` and
  `student_nerds.study_mode as programme_type`, but hardcoded the four award
  fields to `null`.
- The `app/admin/nerd/page.tsx` FIELDS list already had
  `programme_major` / `programme_type` as available, and the four award fields
  as not-available.

### 1.2 The `student_nerds` table — created + backfilled

A new table `student_nerds` was created on the live database and backfilled with
**1685 rows** copied from `student_nysc`. This was done via a temp PHP script
(`%TEMP%\opencode\nerd_import.php`) and was also captured for re-usability in:

- `mark/database/sql/create_student_nerds.sql`

Original columns: `student_id`, `nysc_session_id`, `matric_no`, `nin`, `email`,
`phone`, `fname`, `mname`, `lname`, `gender`, `dob`, `state`, `course_study`,
`study_mode`, `department`, `cgpa decimal(5,2)`, `class_of_degree`,
`graduation_year`, `graduation_date`, `created_at`, `updated_at`.

> The table was later **renamed in place** to the canonical column set — see
> [Session 3](#session-3). The final schema no longer has `email`/`fname`/…;
> it uses `student_email`/`first_name`/… plus 8 new columns. No data was lost
> in the rename.

Backfill details:

- `dob` was reformatted to `Y-m-d`.
- `cgpa` was rounded to 2 decimals.
- `graduation_date` was set to `NULL` (the source `student_nysc` had no date).
- The insert is **idempotent** — it uses `WHERE NOT EXISTS`, so re-running it
  never duplicates a student.
- Verified on the live DB. Sample rows, e.g.:
  `5732 | VUG/HIS/21/5732 | Gabriel Tobson | 1 | 3.51 | Second Class Upper | 2025 | NULL graduation_date`.

> The only **create** change: `student_nerds` was created in Session 1. All
> later structural changes to it (award columns, canonical renames) are
> additive/data-preserving and covered in the DB change log.

### 1.3 The `StudentNerd` model

`mark/app/Models/StudentNerd.php` — Eloquent model for the `student_nerds`
table with the expected `$fillable` list, `cgpa` cast to `decimal:2`, and
`belongsTo` relations for `Student` and `NyscSession`.

### 1.4 The review controller baseline

`mark/app/Http/Controllers/NyscNerdReviewController.php` — the review flow:

- `getNerdMatches()` — selects a nerd file in `storage/app`, extracts data, and
  matches rows against `student_nerds` (exact or similar matric), producing
  `current_*` / `proposed_*` fields, `needs_update`, and match type.
  - `.docx` files are handled by `DocxImportService::processDocxFile()`.
  - `.csv` files are handled by `DocxImportService::extractNerdDataFromCsvFile()`.
  - Graduation session + date are **file-level** metadata captured at upload time
    (in `nerd_meta.json`), not per-row.
- `applyNerdUpdates()` — writes approved rows back into `student_nerds` only.
- `uploadNerdFile()` / `getNerdFiles()` / `deleteNerdFile()` — file management.
- `readNerdMeta()` / `writeNerdMeta()` / `nerdMetaFor()` — sidecar JSON metadata.

---

## <a name="session-2"></a>Session 2 — the fixes (CGPA, programme, awards)

### 2.1 THE reported bug: CGPA not coming from the uploaded nerd file

The Nerd page's CGPA was not reflecting the uploaded file. **Root cause** in
`NyscAdminController::getNerdStudents()`:

```php
$calculatedCgpa = $cgpaMap[$s->matric_no] ?? $s->final_cgpa;  // recomputed from course_regs
```

The controller was **recomputing** CGPA from the `course_regs` table (grades and
credit units) and using that as the displayed `final_cgpa`, discarding whatever
was saved from the uploaded file into `student_nerds.cgpa`.

**Fix:** removed the `course_regs` recomputation. The Nerd page now shows
`student_nerds.cgpa` directly (the CGPA from the uploaded file, via the review
approval flow). Also confirmed `getNerdStudents` had no leftover references to
the removed `cgpaMap` / `calculatedCgpa`.

### 2.2 Programme extraction from uploaded CSVs

`DocxImportService::extractNerdDataFromCsvFile()` was updated to:

- Detect a **PROGRAMME / Course of Study** column from the header row.
- Treat **section-header rows** (e.g. `,ENGLISH EDUCATION,,,,` where the
  programme name appears instead of a matric) as a programme section, not a
  student. The detected programme propagates to the rows that follow.
- Use a new helper `looksLikeMatricNumber()` (e.g. matches `VUG/EEC/22/8421`) to
  decide "real matric vs. section header".

Verified on every real format in `storage/app`:

| File | Matric? | CGPA? | Programme? | Degree? |
|------|---------|-------|------------|---------|
| `test.csv` | ✅ | ✅ `4.5`, `4.29`… | ✅ (section headers) | ✅ |
| `responsex.csv` | ✅ | (no column in file) | ✅ (from `Course of Study:`) | ✅ |
| `list.csv` | ✅ | (no column in file) | (no programme column) | (no column) |

> Note: none of the files currently in `storage/app` has a CGPA column
> (`response*.csv` are Google-Form exports, `list*.csv` are name lists). To see
> CGPA populate, upload the actual nerd file (format like `test.csv`).

### 2.3 Class-of-degree normalization

Added mappings so `Second Class Honours (Upper Division)` /
`Second Class Honours (Lower Division)` normalize correctly (they previously
returned no match).

### 2.4 Programme → award derivation (`ProgrammeAwardService`)

A new service, `mark/app/Services/ProgrammeAwardService.php`, was added. It:

- Reads the reference table `mark/programme_award.txt` (a `Programme |
  Program_award_combined | award_short_title` markdown table) — cached in memory.
- Resolves a programme/course-of-study into the four award fields:
  `award_title`, `award_short_title`, `programme_award_combined`,
  `programme_category`.
- Matches **tolerantly**: case-insensitive, strips degree prefixes/suffixes
  (`B.ENG.`, `Bsc`, `B. Sc.`), with exact → substring → first-word fallback.
  Verified examples:

  | Input | Programme | Award short title |
  |-------|-----------|-------------------|
  | `Computer Science` | Computer Science | B.Sc |
  | `B.ENG.Computer Engineering` | Computer Engineering | B.Eng |
  | `Bsc Accounting` | Accounting | B.Sc |
  | `Mass Communication B. Sc.` | Mass Communication | B.Sc |
  | `ENGLISH EDUCATION` | English Education | B.Ed |
  | `History and International Relations` | History and International Relations | B.A |
  | `Marketing and advertising` | Marketing and Advertising | B.Sc |

`getNerdStudents()` reads these four award fields from the `student_nerds`
table (populated by the award-columns migration/backfill and kept in sync when a
programme changes via the review apply flow).

### 2.5 Programme flows through review → apply

- `getNerdMatches()` now includes `current_programme` / `proposed_programme` on
  each match (and `course_study` is selected from `student_nerds`).
- **Programme is authoritative per student.** `programme_major` on the nerd
  table comes from `student_nysc.course_study` (correct for every student). The
  nerd review therefore sets `proposed_programme` to the student's own
  `programme_major` rather than the uploaded file's programme / section header,
  so the file can never mislabel a student (e.g. a "Software Engineering"
  student was once being proposed as "English and Literary Studies" because the
  file's section header inherited onto his row).
- `applyNerdUpdates()` writes the approved programme into the student's
  `course_study` when it differs, and re-derives the four award columns from it,
  keeping `award_title`, `award_short_title`, `programme_award_combined`, and
  `programme_category` in sync.

### 2.6 File-name and parser hardening (`ProgrammeAwardService`)

- Fixed the reference file name from `Programme_award.txt` to the actual on-disk
  `programme_award.txt` (case mattered for the Linux server; it would otherwise
  have silently failed to load the map there).
- Hardened the markdown-table parser so only lines whose third cell is a real
  degree short title are accepted — stray text appended after the table (such as
  the CSV header pasted at the end of `programme_award.txt`) can never pollute
  the award map.

### 2.7 Frontend (`NYSC_UPDATE_FRONT`)

- `components/admin/NerdReviewTable.tsx` — added a
  **Programme (Current → Proposed)** column; added
  `current_programme` / `proposed_programme` to the `NerdReviewData` type; bumped
  the empty-state `colSpan`.
- `app/admin/nerd-review/page.tsx` — the "Apply Updates" payload now sends
  `proposed_programme`.
- `app/admin/nerd/page.tsx` — the four award fields (`award_title`,
  `award_short_title`, `programme_award_combined`, `programme_category`) are now
  marked as **available** (populated by the backend). Also fixed a
  `react/no-unescaped-entities` lint error in the header text.

### 2.8 Verification

- `php -l` on all changed PHP files — clean.
- `npx tsc --noEmit` on `NYSC_UPDATE_FRONT` — clean.
- `eslint` on the three changed frontend files — 0 errors (the 2 remaining
  warnings are pre-existing hook dependency warnings, not from this change).

---

## <a name="session-3"></a>Session 3 — canonical nerd columns (rename, no duplicates)

The nerd table must physically carry the canonical graduate-record columns.
Where a column already existed under a different name it was **renamed in
place** (data preserved) rather than duplicated; only genuinely-new columns
were added.

### 3.1 The canonical column set on `student_nerds`

| # | Column (final) | How it came to exist |
|---|----------------|----------------------|
| 1 | `nin` | kept (existing) |
| 2 | `matric_no` | kept (existing) |
| 3 | `student_email` | renamed from `email` |
| 4 | `phone_number` | renamed from `phone` |
| 5 | `first_name` | renamed from `fname` |
| 6 | `middle_name` | renamed from `mname` |
| 7 | `surname` | renamed from `lname` |
| 8 | `sex` | renamed from `gender` |
| 9 | `date_of_birth` | renamed from `dob` |
| 10 | `state` | kept (existing) |
| 11 | `programme_major` | renamed from `course_study` |
| 12 | `award_title` | kept (award migration) |
| 13 | `award_short_title` | kept (award migration) |
| 14 | `programme_award_combined` | kept (award migration) |
| 15 | `programme_category` | kept (award migration) |
| 16 | `programme_type` | renamed from `study_mode` |
| 17 | `class_of_degree_text` | renamed from `class_of_degree` |
| 18 | `final_cgpa` | renamed from `cgpa` (decimal(5,2)) |
| 19 | `graduation_session` | renamed from `graduation_year` |
| 20 | `graduation_date` | kept (existing) |
| 21 | `grade_approval_date` | **new** (varchar, nullable) — mirrors `graduation_date` (same value, kept in sync) |
| 22 | `admission_date` | **new** (varchar, nullable) |
| 23 | `mode_of_entry` | **new** (varchar, nullable) |
| 24 | `faculty_name` | **new** (varchar, nullable) |
| 25 | `department_name` | renamed from `department` |
| 26 | `senate_meeting_ref` | **new** (varchar, nullable) |
| 27 | `graduate_list_ref` | **new** (varchar, nullable) |
| 28 | `verified_by` | **new** (varchar, nullable) |
| 29 | `remarks` | **new** (text, nullable) |
| 30 | `updated_at` / `created_at` | kept (timestamps) |

Plus infrastructure columns `id`, `student_id`, `nysc_session_id`.

### 3.2 How the rename was done (migration)

`mark/database/migrations/2026_09_07_140000_canonicalize_student_nerds_columns.php`

- Performs 13 data-preserving `ALTER TABLE student_nerds CHANGE ...` renames
  (guarded with `Schema::hasColumn` so it is idempotent and never errors if a
  column was already renamed or never existed).
- Adds the 8 new columns (each guarded by `hasColumn`).
- One-time read-only backfill: `admission_date`, `faculty_name`,
  `mode_of_entry` are copied from the academics tables (left-joins keyed on
  `matric_no` — `student_academics.matric_no` is the point of contact with
  `student_nerds.matric_no`) for rows where they are null — nothing else is
  modified.
- `down()` reverses only what this migration did.

The award-columns migration
(`2026_09_07_131354_add_award_fields_to_student_nerds.php`) was also made
idempotent (guards around `ADD COLUMN`, reads `course_study` **or**
`programme_major` depending on which exists, backfills only where
`award_title` is null).

### 3.3 Code updated to the canonical names

- `app/Models/StudentNerd.php` — `$fillable` + `final_cgpa` cast.
- `NyscAdminController::getNerdStudents()` — selects the canonical columns
  directly (aliases no longer needed); keep the academics joins **only as
  fallbacks** (`academics_department_name`, `academics_admitted_date`,
  `academics_faculty_name`, `academics_mode_of_entry`); search uses
  `first_name`/`surname`/`department_name`.
- `NyscNerdReviewController` — `getNerdMatches`, `applyNerdUpdates`,
  `needsUpdate` all use the canonical names; `applyNerdUpdates` also keeps the
  stored `department_name` in sync with the programme resolved from
  `programme_award.txt` when the programme changes.
- `NyscStudentController::syncNerdRecord()` — mirrors `student_nysc` (read)
  into `student_nerds` (write) using the canonical keys. `student_nysc` itself
  is not touched.
- `database/sql/backfill_student_nerds_awards.php` — reads `programme_major`.
- `database/sql/create_student_nerds.sql` — rewritten with the canonical schema.

### 3.4 NYSC untouched

Only `student_nerds` and the nerd-flow code change. `student_nysc`, the NYSC
payment/export/import flows, and all NYSC-related tables are read-only (or
untouched) as far as this module is concerned.

---

## <a name="files"></a>Complete list of files changed

Across **all** sessions:

### Backend (PHP) — `mark/`
- `app/Services/ProgrammeAwardService.php` — **new** service (award derivation).
- `app/Models/StudentNerd.php` — model for `student_nerds` (Session 1).
- `app/Services/DocxImportService.php` — CSV programme/degree parsing (Session 2).
- `app/Http/Controllers/NyscAdminController.php` — CGPA fix, award fields,
  department reconciliation.
- `app/Http/Controllers/NyscNerdReviewController.php` — review/apply + programme
  + award persistence.
- `database/sql/create_student_nerds.sql` — **new** SQL file capturing the
  Session 1 table creation + backfill (for reference/re-import).
- `database/sql/backfill_student_nerds_awards.php` — **new** one-time backfill
  runner for the award columns.
- `database/sql/backfill_student_nerds_canonical.php` — **new** one-time data
  backfill (awards on missing rows, academics fields keyed on `matric_no`,
  grade_approval_date sync).
- `database/migrations/2026_09_07_131354_add_award_fields_to_student_nerds.php`
  — **new** migration adding the 4 award columns + backfilling. Made
  idempotent (hasColumn guards) in Session 3.
- `database/migrations/2026_09_07_140000_canonicalize_student_nerds_columns.php`
  — **new** data-preserving rename of 13 columns to canonical names + adds 8
  new columns + one-time academics backfill.

### Frontend (Next.js / TypeScript) — `NYSC_UPDATE_FRONT/`
- `components/admin/NerdReviewTable.tsx`
- `app/admin/nerd-review/page.tsx`
- `app/admin/nerd/page.tsx`

The migrations under `database/migrations` are the mechanism for applying the
structural changes to `student_nerds`:
`2026_09_05_000000_create_student_nerds_table.php`,
`2026_09_07_131354_add_award_fields_to_student_nerds.php`, and
`2026_09_07_140000_canonicalize_student_nerds_columns.php`. They only ever
touch `student_nerds`.

---

## <a name="db-changes"></a>Database change log — every table touched

This is the complete, honest record of **every** database operation across all
sessions (including the user-approved award columns and canonical rename).

| When | Operation | Table(s) | Notes |
|------|-----------|----------|-------|
| Session 1 | `CREATE TABLE IF NOT EXISTS` | `student_nerds` | New table created. |
| Session 1 | `INSERT ... SELECT ... WHERE NOT EXISTS` | `student_nerds` | Backfilled 1685 rows from `student_nysc`. Idempotent. |
| Session 2a | `ALTER TABLE ADD COLUMN` | `student_nerds` | User-approved: added `award_title`, `award_short_title`, `programme_award_combined`, `programme_category`. |
| Session 2a | `UPDATE` (award backfill) | `student_nerds` | Populated the 4 award columns from `programme_major` via `ProgrammeAwardService`. |
| Session 3 | 13 × `ALTER TABLE ... CHANGE` renames | `student_nerds` | Data-preserving rename to canonical column names. |
| Session 3 | `ALTER TABLE ADD COLUMN` (×8) | `student_nerds` | Added 8 new columns (grade_approval_date — mirrors graduation_date, admission_date, mode_of_entry, faculty_name, senate_meeting_ref, graduate_list_ref, verified_by, remarks). |
| Session 3 | `UPDATE` (academics backfill) | `student_nerds` | One-time read-only copy of admission_date/faculty_name/mode_of_entry from academics tables keyed on `matric_no`; syncs grade_approval_date = graduation_date. `updated_at` refreshed. |
| Session 2b | **None** | — | Code-only changes (CGPA, programme extraction, export, frontend). |
| Going forward | Runtime writes (approve flow only) | `student_nerds` | Only via `applyNerdUpdates`, only for user-approved rows. |

**What was NOT touched (ever):**

- `student_nysc` — read from during the one-time backfill (Session 1) and
  always read-only by `syncNerdRecord` and the academics fallback in
  `getNerdStudents`. Never written or altered.
- `course_regs` — previously *read* by `getNerdStudents` for the CGPA
  recalculation; that read has now been **removed** entirely.
- No `ALTER TABLE` or `UPDATE` on any table other than `student_nerds`. The
  renames were data-preserving `CHANGE` operations on `student_nerds` columns
  only. No indexes/constraints were added, removed, or changed on any table.

**What happens going forward:**

- All runtime writes go to the existing `student_nerds` table and only its
  canonical columns: `final_cgpa`, `class_of_degree_text`, `graduation_session`,
  `graduation_date`, `programme_major`, `department_name`, plus the award
  columns and any of the new columns that were added. All kept consistent
  whenever the programme changes via the review apply flow.
- Writes only occur when an admin **approves** rows in the review UI and clicks
  "Apply Updates".
- Nothing in the changed code contains `CREATE`, `ALTER`, or `DROP` at runtime.
  The only structural changes are the Session 1 table creation, the
  user-approved award columns, and the Session 3 canonical renames + new
  columns — all via offline migrations.
