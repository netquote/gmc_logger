<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Shared helpers for GMC logger/admin pages.
declare(strict_types=1);

if (!defined('DB_FILE')) {
    define('DB_FILE', 'gmc_logs/gmc_readings.sqlite');
}

if (!defined('THEMES')) {
    define('THEMES', [
        'light' => 'White',
        'dark' => 'Dark',
        'forest' => 'Forest',
        'ocean' => 'Ocean',
        'sunset' => 'Sunset',
        'lavender' => 'Lavender',
        'mono' => 'Monochrome',
    ]);
}

if (!defined('DEFAULT_THEME')) {
    define('DEFAULT_THEME', 'dark');
}

if (!function_exists('e')) {
    function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('formatTimestamp')) {
    function formatTimestamp(string $value): string {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $dt instanceof DateTimeImmutable ? $dt->format('d/m/Y H:i:s') : $value;
    }
}

if (!function_exists('getThemeFromSources')) {
    function getThemeFromSources(array $get, array $post): string {
        $fromGet = strtolower(trim((string)($get['theme'] ?? '')));
        $fromPost = strtolower(trim((string)($post['theme'] ?? '')));
        $theme = $fromGet !== '' ? $fromGet : $fromPost;

        if ($theme === '') {
            return DEFAULT_THEME;
        }

        return array_key_exists($theme, THEMES) ? $theme : DEFAULT_THEME;
    }
}

if (!function_exists('getThemeFromRequest')) {
    function getThemeFromRequest(): string {
        return getThemeFromSources($_GET, []);
    }
}

if (!function_exists('getThemeOptions')) {
    function getThemeOptions(): array {
        return THEMES;
    }
}

if (!function_exists('getDb')) {
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
}

if (!function_exists('dbInsertReading')) {
    function dbInsertReading(array $reading): void {
        $db = getDb();
        $stmt = $db->prepare(
            'INSERT INTO readings (timestamp, device_id, cpm, acpm, usv, dose, raw_data, client_ip)
             VALUES (:timestamp, :device_id, :cpm, :acpm, :usv, :dose, :raw_data, :client_ip)'
        );
        $stmt->execute([
            ':timestamp' => (string)($reading['timestamp'] ?? ''),
            ':device_id' => (string)($reading['device_id'] ?? ''),
            ':cpm' => (string)($reading['cpm'] ?? ''),
            ':acpm' => (string)($reading['acpm'] ?? ''),
            ':usv' => (string)($reading['usv'] ?? ''),
            ':dose' => (string)($reading['dose'] ?? ''),
            ':raw_data' => (string)($reading['raw_data'] ?? '{}'),
            ':client_ip' => (string)($reading['client_ip'] ?? ''),
        ]);
    }
}

if (!function_exists('dbFetchRecentReadings')) {
    function dbFetchRecentReadings(?int $limit = null): array {
        $db = getDb();
        $sql = 'SELECT timestamp, device_id, cpm, acpm, usv, dose, raw_data, client_ip FROM readings ORDER BY id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $stmt = $db->query($sql);
        return $stmt->fetchAll();
    }
}

