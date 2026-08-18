<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Access logger — writes one line per event to LOG_DIR/yyyy-mm-dd_access.log.
 * Line format: timestamp | EVENT | sub | name | ip | detail
 */
function write_access_log(string $event, string $sub, string $name, string $detail = ''): void {
    if (!defined('LOG_DIR') || !LOG_DIR) return;
    $dir = rtrim(LOG_DIR, '/\\');
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) return;
    }
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $ip = trim(explode(',', $ip)[0]);

    $line = implode(' | ', [
        date('Y-m-d H:i:s'),
        strtoupper($event),
        $sub    ?: '-',
        $name   ?: '-',
        $ip,
        $detail,
    ]) . "\n";

    file_put_contents($dir . '/' . date('Y-m-d') . '_access.log', $line, FILE_APPEND | LOCK_EX);
}
