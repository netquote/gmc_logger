<?php
// SPDX-License-Identifier: AGPL-3.0-only
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License version 3 (AGPLv3)
// as published by the Free Software Foundation.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU Affero General Public License version 3 for more details.

// filename: gmc_log.php
// GMC Geiger Counter Data Logger
declare(strict_types=1);

// --- Configuration ---
define('DB_FILE', 'gmc_logs/gmc_readings.sqlite');
define('WHITELIST_FILE', __DIR__ . '/whitelist.txt');
define('MAX_VIEW_ROWS', 100);
define('THEMES', [
    'light' => 'White',
    'dark' => 'Dark',
    'forest' => 'Forest',
    'ocean' => 'Ocean',
    'sunset' => 'Sunset',
    'lavender' => 'Lavender',
    'mono' => 'Monochrome',
]);
define('CHART_BUCKETS', ['minute', 'hourly', 'daily', 'weekly', 'monthly']);
// --------------------

function getDb(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dbDir = dirname(DB_FILE);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0777, true);
        }

        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS readings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp TEXT NOT NULL,
                device_id TEXT NOT NULL,
                cpm TEXT NOT NULL,
                acpm TEXT NOT NULL,
                usv TEXT NOT NULL,
                dose TEXT NOT NULL,
                raw_data TEXT NOT NULL,
                client_ip TEXT NOT NULL DEFAULT ""
            )'
        );

        $columns = $pdo->query('PRAGMA table_info(readings)')->fetchAll();
        $hasClientIp = false;
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'client_ip') {
                $hasClientIp = true;
                break;
            }
        }

        if (!$hasClientIp) {
            $pdo->exec('ALTER TABLE readings ADD COLUMN client_ip TEXT NOT NULL DEFAULT ""');
        }
    }

    return $pdo;
}

function hasLogParams(): bool {
    foreach (['CPM', 'cpm', 'ID', 'id', 'AID', 'aid', 'GID', 'gid'] as $key) {
        if (isset($_GET[$key])) {
            return true;
        }
    }

    return false;
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function getThemeFromRequest(): string {
    $theme = strtolower(trim((string)($_GET['theme'] ?? 'light')));
    return array_key_exists($theme, THEMES) ? $theme : 'light';
}

function getThemeOptions(): array {
    return THEMES;
}

function readParam(array $keys, string $default): string {
    foreach ($keys as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            return trim((string)$_GET[$key]);
        }
    }

    return $default;
}

function getClientIpFromRequest(): string {
    $candidates = [];

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($parts as $part) {
            $candidate = trim($part);
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }
    }

    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $candidates[] = trim((string)$_SERVER['HTTP_CLIENT_IP']);
    }

    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = trim((string)$_SERVER['REMOTE_ADDR']);
    }

    foreach ($candidates as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    return 'UNKNOWN';
}

function getWhitelistDevices(): ?array {
    if (!is_file(WHITELIST_FILE)) {
        return null;
    }

    if (!is_readable(WHITELIST_FILE)) {
        throw new RuntimeException('Whitelist file is not readable: ' . WHITELIST_FILE);
    }

    $lines = file(WHITELIST_FILE, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read whitelist file: ' . WHITELIST_FILE);
    }

    $allowed = [];
    foreach ($lines as $line) {
        $value = trim((string)$line);
        if ($value === '' || str_starts_with($value, '#')) {
            continue;
        }

        $allowed[strtoupper($value)] = true;
    }

    return $allowed;
}

function isDeviceAllowed(string $deviceId): bool {
    $allowed = getWhitelistDevices();
    if ($allowed === null) {
        return true;
    }

    return isset($allowed[strtoupper(trim($deviceId))]);
}

function getFiltersFromRequest(): array {
    return [
        'timestamp_from' => trim((string)($_GET['f_timestamp_from'] ?? '')),
        'timestamp_to' => trim((string)($_GET['f_timestamp_to'] ?? '')),
    ];
}

function normalizeDateInput(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if ($dt === false) {
        return '';
    }

    return $dt->format('Y-m-d');
}

