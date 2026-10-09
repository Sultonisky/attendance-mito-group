# HRIS outsource person sync

HRIS is the master for outsource persons and their `DM{YYYY}NNNN` IDs. The
Person List can no longer create persons (`POST /api/v1/outsource-persons` was
removed); administrators only manage cabang, pins, login PIN, and status.

## Configuration

```dotenv
HRIS_API_BASE_URL=https://<HRIS-host>
HRIS_OUTSOURCE_SYNC_API_TOKEN=<same secret as HRIS_OUTSOURCE_SYNC_API_TOKEN in HRIS>
ATTENDANCE_OUTSOURCE_PUSH_API_TOKEN=<same secret as ATTENDANCE_OUTSOURCE_PUSH_API_TOKEN in HRIS>
HRIS_API_TIMEOUT=8
```

Use a separate long random secret for each token, keep them server-side, and
use HTTPS in production. Never expose them to Vue/Vite environment variables.

## Receiving new outsource persons from HRIS

When HRIS creates an outsource person, it calls this server-to-server endpoint:

```http
POST /api/v1/integrations/hris/outsource-persons
Authorization: Bearer <ATTENDANCE_OUTSOURCE_PUSH_API_TOKEN>
Content-Type: application/json

{"people": [{"outsource_id": "DM20260135", "full_name": "Employee Name"}], "dry_run": false}
```

It accepts up to 100 people and only `outsource_id` and `full_name`. A missing
ID is created as `inactive`, without cabang or pin assignments, with the
default login PIN `123456`, and audited as `outsource_person.created_from_hris`.
An administrator assigns a cabang and activates the person in the Person List.
Existing records, including soft-deleted ones, are never changed: the same
name returns `skipped`; a different name or a deleted record returns
`conflict`. Invalid payloads receive `422`, a wrong token `401`, and an unset
`ATTENDANCE_OUTSOURCE_PUSH_API_TOKEN` fails closed with `503`. Requests are
limited to 60 per minute. HRIS retries missed pushes with
`php artisan mito:outsource-push-attendance --execute`.

## One-time reconciliation of existing Person List IDs to HRIS

The command below pushes existing Person List IDs and names to HRIS using
`HRIS_OUTSOURCE_SYNC_API_TOKEN`. HRIS creates the master record only when that
ID is missing. If the ID already exists with the same normalized name, HRIS
skips it without changing any HRIS-managed personalia. If the same ID has a
different name, the sync reports a conflict; it does not change HRIS. Editing a
Person List record in Attendance does not update HRIS.

Preview all non-deleted Person List records without writing:

```sh
php artisan hris:sync-outsource-persons
```

Review the `Would create` and `Skipped` totals, then explicitly create only
missing HRIS IDs:

```sh
php artisan hris:sync-outsource-persons --execute
```

The command is safe to retry. It processes batches of at most 100 people, so
existing production records are skipped and left untouched. Resolve every
reported conflict manually before the Attendance record is used for attendance
or payroll lookups.
