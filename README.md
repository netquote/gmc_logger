# gmc_logger

`gmc_log.php` is a single-file GMC Geiger Counter logger and viewer.
It accepts incoming device readings via HTTP GET, stores them in SQLite, and provides a browser UI with filtering, charting, and export.

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

- Logs incoming device readings to `gmc_logs/gmc_readings.sqlite`
- Stores timestamps in **UTC** (`Y-m-d H:i:s`)
- Captures device ID, CPM, ACPM, µSv/h, dose, raw query payload, and client IP
- Shows a web dashboard for recent readings (latest first)
- Supports date-range filtering
- Supports export to CSV and XLSX

## Database details

SQLite DB file:

- `gmc_logs/gmc_readings.sqlite`

Table created automatically:

- `readings(id, timestamp, device_id, cpm, acpm, usv, dose, raw_data, client_ip)`

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

- Last 100 rows shown (`MAX_VIEW_ROWS = 100`)
- Built-in chart buckets: minute, hourly, daily, weekly, monthly
- Date filters via:
	- `f_timestamp_from=YYYY-MM-DD`
	- `f_timestamp_to=YYYY-MM-DD`
- Theme selection via `theme`:
	- `light`, `dark`, `forest`, `ocean`, `sunset`, `lavender`, `mono`
- Auto-refresh every 60 seconds

The viewer uses `gmc_log.css` for styling.

## Export behavior

Use query parameter:

- `export=csv` or `export=xlsx`

Exports respect active date filters.

Examples:

- CSV: `gmc_log.php?export=csv`
- XLSX: `gmc_log.php?export=xlsx`
- Filtered CSV: `gmc_log.php?f_timestamp_from=2026-02-01&f_timestamp_to=2026-02-20&export=csv`

## Quick usage examples

Sample device log request:

`gmc_log.php?AID=50389795&CPM=10&ACPM=7.9&uSV=0.20`

Then open:

`gmc_log.php`

to view data.

## Requirements

- PHP with PDO SQLite enabled
- Web server that can run PHP (Apache/Nginx+PHP-FPM/IIS+PHP)
- Write permission for `gmc_logs/`

## FTP deploy script

Use `ftp_update.bat` to upload selected files.

- Configure FTP credentials in `ftp_update.bat` (`FTP_HOST`, `FTP_USER`, `FTP_PASS`, `FTP_REMOTE_DIR`).
- Configure uploaded files in `FTP_FILES` (space-separated list).
- The batch script calls `ftp_update.py --files ...`.
- If a listed file does not exist, upload stops with error.