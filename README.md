## Equalify + UIC Utilities

WordPress plugin for generating a public CSV of site URLs for Equalify ingestion.

### Current behavior (v3.0.0)

- Runs scans in the background with WP-Cron.
- Schedules a weekly scan automatically.
- Supports manual runs from the plugin admin page.
- Scans public content for:
  - PDF links
  - Public page/post URLs
- Writes one stable CSV file per site and overwrites it after each completed scan.
- Shows status in wp-admin, including:
  - current state (`Queued`, `Running`, `Complete`, `Failed`)
  - progress (`processed / total`, remaining count, stage message)
  - last started/completed timestamps
  - next scheduled run time
  - latest CSV row count
  - latest error message (if any)

### CSV output

- The CSV has two columns: `url`, `type`
- `type` values:
  - `pdf`
  - `html`
- CSV URL is shown in the plugin settings page once the file exists.
