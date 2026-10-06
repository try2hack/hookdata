<?php
/**
 * Central configuration loader.
 *
 * Resolution order for every key (highest precedence first):
 *   1. Real environment variables (getenv)
 *   2. A .env file in this directory (simple KEY=VALUE lines)
 *   3. An optional, git-ignored config.php returning an associative array
 *   4. The built-in defaults below
 *
 * Nothing secret lives in this committed file.
 */

declare(strict_types=1);

/**
 * Load .env (once) into a static cache. Supports KEY=VALUE, # comments,
 * optional surrounding quotes. Does not override real environment variables.
 */
function hd_env_file(): array
{
    static $env = null;
    if ($env !== null) {
        return $env;
    }
    $env = [];
    $path = __DIR__ . '/.env';
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $env[$k] = $v;
        }
    }
    return $env;
}

/** Load the optional git-ignored config.php array (once). */
function hd_config_file(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $cfg = [];
    $path = __DIR__ . '/config.php';
    if (is_readable($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $cfg = $loaded;
        }
    }
    return $cfg;
}

/** Built-in safe defaults (no secrets). */
function hd_config_defaults(): array
{
    return [
        'DB_HOST'             => 'localhost',
        'DB_PORT'             => '3306',
        'DB_NAME'             => 'test_hook',
        'DB_USER'             => '',
        'DB_PASS'             => '',
        'DECRYPT_API_URL'     => '',
        'DECRYPT_TIMEOUT'     => '5',
        'DECRYPT_PASSTHROUGH' => false,
        'WEBHOOK_SECRET'      => '',
        'VIEWER_USER'         => '',
        'VIEWER_PASS_HASH'    => '',
    ];
}

/** Fetch a single configuration value. */
function hd_config(string $key, $default = null)
{
    $fromEnv = getenv($key);
    if ($fromEnv !== false && $fromEnv !== '') {
        return hd_normalize($fromEnv);
    }

    $envFile = hd_env_file();
    if (array_key_exists($key, $envFile) && $envFile[$key] !== '') {
        return hd_normalize($envFile[$key]);
    }

    $cfgFile = hd_config_file();
    if (array_key_exists($key, $cfgFile)) {
        return $cfgFile[$key];
    }

    $defaults = hd_config_defaults();
    if (array_key_exists($key, $defaults)) {
        return $defaults[$key];
    }

    return $default;
}

/** Turn string booleans coming from env/.env into real booleans. */
function hd_normalize(string $v)
{
    $lower = strtolower(trim($v));
    if ($lower === 'true')  return true;
    if ($lower === 'false') return false;
    return $v;
}
