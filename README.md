# gmc_logger

`gmc_log.php` is a single-file GMC Geiger Counter logger and viewer.
It accepts incoming device readings via HTTP GET, stores them in SQLite, and provides a browser UI with charts and export.

Licensed under **AGPL-3.0-only**.

## Configure GMC Devices

1. Connect your GMC-500+ to WiFi.
2. Navigate to **Menu** -> **Server** -> **Website**.
3. Enter the URL for your server installation.
   
   Example:
   Website: `192.168.1.100`
   URL: `gmc_log.php`
   
   *(Replace `192.168.1.100` with your actual server IP or domain)*

4. Save and exit. The counter should begin sending readings.

## What `gmc_log.php` does

- Logs incoming device readings to `readings.sqlite`
- Stores timestamps in **UTC** (`Y-m-d H:i:s`)
- Captures device ID, CPM, ACPM, µSv/h, dose, raw query payload, and client IP
- Shows a web dashboard with a readings table (latest first)
- Displays interactive charts with day/week/month/year tabs
- Supports export to CSV and XLSX

## Database details

SQLite DB file:

- `readings.sqlite`

Table created automatically:

- `readings(id, timestamp, device_id, cpm, acpm, usv, dose)`

The script auto-creates the DB folder and table if missing.

## Logging endpoint behavior

The script treats a request as a **log write** when any of these query params exists (case-insensitive):

- `CPM`, `ID`, `AID`, or `GID`

Accepted input aliases:

- Device ID: `ID`, `id`, `AID`, `aid`, `GID`, `gid`
- CPM: `CPM`, `cpm`
- ACPM: `ACPM`, `acpm`
- uSv/h: `USV`, `uSV`, `uSv`, `usv`
- Dose: `dose`, `DOSE`

Defaults when omitted:

- `device_id = UNKNOWN`
- `cpm = 0`
- `acpm = 0`
- `usv = 0.0`
- `dose = 0`

On successful insert, response body is:

- `OK`

Optional device whitelist:

- If `whitelist.txt` exists in the same folder as `gmc_log.php`, only listed device IDs are accepted.
- One device ID per line.
- Empty lines are ignored.
- Lines starting with `#` are treated as comments.
- Device matching is case-insensitive.
- If a device is not in whitelist, the logger returns HTTP `403` with body `FORBIDDEN`.

Example `whitelist.txt`:

```txt
# Allowed devices
50389795
GMC-ALPHA-01
```

On unhandled server error:

- HTTP `500`
- response body `ERROR`

## Viewer behavior

Opening `gmc_log.php` without log params shows the dashboard.

Features:

- Last 50 rows shown (`MAX_VIEW_ROWS = 50`)
- Interactive chart with tabs for **Last Day**, **Last Week**, **Last Month**, **Last Year**
  - Displays averaged CPM and ACPM over grouped time intervals
- Theme selection via `theme` (default: `dark`):
	- `light`, `dark`, `forest`, `ocean`, `sunset`, `lavender`, `mono`
- Theme preference is saved in `localStorage`
- Auto-refresh every 60 seconds

The viewer uses `gmc_log.css` for styling and [Chart.js](https://www.chartjs.org/) for charts.

## Export behavior

Use query parameter:

- `export=csv` or `export=xlsx`

Exports include **all** stored readings (no row limit).

Examples:

- CSV: `gmc_log.php?export=csv`
- XLSX: `gmc_log.php?export=xlsx`

## Quick usage examples

Sample device log request:

`gmc_log.php?AID=50389795&CPM=10&ACPM=7.9&uSV=0.20`

Then open:

`gmc_log.php`

to view data.