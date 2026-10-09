# HRIS outsource payroll in Attendance

The outsource PWA displays payslip and incentive history from HRIS. HRIS
remains the source of truth; Attendance fetches data on demand and does not
copy payroll records into its database.

## Configuration

Set the HRIS application origin and the dedicated payroll API token in the
Attendance backend environment:

```dotenv
HRIS_API_BASE_URL=https://<HRIS-host>
HRIS_OUTSOURCE_PAYROLL_API_TOKEN=<same secret as HRIS_OUTSOURCE_PAYROLL_API_TOKEN in HRIS>
HRIS_API_TIMEOUT=8
```

The shared secret is used only by the Attendance backend. Never expose it to
Vue/Vite environment variables or browser requests. Production calls require
HTTPS.

## Attendance API

The browser uses the current outsource attendance session; no outsource ID is
accepted from the client:

```http
GET /api/v1/outsource/payroll/payslips
GET /api/v1/outsource/payroll/incentives
```

An optional `?period=YYYY-MM` filters to one payroll period. Without it, all
available periods are returned by HRIS. Attendance obtains the signed-in
outsource's `outsource_code` from its server-side session and uses it as the
HRIS Outsource ID. HRIS access failures are returned as gateway errors; no
local payroll fallback is used.

Configure the same dedicated token in HRIS as `HRIS_OUTSOURCE_PAYROLL_API_TOKEN`
and make sure the HRIS payroll API has been deployed before enabling the
Attendance UI.
