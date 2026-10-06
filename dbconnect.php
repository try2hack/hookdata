<?php
/**
 * Database connection.
 *
 * Reads credentials from the central config loader (env / .env / config.php)
 * and exposes a ready-to-use mysqli handle as $conn.
 *
 * mysqli is put into exception mode so callers can use try/catch instead of
 * checking every return value, and we never echo raw driver errors to clients.
 */

declare(strict_types=1);

require_once __DIR__ . '/app_config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Create and return a configured mysqli connection.
 * Throws mysqli_sql_exception on failure (report mode is enabled above).
 */
function hd_db_connect(): mysqli
{
    $host = (string) hd_config('DB_HOST', 'localhost');
    $user = (string) hd_config('DB_USER', '');
    $pass = (string) hd_config('DB_PASS', '');
    $name = (string) hd_config('DB_NAME', '');
    $port = (int)    hd_config('DB_PORT', '3306');

    $conn = new mysqli($host, $user, $pass, $name, $port);
    $conn->set_charset('utf8mb4');
    return $conn;
}

// Shared handle used by hook.php and view.php.
$conn = hd_db_connect();
