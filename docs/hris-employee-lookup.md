# HRIS employee lookup from Attendance

Attendance performs a server-to-server lookup when an authorized Attendance
user requests an HRIS employee. HRIS remains the source of truth; Attendance
does not copy or persist the returned employee data.

## Attendance API

```http
GET /api/v1/employees/hris?page=1&per_page=25&search=treasury&work_location=Jakarta&work_area=Head%20Office
GET /api/v1/employees/hris/by-nik/{16-digit-NIK}
GET /api/v1/employees/hris/{16-digit-NIK}/work-locations
PUT /api/v1/employees/hris/{16-digit-NIK}/work-locations
GET /api/v1/employee-work-locations/options
POST /api/v1/employee-work-locations/options
GET /api/v1/employee-work-locations/{id}/employees
Authorization: Bearer <Attendance Sanctum token>
Accept: application/json
```

The employee list and NIK lookup require an authenticated Attendance user with
the `employees.view` permission. The work-location options endpoint requires
`employee_work_location.view`. The person list fetches paginated HRIS data and
searches by employee ID, name, NIK, job position, division, department, work
location, or work area on the HRIS server. Optional `work_location` and
`work_area` filters are exact (case-insensitive) and apply before pagination.
The list response includes distinct work-location and work-area options from
the full HRIS employee source for the filter dropdowns. The list and direct NIK
lookup return only the allowlisted attendance fields. Attendance calls HRIS
from the backend using its own configured integration token. The token must
never be sent to Vue.

The work-location options endpoint requires `employee_work_location.view` and
returns HRIS work-location/work-area values merged with custom options saved in
Attendance. Creating a custom option requires `employee_work_location.create`;
it is stored locally and does not modify HRIS. When creating an Attendance work
location, users select one of these values and manually enter the Attendance
location name, address, coordinates, and radius.

The employee-work-location employees endpoint requires
`employee_work_location.view` and returns active employees assigned to the
specified Attendance work location, with employee code, NIK, and name for the
assignment details modal.

The read-only Person List uses the HRIS employee ID, NIK, name, job position,
work location, and work area. Attendance-specific geofence assignments are
managed separately by NIK through the two `work-locations` endpoints and
require `employee_work_location.view` or `employee_work_location.update`.
Saving an assignment verifies the NIK with HRIS and links it to an existing
Attendance employee whose `employee_code` matches the HRIS employee ID (or
whose NIK is already linked). Only that NIK mapping and the work-location
assignment are stored locally; HRIS profile fields are not copied and no
Attendance employee record is created. If no matching local Attendance
employee exists, the API returns `409` and does not create one.

The Employee Attendance report's Add Record form searches this HRIS person list,
then loads only the selected person's active Attendance work-location
assignments by NIK. The create request resolves the HRIS employee ID/NIK to an
existing local Attendance employee and verifies that the chosen location is
assigned to that employee. The selected location is stored on the manual
check-in/out events; manual records do not invent GPS coordinates. Missing
employee mappings or mismatched location assignments are rejected without
creating an attendance record.

## Server configuration

Configure the following values in the Attendance backend environment:

```dotenv
HRIS_API_BASE_URL=https://<HRIS-host>
HRIS_API_TOKEN=<same secret as HRIS_EMPLOYEE_API_TOKEN>
HRIS_API_TIMEOUT=8
```

The base URL is the HRIS application origin, without `/api/v1`. Set
`HRIS_EMPLOYEE_API_TOKEN` in the HRIS environment to the same long, random
secret. Both services must be reachable over HTTPS in production.

## Response and failure behavior

On success, Attendance returns the allowlisted HRIS employee fields under
`data`. A missing employee returns `404`; invalid NIK is rejected before an
outbound request; and incomplete configuration, unavailable HRIS, or an
unexpected upstream response returns `502`. The endpoints are rate limited
to 30 requests per minute.

Employee lookup and listing do not write to Attendance's `employees` table or
modify attendance records. Saving a geofence assignment may set the verified
NIK on an already-existing local Attendance employee as its identity mapping.
HRIS response availability is required for each lookup; there is intentionally
no stale cache or fallback to local employee records.