if (!function_exists('dbFetchChartData')) {
    function dbFetchChartData(string $range): array {
        switch ($range) {
            case 'day':
                $since = gmdate('Y-m-d H:i:s', time() - 86400);
                $groupExpr = "strftime('%Y-%m-%d %H:', timestamp) || (CAST(strftime('%M', timestamp) AS INTEGER) / 10 * 10)";
                $labelExpr = "strftime('%H:', timestamp) || SUBSTR('0' || (CAST(strftime('%M', timestamp) AS INTEGER) / 10 * 10), -2)";
                break;
            case 'week':
                $since = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
                $groupExpr = "strftime('%Y-%m-%d', timestamp) || ' ' || CASE WHEN CAST(strftime('%H', timestamp) AS INTEGER) < 6 THEN '00' WHEN CAST(strftime('%H', timestamp) AS INTEGER) < 12 THEN '06' WHEN CAST(strftime('%H', timestamp) AS INTEGER) < 18 THEN '12' ELSE '18' END";
                $labelExpr = "strftime('%m-%d', timestamp) || ' ' || CASE WHEN CAST(strftime('%H', timestamp) AS INTEGER) < 6 THEN '00h' WHEN CAST(strftime('%H', timestamp) AS INTEGER) < 12 THEN '06h' WHEN CAST(strftime('%H', timestamp) AS INTEGER) < 18 THEN '12h' ELSE '18h' END";
                break;
            case 'month':
                $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
                $groupExpr = "strftime('%Y-%m-%d', timestamp) || ' ' || CASE WHEN CAST(strftime('%H', timestamp) AS INTEGER) < 6 THEN '00' WHEN CAST(strftime('%H', timestamp) AS INTEGER) < 12 THEN '06' WHEN CAST(strftime('%H', timestamp) AS INTEGER) < 18 THEN '12' ELSE '18' END";
                $labelExpr = "strftime('%m-%d', timestamp) || ' ' || CASE WHEN CAST(strftime('%H', timestamp) AS INTEGER) < 6 THEN '00h' WHEN CAST(strftime('%H', timestamp) AS INTEGER) < 12 THEN '06h' WHEN CAST(strftime('%H', timestamp) AS INTEGER) < 18 THEN '12h' ELSE '18h' END";
                break;
            case 'year':
                $since = gmdate('Y-m-d H:i:s', time() - 365 * 86400);
                $groupExpr = "strftime('%Y-W', timestamp) || SUBSTR('0' || ((CAST(strftime('%j', timestamp) AS INTEGER) - 1) / 7 + 1), -2)";
                $labelExpr = "strftime('%m-', timestamp) || 'W' || SUBSTR('0' || ((CAST(strftime('%j', timestamp) AS INTEGER) - 1) / 7 + 1), -2)";
                break;
            default:
                return ['labels' => [], 'cpm' => [], 'acpm' => []];
        }

        $db = getDb();
        $sql = "SELECT {$labelExpr} AS label,
                       ROUND(AVG(CAST(cpm AS REAL)), 3) AS avg_cpm,
                       ROUND(AVG(CAST(acpm AS REAL)), 3) AS avg_acpm
                FROM readings
                WHERE timestamp >= :since
                GROUP BY {$groupExpr}
                ORDER BY {$groupExpr} ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute([':since' => $since]);
        $rows = $stmt->fetchAll();

        $labels = [];
        $cpm = [];
        $acpm = [];
        foreach ($rows as $row) {
            $labels[] = (string)$row['label'];
            $cpm[] = (float)$row['avg_cpm'];
            $acpm[] = (float)$row['avg_acpm'];
        }

        return ['labels' => $labels, 'cpm' => $cpm, 'acpm' => $acpm];
    }
}

if (!function_exists('dbFetchPaginatedReadings')) {
    function dbFetchPaginatedReadings(int $page, int $rowsPerPage, ?array $range = null): array {
        $db = getDb();
        $whereSql = '';
        $params = [];

        if ($range !== null) {
            $whereSql = ' WHERE timestamp >= :from AND timestamp <= :to';
            $params = [
                ':from' => (string)($range['from'] ?? ''),
                ':to' => (string)($range['to'] ?? ''),
            ];
        }

        $countStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM readings' . $whereSql);
        foreach ($params as $name => $value) {
            $countStmt->bindValue($name, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();

        $safeRowsPerPage = max(1, $rowsPerPage);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $safeRowsPerPage));
        $currentPage = min(max(1, $page), $totalPages);
        $offset = ($currentPage - 1) * $safeRowsPerPage;

        $stmt = $db->prepare(
            'SELECT id, timestamp, device_id, cpm, acpm, usv, dose, client_ip
             FROM readings' . $whereSql . '
             ORDER BY timestamp DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $safeRowsPerPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'page' => $currentPage,
            'rows' => $stmt->fetchAll(),
            'totalRows' => $totalRows,
            'totalPages' => $totalPages,
        ];
    }
}

if (!function_exists('dbDeleteReadingsByPeriod')) {
    function dbDeleteReadingsByPeriod(string $from, string $to): int {
        $db = getDb();
        $stmt = $db->prepare('DELETE FROM readings WHERE timestamp >= :from AND timestamp <= :to');
        $stmt->execute([':from' => $from, ':to' => $to]);

        return $stmt->rowCount();
    }
}

if (!function_exists('dbDeleteReadingsByIds')) {
    function dbDeleteReadingsByIds(array $ids): int {
        $safeIds = array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0);
        if (count($safeIds) === 0) {
            return 0;
        }

        $db = getDb();
        $placeholders = implode(',', array_fill(0, count($safeIds), '?'));
        $stmt = $db->prepare("DELETE FROM readings WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($safeIds));

        return $stmt->rowCount();
    }
}

if (!function_exists('dbFetchReadingsStats')) {
    function dbFetchReadingsStats(): array {
        $db = getDb();
        $statsStmt = $db->query('SELECT COUNT(*) AS total, MIN(timestamp) AS earliest, MAX(timestamp) AS latest FROM readings');
        $stats = $statsStmt->fetch();

        if (!is_array($stats)) {
            return ['total' => 0, 'earliest' => null, 'latest' => null];
        }

        return [
            'total' => (int)($stats['total'] ?? 0),
            'earliest' => $stats['earliest'] ?? null,
            'latest' => $stats['latest'] ?? null,
        ];
    }
}