function buildWhereClause(array $filters, array &$params): string {
    $conditions = [];

    $from = normalizeDateInput((string)($filters['timestamp_from'] ?? ''));
    if ($from !== '') {
        $conditions[] = 'timestamp >= :f_timestamp_from';
        $params[':f_timestamp_from'] = $from . ' 00:00:00';
    }

    $to = normalizeDateInput((string)($filters['timestamp_to'] ?? ''));
    if ($to !== '') {
        $conditions[] = 'timestamp <= :f_timestamp_to';
        $params[':f_timestamp_to'] = $to . ' 23:59:59';
    }

    if (empty($conditions)) {
        return '';
    }

    return ' WHERE ' . implode(' AND ', $conditions);
}

function fetchReadings(array $filters, ?int $limit = MAX_VIEW_ROWS): array {
    $db = getDb();
    $params = [];

    $sql = 'SELECT timestamp, device_id, cpm, acpm, usv, dose, raw_data FROM readings';
    $sql .= buildWhereClause($filters, $params);
    $sql .= ' ORDER BY id DESC';

    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit);
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fetchChartSeries(array $filters, string $bucket): array {
    $db = getDb();
    $params = [];
    $where = buildWhereClause($filters, $params);

    // Limit "minute" bucket to last 24 hours if no specific timeframe is set
    // to prevent fetching massive amounts of data by default.
    if ($bucket === 'minute' && empty($filters['timestamp_from']) && empty($filters['timestamp_to'])) {
        $yesterday = gmdate('Y-m-d H:i:s', time() - 86400);
        // buildWhereClause constructs WHERE ... AND ...
        // If it's empty, we start with WHERE. If not, we append AND.
        if ($where === '') {
            $where = ' WHERE timestamp >= :timestamp_limit';
        } else {
            $where .= ' AND timestamp >= :timestamp_limit';
        }
        $params[':timestamp_limit'] = $yesterday;
    }

    $groupExpr = [
        'minute' => "strftime('%Y-%m-%d %H:%M', timestamp)", // Group by minute
        'hourly' => "strftime('%Y-%m-%d %H:00', timestamp)", // Group by hour
        'daily' => "strftime('%Y-%m-%d', timestamp)",        // Group by day
        'weekly' => "strftime('%Y-W%W', timestamp)",         // Weekly
        'monthly' => "strftime('%Y-%m', timestamp)",         // Monthly
    ][$bucket] ?? "strftime('%Y-%m-%d', timestamp)";

    $sql = "SELECT
                {$groupExpr} AS period_key,
                AVG(CAST(cpm AS REAL)) AS avg_cpm,
                AVG(CAST(acpm AS REAL)) AS avg_acpm,
                MIN(timestamp) AS first_ts
            FROM readings";
    $sql .= $where;
    $sql .= ' GROUP BY period_key ORDER BY first_ts ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $labels = [];
    $cpm = [];
    $acpm = [];

    foreach ($rows as $row) {
        $labels[] = (string)($row['period_key'] ?? '');
        $cpm[] = round((float)($row['avg_cpm'] ?? 0), 2);
        $acpm[] = round((float)($row['avg_acpm'] ?? 0), 2);
    }

    return [
        'labels' => $labels,
        'cpm' => $cpm,
        'acpm' => $acpm,
    ];
}

function exportReadings(string $format, array $filters): void {
    $rows = fetchReadings($filters, null);
    $fileStamp = gmdate('Ymd_His');
    $headers = ['Timestamp', 'DeviceID', 'CPM', 'ACPM', 'uSv/h', 'Dose', 'RawData'];
    $records = array_map('formatExportRow', $rows);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="gmc_readings_' . $fileStamp . '.csv"');

        $out = fopen('php://output', 'w');
        // Add UTF-8 BOM for Excel compatibility
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($records as $record) {
            fputcsv($out, $record);
        }
        fclose($out);
        return;
    }

    if ($format === 'xlsx') {
        // Use .xlsx extension
        // Note: This outputs tab-separated values. Excel might warn about format mismatch if strictly checked,
        // but .xlsx is often requested by users.
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=utf-8');
        header('Content-Disposition: attachment; filename="gmc_readings_' . $fileStamp . '.xlsx"');

        // Add UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";
        echo implode("\t", $headers) . "\n";
        foreach ($records as $record) {
            $line = array_map(static function (string $v): string {
                $v = str_replace(["\t", "\r", "\n"], ' ', $v);
                return trim($v);
            }, $record);

            echo implode("\t", $line) . "\n";
        }
    }
}

function formatExportRow(array $row): array {
    return [
        (string)($row['timestamp'] ?? ''),
        (string)($row['device_id'] ?? ''),
        (string)($row['cpm'] ?? ''),
        (string)($row['acpm'] ?? ''),
        (string)($row['usv'] ?? ''),
        (string)($row['dose'] ?? ''),
        (string)($row['raw_data'] ?? ''),
    ];
}

