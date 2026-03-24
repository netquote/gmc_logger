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
require_once __DIR__ . '/gmc_common.php';

define('WHITELIST_FILE', __DIR__ . '/whitelist.txt');
define('MAX_VIEW_ROWS', 50);
// --------------------

function hasLogParams(): bool {
    foreach (['CPM', 'cpm', 'ID', 'id', 'AID', 'aid', 'GID', 'gid'] as $key) {
        if (isset($_GET[$key])) {
            return true;
        }
    }

    return false;
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



function fetchChartData(string $range): array {
    return dbFetchChartData($range);
}

function fetchReadings(?int $limit = MAX_VIEW_ROWS): array {
    return dbFetchRecentReadings($limit);
}

function exportReadings(string $format): void {
    $rows = fetchReadings(null);
    $fileStamp = gmdate('Ymd_His');
    $headers = ['Timestamp', 'DeviceID', 'CPM', 'ACPM', 'uSv/h', 'Dose'];
    $records = array_map('formatExportRow', $rows);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="readings_' . $fileStamp . '.csv"');

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
        header('Content-Disposition: attachment; filename="readings_' . $fileStamp . '.xlsx"');

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

    dbInsertReading([
        'timestamp' => $timestamp,
        'device_id' => $deviceId,
        'cpm' => $cpm,
        'acpm' => $acpm,
        'usv' => $usv,
        'dose' => $dose,
        'raw_data' => $rawData,
        'client_ip' => $clientIp,
    ]);

    // Return success response as expected by GMC devices
    echo 'OK';
}

function showViewer(string $theme): void {
    $rows = fetchReadings(MAX_VIEW_ROWS);
    $themeOptions = getThemeOptions();

    $chartDay   = fetchChartData('day');
    $chartWeek  = fetchChartData('week');
    $chartMonth = fetchChartData('month');
    $chartYear  = fetchChartData('year');
    ?>
    <!DOCTYPE html>
    <html lang="en" data-theme="<?= e($theme) ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>GMC-500+ Geiger Counter Data Logger</title>
        <link rel="stylesheet" href="gmc_log.css?v=<?= (int)@filemtime(__DIR__ . '/gmc_log.css') ?>">
    </head>
    <body>
        <main class="page">
            <header class="header">
                <div class="header-top">
                    <h1>☢️ GMC-500+ Geiger Counter Data Logger</h1>
                    <div class="theme-control">
                        <label for="theme-select">Theme</label>
                        <select id="theme-select" aria-label="Theme">
                            <?php foreach ($themeOptions as $themeKey => $themeLabel): ?>
                            <option value="<?= e($themeKey) ?>"<?= $themeKey === $theme ? ' selected' : '' ?>><?= e($themeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p>Browse and export counter data</p>
            </header>

            <section class="card chart-section">
                <div class="chart-header">
                    <div class="chart-tabs">
                        <button class="chart-tab active" data-range="day">Last Day</button>
                        <button class="chart-tab" data-range="week">Last Week</button>
                        <button class="chart-tab" data-range="month">Last Month</button>
                        <button class="chart-tab" data-range="year">Last Year</button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="radiationChart"></canvas>
                </div>
                <div id="chart-empty" class="chart-empty" style="display:none;">No data available for this period</div>
            </section>

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

            <div class="export-bar card">
                <a href="gmc_log.php?export=csv&theme=<?= e($theme) ?>" class="btn btn-success">Export to CSV</a>
                <a href="gmc_log.php?export=xlsx&theme=<?= e($theme) ?>" class="btn btn-success">Export to XLSX</a>
            </div>

            <footer class="footer">
                <span>Results shown: <strong><?= count($rows) ?></strong></span>
            </footer>
        </main>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
        <script>
            const chartData = {
                day:   <?= json_encode($chartDay, JSON_UNESCAPED_UNICODE) ?>,
                week:  <?= json_encode($chartWeek, JSON_UNESCAPED_UNICODE) ?>,
                month: <?= json_encode($chartMonth, JSON_UNESCAPED_UNICODE) ?>,
                year:  <?= json_encode($chartYear, JSON_UNESCAPED_UNICODE) ?>
            };
        </script>
        <script>
            const themeSelect = document.getElementById('theme-select');
            const htmlNode = document.documentElement;
            const allowedThemes = ['light', 'dark', 'forest', 'ocean', 'sunset', 'lavender', 'mono'];

            function applyTheme(theme) {
                const safeTheme = allowedThemes.includes(theme) ? theme : 'dark';
                htmlNode.setAttribute('data-theme', safeTheme);
                try {
                    localStorage.setItem('gmc_theme', safeTheme);
                } catch (error) {
                    // ignore localStorage issues
                }
                if (themeSelect.value !== safeTheme) {
                    themeSelect.value = safeTheme;
                }
            }

            themeSelect.addEventListener('change', function () {
                applyTheme(themeSelect.value);

                const url = new URL(window.location.href);
                url.searchParams.set('theme', themeSelect.value);
                window.history.replaceState({}, '', url.toString());
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

            const autoRefreshMs = 60 * 1000;
            setInterval(function () {
                window.location.reload();
            }, autoRefreshMs);
        </script>
        <script>
            (function () {
                const ctx = document.getElementById('radiationChart').getContext('2d');
                const emptyMsg = document.getElementById('chart-empty');
                let chart = null;

                function getChartColors() {
                    const style = getComputedStyle(document.documentElement);
                    return {
                        text: style.getPropertyValue('--text').trim() || '#1f2937',
                        muted: style.getPropertyValue('--muted').trim() || '#6b7280',
                        border: style.getPropertyValue('--border').trim() || '#e5e7eb',
                        cpm: style.getPropertyValue('--btn-primary-bg').trim() || '#0f766e',
                        acpm: style.getPropertyValue('--btn-success-bg').trim() || '#166534'
                    };
                }

                function renderChart(range) {
                    const data = chartData[range];
                    if (!data || data.labels.length === 0) {
                        ctx.canvas.style.display = 'none';
                        emptyMsg.style.display = 'block';
                        if (chart) { chart.destroy(); chart = null; }
                        return;
                    }
                    ctx.canvas.style.display = 'block';
                    emptyMsg.style.display = 'none';

                    const colors = getChartColors();
                    if (chart) { chart.destroy(); }

                    chart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.labels,
                            datasets: [
                                {
                                    label: 'CPM',
                                    data: data.cpm,
                                    borderColor: colors.cpm,
                                    backgroundColor: colors.cpm + '22',
                                    borderWidth: 2.5,
                                    pointRadius: data.labels.length > 60 ? 0 : 3,
                                    pointHoverRadius: 5,
                                    tension: 0.3,
                                    fill: true
                                },
                                {
                                    label: 'ACPM',
                                    data: data.acpm,
                                    borderColor: colors.acpm,
                                    backgroundColor: colors.acpm + '22',
                                    borderWidth: 2.5,
                                    pointRadius: data.labels.length > 60 ? 0 : 3,
                                    pointHoverRadius: 5,
                                    tension: 0.3,
                                    fill: true
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: {
                                    labels: { color: colors.text, usePointStyle: true, padding: 16 }
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(0,0,0,0.8)',
                                    titleColor: '#fff',
                                    bodyColor: '#fff',
                                    cornerRadius: 8,
                                    padding: 10
                                }
                            },
                            scales: {
                                x: {
                                    ticks: { color: colors.muted, maxRotation: 45 },
                                    grid: { color: colors.border + '66' }
                                },
                                y: {
                                    beginAtZero: false,
                                    ticks: { color: colors.muted },
                                    grid: { color: colors.border + '66' },
                                    title: {
                                        display: true,
                                        text: 'Counts Per Minute',
                                        color: colors.muted
                                    }
                                }
                            }
                        }
                    });
                }

                document.querySelectorAll('.chart-tab').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        document.querySelectorAll('.chart-tab').forEach(function (b) { b.classList.remove('active'); });
                        btn.classList.add('active');
                        renderChart(btn.dataset.range);
                    });
                });

                renderChart('day');

                const observer = new MutationObserver(function () {
                    const activeTab = document.querySelector('.chart-tab.active');
                    if (activeTab) { renderChart(activeTab.dataset.range); }
                });
                observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
            })();
        </script>
    </body>
    </html>
    <?php
}

// --- Routing ---
try {
    $theme = getThemeFromRequest();
    $export = strtolower(trim((string)($_GET['export'] ?? '')));

    if (hasLogParams()) {
        handleLogRequest();
    } elseif ($export === 'csv' || $export === 'xlsx') {
        exportReadings($export);
    } else {
        showViewer($theme);
    }
} catch (Throwable $e) {
    error_log('GMC Logger error: ' . $e->getMessage());
    http_response_code(500);
    echo 'ERROR';
}
?>
