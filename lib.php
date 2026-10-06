<?php
/**
 * Shared helpers: JSON responses, authentication, input validation,
 * and the configurable decrypt client.
 */

declare(strict_types=1);

require_once __DIR__ . '/app_config.php';

/** Send a JSON response with an HTTP status code and stop. */
function hd_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Authenticate an incoming webhook request.
 *
 * Accepts the request if WEBHOOK_SECRET is configured AND either:
 *   - header "X-Webhook-Secret" equals the secret (constant-time), OR
 *   - header "X-Signature: sha256=<hex>" is a valid HMAC-SHA256 of the raw
 *     body using the secret as key.
 *
 * If WEBHOOK_SECRET is empty, auth is skipped (logged intent: NOT recommended).
 */
function hd_require_webhook_auth(string $rawBody): void
{
    $secret = (string) hd_config('WEBHOOK_SECRET', '');
    if ($secret === '') {
        return; // auth disabled by configuration
    }

    $headers = hd_request_headers();

    $provided = $headers['x-webhook-secret'] ?? '';
    if ($provided !== '' && hash_equals($secret, $provided)) {
        return;
    }

    $sigHeader = $headers['x-signature'] ?? ($headers['x-hub-signature-256'] ?? '');
    if ($sigHeader !== '') {
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        if (hash_equals($expected, $sigHeader)) {
            return;
        }
    }

    hd_json(401, ['message' => 'Unauthorized']);
}

/** Case-insensitive request header map. */
function hd_request_headers(): array
{
    $out = [];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            $out[strtolower($k)] = $v;
        }
    }
    // Fallback for SAPIs without getallheaders (e.g. php -S sometimes).
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr($k, 5)));
            $out[$name] = $out[$name] ?? $v;
        }
    }
    return $out;
}

/**
 * HTTP Basic auth gate for view.php using VIEWER_USER + VIEWER_PASS_HASH.
 * Challenges the browser when credentials are missing or wrong.
 */
function hd_require_viewer_auth(): void
{
    $user = (string) hd_config('VIEWER_USER', '');
    $hash = (string) hd_config('VIEWER_PASS_HASH', '');

    if ($user === '' || $hash === '') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Viewer authentication is not configured. Set VIEWER_USER and VIEWER_PASS_HASH.";
        exit;
    }

    $givenUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $givenPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    $userOk = hash_equals($user, $givenUser);
    $passOk = $givenPass !== '' && password_verify($givenPass, $hash);

    if (!$userOk || !$passOk) {
        header('WWW-Authenticate: Basic realm="hookdata viewer"');
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Authentication required.';
        exit;
    }
}

/**
 * Decrypt a single field value via the configured service.
 *
 * - URL is taken from DECRYPT_API_URL. "{data}" is replaced with the
 *   url-encoded value; if the token is absent the encoded value is appended.
 * - Uses curl with a timeout and proper error handling.
 * - Returns null on any failure so callers can reject the record.
 * - If DECRYPT_PASSTHROUGH is true, returns the raw value unchanged (testing).
 */
function hd_decrypt_data($encodedData): ?string
{
    if ($encodedData === null || $encodedData === '') {
        return null;
    }
    $encodedData = (string) $encodedData;

    if (hd_config('DECRYPT_PASSTHROUGH', false) === true) {
        return $encodedData;
    }

    $template = (string) hd_config('DECRYPT_API_URL', '');
    if ($template === '') {
        return null; // not configured -> cannot decrypt
    }

    if (str_contains($template, '{data}')) {
        $url = str_replace('{data}', rawurlencode($encodedData), $template);
    } else {
        $url = rtrim($template, '/') . '/' . rawurlencode($encodedData);
    }

    $timeout = (int) hd_config('DECRYPT_TIMEOUT', '5');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlErr !== '' || $httpCode < 200 || $httpCode >= 300) {
        error_log("decrypt failed: http=$httpCode err=$curlErr");
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['decrypted'])) {
        return null;
    }

    $decrypted = (string) $data['decrypted'];
    $parts = explode(':', $decrypted);
    return count($parts) > 1 ? $parts[1] : $decrypted;
}

/**
 * Ensure all required keys exist and are scalar/non-empty in $data.
 * Returns the list of missing keys (empty = OK).
 */
function hd_missing_keys(array $data, array $required): array
{
    $missing = [];
    foreach ($required as $key) {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            $missing[] = $key;
        }
    }
    return $missing;
}

/** Coerce a value to int, or null if it is not a valid number. */
function hd_to_int($value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && is_numeric($value)) {
        return (int) $value;
    }
    if (is_float($value)) {
        return (int) $value;
    }
    return null;
}