function handleLogRequest(): void {
    $timestamp = gmdate('Y-m-d H:i:s');

    // Get parameters from GET request (GMC devices use GET)
    $deviceId = readParam(['ID', 'id', 'AID', 'aid', 'GID', 'gid'], 'UNKNOWN');
    $cpm      = readParam(['CPM', 'cpm'], '0');
    $acpm     = readParam(['ACPM', 'acpm'], '0');
    $usv      = readParam(['USV', 'uSV', 'uSv', 'usv'], '0.0');
    $dose     = readParam(['dose', 'DOSE'], '0');
    $clientIp = getClientIpFromRequest();

    if (!isDeviceAllowed($deviceId)) {
        http_response_code(403);
        echo 'FORBIDDEN';
        return;
    }

    // Capture all parameters for raw logging
    $rawData = json_encode($_GET, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO readings (timestamp, device_id, cpm, acpm, usv, dose, raw_data, client_ip)
         VALUES (:timestamp, :device_id, :cpm, :acpm, :usv, :dose, :raw_data, :client_ip)'
    );
    $stmt->execute([
        ':timestamp' => $timestamp,
        ':device_id' => $deviceId,
        ':cpm' => $cpm,
        ':acpm' => $acpm,
        ':usv' => $usv,
        ':dose' => $dose,
        ':raw_data' => $rawData,
        ':client_ip' => $clientIp,
    ]);

    // Return success response as expected by GMC devices
    echo 'OK';
}

