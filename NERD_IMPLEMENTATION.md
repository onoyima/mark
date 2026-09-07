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
4. [Complete list of files changed](#files)
5. [Database change log — every table touched](#db-changes)

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

### 1.2 The `student_nerds` table — created + backfilled (THE one DB change)

A new table `student_nerds` was created on the live database and backfilled with
**1685 rows** copied from `student_nysc`. This was done via a temp PHP script
(`%TEMP%\opencode\nerd_import.php`) and was also captured for re-usability in:

- `mark/database/sql/create_student_nerds.sql`

The table columns (note: all columns exist, only `created_at`/`updated_at` are
non-nullable in practice):

`student_id`, `nysc_session_id`, `matric_no`, `nin`, `email`, `phone`, `fname`,
`mname`, `lname`, `gender`, `dob`, `state`, `course_study`, `study_mode`,
`department`, `cgpa decimal(5,2)`, `class_of_degree`, `graduation_year`,
`graduation_date`, `created_at`, `updated_at`.

Backfill details:

- `dob` was reformatted to `Y-m-d`.
- `cgpa` was rounded to 2 decimals.
- `graduation_date` was set to `NULL` (the source `student_nysc` had no date).
- The insert is **idempotent** — it uses `WHERE NOT EXISTS`, so re-running it
  never duplicates a student.
- Verified on the live DB. Sample rows, e.g.:
  `5732 | VUG/HIS/21/5732 | Gabriel Tobson | 1 | 3.51 | Second Class Upper | 2025 | NULL graduation_date`.

> This is the **only** table change in the whole effort, and it happened in
> Session 1.

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

`getNerdStudents()` now populates these four award fields from each student's
programme (previously hardcoded `null`).

### 2.5 Programme flows through review → apply

- `getNerdMatches()` now includes `current_programme` / `proposed_programme` on
  each match (and `course_study` is selected from `student_nerds`).
- `applyNerdUpdates()` now writes the approved programme into the student's
  `course_study` when it differs (case-insensitive compare), in the same approve-
  only flow as the other fields.

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

## <a name="files"></a>Complete list of files changed

Across **both** sessions:

### Backend (PHP) — `mark/`
- `app/Services/ProgrammeAwardService.php` — **new** service (award derivation).
- `app/Models/StudentNerd.php` — model for `student_nerds` (Session 1).
- `app/Services/DocxImportService.php` — CSV programme/degree parsing (Session 2).
- `app/Http/Controllers/NyscAdminController.php` — CGPA fix + award fields.
- `app/Http/Controllers/NyscNerdReviewController.php` — review/apply + programme.
- `database/sql/create_student_nerds.sql` — **new** SQL file capturing the
  Session 1 table creation + backfill (for reference/re-import).

### Frontend (Next.js / TypeScript) — `NYSC_UPDATE_FRONT/`
- `components/admin/NerdReviewTable.tsx`
- `app/admin/nerd-review/page.tsx`
- `app/admin/nerd/page.tsx`

No migration files were created or run (Laravel migrations under
`database/migrations` were not touched in either session).

---

## <a name="db-changes"></a>Database change log — every table touched

This is the complete, honest record of **every** database operation across both
sessions.

| When | Operation | Table(s) | Notes |
|------|-----------|----------|-------|
| Session 1 | `CREATE TABLE IF NOT EXISTS` | `student_nerds` | New table created. |
| Session 1 | `INSERT ... SELECT ... WHERE NOT EXISTS` | `student_nerds` | Backfilled 1685 rows from `student_nysc`. Idempotent. |
| Session 2 | **None** | — | No DDL, no DML executed. |
| Going forward | Runtime writes (approve flow only) | `student_nerds` | Only via `applyNerdUpdates`, only for user-approved rows. |

**What was NOT touched (ever):**

- `student_nysc` — read from during the one-time backfill, **never written or
  altered**.
- `course_regs` — previously *read* by `getNerdStudents` for the CGPA
  recalculation; that read has now been **removed** entirely.
- No `ALTER TABLE`, no indexes/constraints added, no migrations added or run.
- No column was added, dropped, or renamed in any table.

**What happens going forward:**

- All runtime writes go to the existing `student_nerds` table and only its
  **existing** columns: `cgpa`, `class_of_degree`, `graduation_year`,
  `graduation_date`, `course_study`.
- Writes only occur when an admin **approves** rows in the review UI and clicks
  "Apply Updates".
- Nothing in the changed code contains `CREATE`, `ALTER`, or `DROP`. No schema
  change is made at runtime, and no table other than `student_nerds` is written
  to.

In short: the **only** structural change in the entire effort was creating the
`student_nerds` table (plus its one-time backfill) in Session 1. That table is
the dedicated, sandboxed home for nerd data; the primary `student_nysc` table
and all others are left untouched.
