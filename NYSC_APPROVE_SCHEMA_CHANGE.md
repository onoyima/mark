# NYSC Admin Approve/Reject — schema change status

## Status: REVERTED — no schema change remains

A temporary schema change was applied to the local `exeat2` database on
2026-08-03 15:51 to try to support the admin approve/reject feature. It has
since been **fully reverted** at your request. The database is back to exactly
its original structure. No columns were added or removed permanently, and the
`status` enum is back to its original values.

## What was temporarily applied (for the record)

1. `status` enum expanded from `('pending','paid','expired')` to also allow
   `'approved'` and `'rejected'`.
2. Three nullable columns added: `review_notes`, `reviewed_by`, `reviewed_at`.

## What was done to revert it

```sql
ALTER TABLE nysc_temp_submissions
  DROP COLUMN reviewed_at,
  DROP COLUMN reviewed_by,
  DROP COLUMN review_notes;

ALTER TABLE nysc_temp_submissions
  MODIFY status ENUM('pending','paid','expired') NOT NULL DEFAULT 'pending';
```

Verified afterwards with `SHOW COLUMNS`:

```
status  enum('pending','paid','expired')  NO  (default 'pending')
```

No `review_*` columns remain.

## How the feature works now (no schema change)

The approve/reject feature works entirely with the existing tables and columns:

- **Approve** (`PUT /api/nysc/admin/submissions/{id}/status` with
  `status=approved`): the pending submission's data is written into the master
  `student_nysc` list via `updateOrCreate` (`is_submitted=true`, `is_paid` set
  from whether the student has a successful payment, `nysc_session_id`
  preserved). The resolved pending row is then removed from
  `nysc_temp_submissions`. This mirrors the same `updateOrCreate` already used
  by the Paystack payment webhook — the payment flow itself is untouched.
- **Reject** (`status=rejected`): the pending row is removed from
  `nysc_temp_submissions`. The student can re-submit later.

Because the `status` enum has no `approved`/`rejected` values, a resolved
submission is removed from the queue rather than given a non-existent status.
This is consistent with existing behaviour: students already get their old
pending submissions deleted when they re-submit (`NyscStudentController.php`).

## The flow (student → pending queue → admin resolve)

### Student → Pending Queue (unchanged)
1. Student submits the Confirm page → a row is created in
   `nysc_temp_submissions` with `status='pending'` and a `submission_token`.
2. It sits in the admin "Pending" queue until either the student pays
   (auto-flow) or an admin resolves it.

### Admin Approve
1. Admin clicks the checkmark (list page) or **Approve Submission** (detail
   page).
2. Frontend sends `PUT /api/nysc/admin/submissions/{id}/status` with
   `{status:'approved'}`; the buttons are disabled while processing.
3. Backend (`updateSubmissionStatus`):
   - Checks whether the student has a successful payment → sets `is_paid`
     accordingly.
   - `StudentNysc::updateOrCreate` copies the submission's data into the master
     **`student_nysc`** list (`is_submitted=true`, `nysc_session_id`
     preserved).
   - **Deletes the pending temp row** (the enum cannot store `approved`, so the
     resolved row leaves the queue).
4. Frontend: toast "Submission approved and added to the data list", the row is
   removed from the list / the admin is redirected back.
5. The student portal now shows "submitted" because
   `student_nysc.is_submitted=true`.

### Admin Reject
1. Admin clicks the X.
2. Same endpoint, `{status:'rejected'}`.
3. Backend: **deletes the pending temp row** — nothing is written to
   `student_nysc`.
4. Frontend: toast "Submission rejected", the row is removed from the queue.
5. The student can re-confirm and re-submit later.

### Payment path (untouched)
Student pays → Paystack webhook finds the *pending* temp row by token →
auto-creates/updates the `student_nysc` row (`is_paid=true`) and marks the temp
row `paid`.

> **Known edge case:** if an admin approves an **unpaid** submission and the
> student pays *afterwards*, the webhook finds no pending temp row, so it only
> marks the payment successful — the master row's `is_paid` stays `false`
> unless the admin re-verifies. Fixing that would require touching the webhook.

## Files involved

- `app/Http/Controllers/NyscAdminController.php` — `updateSubmissionStatus()`
  now validates `approved`/`rejected`, moves approved data into `student_nysc`
  (existing columns only), and removes the resolved pending row. The
  submissions export no longer references the non-existent `review_*` columns.
- `NYSC_UPDATE_FRONT/app/admin/submissions/page.tsx` — after approve/reject the
  row is removed from the list (backend deletes it).
- `NYSC_UPDATE_FRONT/app/admin/submissions/[id]/page.tsx` — after approve/reject
  the admin is redirected back to the submissions list.