function showViewer(array $filters, string $theme): void {
    $rows = fetchReadings($filters, MAX_VIEW_ROWS);
    $themeOptions = getThemeOptions();

    $chartData = [];
    foreach (CHART_BUCKETS as $bucket) {
        $chartData[$bucket] = fetchChartSeries($filters, $bucket);
    }

    // Detect if we have data
    $hasData = false;
    foreach($chartData as $data) {
        if (!empty($data['labels'])) {
            $hasData = true;
            break;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en" data-theme="<?= e($theme) ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>GMC-500+ Geiger Counter Data Logger</title>
        <link rel="stylesheet" href="gmc_log.css">
    </head>
    <body>
        <main class="page">
            <header class="header">
                <div class="header-top">
                    <h1>📊 GMC-500+ Geiger Counter Data Logger</h1>
                    <div class="theme-control">
                        <label for="theme-select">Theme</label>
                        <select id="theme-select" aria-label="Theme">
                            <?php foreach ($themeOptions as $themeKey => $themeLabel): ?>
                            <option value="<?= e($themeKey) ?>"<?= $themeKey === $theme ? ' selected' : '' ?>><?= e($themeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p>Filter, browse and export counter data</p>
            </header>

            <section class="card chart-card">
                <div class="chart-toolbar">
                    <label for="chart-range">Chart</label>
                    <select id="chart-range">
                        <option value="minute">Minute</option>
                        <option value="hourly">Hourly</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
                <h2 class="chart-title" id="chart-title" data-base-title="CPM and ACPM trend" data-from-label="From" data-to-label="To">CPM and ACPM trend (Hourly)</h2>
                <canvas id="cpmChart" height="280" aria-label="CPM ACPM chart"></canvas>
                <p id="chart-empty" class="chart-empty" hidden>No chart data available for selected period</p>
            </section>

            <details class="filters card">
                <summary>Select period</summary>
                <form method="get" action="">
                    <input type="hidden" name="theme" id="theme-hidden" value="<?= e($theme) ?>">
                    <div class="filter-grid">
                        <label>Period (From)
                            <input type="date" name="f_timestamp_from" value="<?= e($filters['timestamp_from']) ?>" placeholder="2026-02-20">
                        </label>
                        <label>Period (To)
                            <input type="date" name="f_timestamp_to" value="<?= e($filters['timestamp_to']) ?>" placeholder="2026-02-20">
                        </label>
                    </div>
                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="gmc_log.php?theme=<?= e($theme) ?>" class="btn btn-light">Reset</a>
                        <button type="submit" name="export" value="csv" class="btn btn-success">Export to CSV</button>
                        <button type="submit" name="export" value="xlsx" class="btn btn-success">Export to XLSX</button>
                    </div>
                </form>
            </details>

            <section class="card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Timestamp (UTC)</th>
                                <th>Device</th>
                                <th>CPM</th>
                                <th>ACPM</th>
                                <th>µSv/h</th>
                                <th>Dose</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e((string)$row['timestamp']) ?></td>
                                <td><?= e((string)$row['device_id']) ?></td>
                                <td><?= e((string)$row['cpm']) ?></td>
                                <td><?= e((string)$row['acpm']) ?></td>
                                <td><?= e((string)$row['usv']) ?></td>
                                <td><?= e((string)$row['dose']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (count($rows) === 0): ?>
                            <tr>
                                <td colspan="6" class="empty">No data available</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <footer class="footer">
                <span>Results shown: <strong><?= count($rows) ?></strong> (max <?= MAX_VIEW_ROWS ?>)</span>
                <span>Database: <strong><?= e(DB_FILE) ?></strong></span>
            </footer>
        </main>
        <script>
            const chartSeries = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const selectRange = document.getElementById('chart-range');
            const chartTitle = document.getElementById('chart-title');
            const themeSelect = document.getElementById('theme-select');
            const themeHidden = document.getElementById('theme-hidden');
            const canvas = document.getElementById('cpmChart');
            const emptyNote = document.getElementById('chart-empty');
            const context = canvas.getContext('2d');
            const htmlNode = document.documentElement;
            const allowedThemes = ['light', 'dark', 'forest', 'ocean', 'sunset', 'lavender', 'mono'];

            function cssColor(varName, fallback) {
                const value = getComputedStyle(htmlNode).getPropertyValue(varName).trim();
                return value || fallback;
            }

            function applyTheme(theme) {
                const safeTheme = allowedThemes.includes(theme) ? theme : 'light';
                htmlNode.setAttribute('data-theme', safeTheme);
                try {
                    localStorage.setItem('gmc_theme', safeTheme);
                } catch (error) {
                    // ignore localStorage issues
                }
                if (themeSelect.value !== safeTheme) {
                    themeSelect.value = safeTheme;
                }
                if (themeHidden) {
                    themeHidden.value = safeTheme;
                }
                drawLineChart(selectRange.value);
            }

            function updateChartTitle() {
                if (!chartTitle || !selectRange) {
                    return;
                }

                const baseTitle = chartTitle.dataset.baseTitle || chartTitle.textContent || '';
                const fromLabel = chartTitle.dataset.fromLabel || 'From';
                const toLabel = chartTitle.dataset.toLabel || 'To';
                const selectedLabel = selectRange.options[selectRange.selectedIndex]
                    ? selectRange.options[selectRange.selectedIndex].text
                    : '';
                const selectedBucket = selectRange.value;
                const currentSeries = chartSeries[selectedBucket] || { labels: [] };
                const labels = currentSeries.labels || [];

                let periodText = '';
                if (labels.length > 0) {
                    const firstLabel = labels[0];
                    const lastLabel = labels[labels.length - 1];
                    periodText = `${fromLabel}: ${firstLabel} ${toLabel}: ${lastLabel}`;
                }

                const mainTitle = selectedLabel ? `${baseTitle} (${selectedLabel})` : `${baseTitle}`;
                chartTitle.textContent = mainTitle;

                if (periodText !== '') {
                    chartTitle.appendChild(document.createElement('br'));
                    chartTitle.appendChild(document.createTextNode(periodText));
                }
            }

            function drawLineChart(bucket) {
                const data = chartSeries[bucket] || { labels: [], cpm: [], acpm: [] };
                const labels = data.labels || [];
                const cpmValues = data.cpm || [];
                const acpmValues = data.acpm || [];

                const width = canvas.clientWidth || canvas.parentElement.clientWidth;
                const height = 280;
                const dpr = window.devicePixelRatio || 1;

                canvas.width = Math.max(1, Math.floor(width * dpr));
                canvas.height = Math.max(1, Math.floor(height * dpr));
                context.setTransform(dpr, 0, 0, dpr, 0, 0);
                context.clearRect(0, 0, width, height);

                if (labels.length === 0) {
                    canvas.hidden = true;
                    emptyNote.hidden = false;
                    return;
                }

                canvas.hidden = false;
                emptyNote.hidden = true;

                const pad = { left: 48, right: 16, top: 18, bottom: 52 };
                const plotWidth = width - pad.left - pad.right;
                const plotHeight = height - pad.top - pad.bottom;

                const allValues = cpmValues.concat(acpmValues);
                const maxVal = Math.max(...allValues, 1);
                const yTicks = 5;

                const chartGrid = cssColor('--chart-grid', '#d1d5db');
                const chartText = cssColor('--chart-text', '#6b7280');
                const cpmColor = cssColor('--chart-cpm', '#0f766e');
                const acpmColor = cssColor('--chart-acpm', '#1d4ed8');
                const legendText = cssColor('--chart-legend-text', '#1f2937');

                context.strokeStyle = chartGrid;
                context.fillStyle = chartText;
                context.lineWidth = 1;
                context.font = '12px Segoe UI, Arial, sans-serif';

                for (let i = 0; i <= yTicks; i++) {
                    const y = pad.top + (plotHeight * i) / yTicks;
                    const value = maxVal - (maxVal * i) / yTicks;

                    context.beginPath();
                    context.moveTo(pad.left, y);
                    context.lineTo(width - pad.right, y);
                    context.stroke();

                    context.fillText(value.toFixed(0), 8, y + 4);
                }

                function xForIndex(index) {
                    if (labels.length === 1) return pad.left + plotWidth / 2;
                    return pad.left + (plotWidth * index) / (labels.length - 1);
                }

                function yForValue(value) {
                    return pad.top + ((maxVal - value) / (maxVal || 1)) * plotHeight;
                }

                function drawSeries(values, color) {
                    context.strokeStyle = color;
                    context.lineWidth = 2;
                    context.beginPath();

                    values.forEach((value, index) => {
                        const x = xForIndex(index);
                        const y = yForValue(value);
                        if (index === 0) context.moveTo(x, y);
                        else context.lineTo(x, y);
                    });

                    context.stroke();

                    context.fillStyle = color;
                    values.forEach((value, index) => {
                        const x = xForIndex(index);
                        const y = yForValue(value);
                        context.beginPath();
                        context.arc(x, y, 3, 0, Math.PI * 2);
                        context.fill();
                    });
                }

                drawSeries(cpmValues, cpmColor);
                drawSeries(acpmValues, acpmColor);

                context.fillStyle = chartText;
                context.textAlign = 'center';
                const maxLabels = Math.min(labels.length, 8);
                const step = Math.max(1, Math.ceil(labels.length / maxLabels));
                for (let i = 0; i < labels.length; i += step) {
                    context.fillText(labels[i], xForIndex(i), height - 20);
                }

                context.textAlign = 'left';
                context.fillStyle = cpmColor;
                context.fillRect(pad.left, 7, 14, 3);
                context.fillStyle = legendText;
                context.fillText('CPM', pad.left + 20, 13);

                context.fillStyle = acpmColor;
                context.fillRect(pad.left + 72, 7, 14, 3);
                context.fillStyle = legendText;
                context.fillText('ACPM', pad.left + 92, 13);
            }

            themeSelect.addEventListener('change', function () {
                applyTheme(themeSelect.value);

                const url = new URL(window.location.href);
                url.searchParams.set('theme', themeSelect.value);
                window.history.replaceState({}, '', url.toString());
            });

            selectRange.addEventListener('change', function () {
                updateChartTitle();
                drawLineChart(selectRange.value);
            });

            window.addEventListener('resize', function () {
                drawLineChart(selectRange.value);
            });

            let storedTheme = '';
            try {
                storedTheme = localStorage.getItem('gmc_theme') || '';
            } catch (error) {
                storedTheme = '';
            }
            if (storedTheme !== '') {
                applyTheme(storedTheme);
            } else {
                applyTheme(themeSelect.value);
            }

            updateChartTitle();
            drawLineChart(selectRange.value);

            const autoRefreshMs = 60 * 1000;
            setInterval(function () {
                window.location.reload();
            }, autoRefreshMs);
        </script>
    </body>
    </html>
    <?php
}

// --- Routing ---
try {
    $filters = getFiltersFromRequest();
    $theme = getThemeFromRequest();
    $export = strtolower(trim((string)($_GET['export'] ?? '')));

    if (hasLogParams()) {
        handleLogRequest();
    } elseif ($export === 'csv' || $export === 'xlsx') {
        exportReadings($export, $filters);
    } else {
        showViewer($filters, $theme);
    }
} catch (Throwable $e) {
    error_log('GMC Logger error: ' . $e->getMessage());
    http_response_code(500);
    echo 'ERROR';
}
?>
