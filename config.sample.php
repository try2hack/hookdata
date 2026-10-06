<?php
/**
 * Local configuration overrides (OPTIONAL).
 *
 * Copy this file to `config.php` and fill in real values if you prefer a PHP
 * config file over environment variables. `config.php` is git-ignored.
 *
 * Precedence (highest first):
 *   1. Environment variables (getenv) / .env file
 *   2. Values returned from this array (config.php)
 *   3. Built-in defaults in dbconnect.php
 *
 * Any key you omit falls back to the next source, so you only need to list
 * the values you want to override.
 */

return [
    // --- Database ---
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'test_hook',
    'DB_USER' => 'hook_user',
    'DB_PASS' => 'change-me',

    // --- Decrypt service ---
    // The external service that decrypts incoming field values.
    // Must contain the token "{data}" which is replaced with the url-encoded payload,
    // OR end with "/" and the encoded data is appended.
    'DECRYPT_API_URL'     => 'https://example.com/decrypted/{data}',
    'DECRYPT_TIMEOUT'     => '5',    // seconds
    // If the decrypt service is unavailable, treat the raw value as already-plaintext
    // (useful for testing). Keep this false in production.
    'DECRYPT_PASSTHROUGH' => false,

    // --- Webhook authentication (hook.php) ---
    // Shared secret sent by the caller in the "X-Webhook-Secret" header,
    // and/or used as the HMAC-SHA256 key for the "X-Signature" header
    // (format: "sha256=<hex>"). Leave empty to DISABLE auth (NOT recommended).
    'WEBHOOK_SECRET' => 'generate-a-long-random-string',

    // --- Viewer authentication (view.php, HTTP Basic auth) ---
    'VIEWER_USER' => 'admin',
    // Generate with: php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
    'VIEWER_PASS_HASH' => '',
];
