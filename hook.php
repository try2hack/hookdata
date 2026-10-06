<?php
/**
 * Webhook receiver.
 *
 * Endpoints (selected by query string), all POST only:
 *   POST /hook.php?register   { "data": { ... } }
 *   POST /hook.php?deposit    { "data": { ... } }
 *   POST /hook.php?withdraw   { "data": { ... } }
 *
 * Auth: see hd_require_webhook_auth() (X-Webhook-Secret header and/or
 * X-Signature HMAC). Responses are always JSON with a correct status code.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/dbconnect.php';

// --- Method check -----------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    hd_json(405, ['message' => 'Method Not Allowed']);
}

// --- Authentication ---------------------------------------------------------
$rawBody = file_get_contents('php://input');
$rawBody = $rawBody === false ? '' : $rawBody;
hd_require_webhook_auth($rawBody);

// --- Parse body -------------------------------------------------------------
if ($rawBody === '') {
    hd_json(400, ['message' => 'No data received']);
}

$decoded = json_decode($rawBody, true);
if (!is_array($decoded)) {
    hd_json(400, ['message' => 'Invalid JSON']);
}

$data = $decoded['data'] ?? null;
if (!is_array($data)) {
    hd_json(422, ['message' => 'Missing "data" object']);
}

// --- Determine endpoint -----------------------------------------------------
$endpoint = null;
foreach (['register', 'deposit', 'withdraw'] as $name) {
    if (isset($_GET[$name])) {
        $endpoint = $name;
        break;
    }
}
if ($endpoint === null) {
    hd_json(404, ['message' => 'Not Found']);
}

try {
    switch ($endpoint) {
        case 'register':
            handle_register($conn, $data);
            break;
        case 'deposit':
            handle_deposit($conn, $data);
            break;
        case 'withdraw':
            handle_withdraw($conn, $data);
            break;
    }
} catch (mysqli_sql_exception $e) {
    error_log('hookdata DB error: ' . $e->getMessage());
    hd_json(500, ['message' => 'Internal server error']);
} finally {
    $conn->close();
}

// ---------------------------------------------------------------------------

function handle_register(mysqli $conn, array $data): void
{
    $required = ['username', 'tel', 'accountNumber', 'firstName', 'lastName',
                 'bankName', 'prefix', 'createDate', 'bonus'];
    $missing = hd_missing_keys($data, $required);
    if ($missing) {
        hd_json(422, ['message' => 'Missing fields', 'fields' => $missing]);
    }

    $username      = hd_decrypt_data($data['username']);
    $tel           = hd_decrypt_data($data['tel']);
    $accountNumber = hd_decrypt_data($data['accountNumber']);

    if (!$username || !$tel || !$accountNumber) {
        hd_json(422, ['message' => 'Invalid data received (decrypt failed)']);
    }

    $check = $conn->prepare(
        'SELECT COUNT(*) AS count FROM register WHERE username = ? AND tel = ? AND accountNumber = ?'
    );
    $check->bind_param('sss', $username, $tel, $accountNumber);
    $check->execute();
    $count = (int) ($check->get_result()->fetch_assoc()['count'] ?? 0);
    if ($count > 0) {
        hd_json(200, ['message' => 'Duplicate register data']);
    }

    $stmt = $conn->prepare(
        'INSERT INTO register (username, firstName, lastName, tel, accountNumber, bankName, prefix, createDate, bonus)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $firstName = (string) $data['firstName'];
    $lastName  = (string) $data['lastName'];
    $bankName  = (string) $data['bankName'];
    $prefix    = (string) $data['prefix'];
    $createDate = (string) $data['createDate'];
    $bonus     = (string) $data['bonus'];
    $stmt->bind_param('sssssssss', $username, $firstName, $lastName, $tel,
        $accountNumber, $bankName, $prefix, $createDate, $bonus);
    $stmt->execute();

    hd_json(201, ['message' => 'Register data received']);
}

function handle_deposit(mysqli $conn, array $data): void
{
    $required = ['username', 'bankName', 'bankNo', 'dateBank', 'detail',
                 'value', 'bonus', 'topUp', 'prefix', 'createDate',
                 'updateDate', 'actionName'];
    $missing = hd_missing_keys($data, $required);
    if ($missing) {
        hd_json(422, ['message' => 'Missing fields', 'fields' => $missing]);
    }

    $username = hd_decrypt_data($data['username']);
    if (!$username) {
        hd_json(422, ['message' => 'Invalid data received (decrypt failed)']);
    }

    $value = hd_to_int($data['value']);
    $bonus = hd_to_int($data['bonus']);
    if ($value === null || $bonus === null) {
        hd_json(422, ['message' => 'Fields "value" and "bonus" must be numeric']);
    }

    $check = $conn->prepare(
        'SELECT COUNT(*) AS count FROM deposit WHERE username = ? AND bankNo = ? AND dateBank = ?'
    );
    $bankNo   = (string) $data['bankNo'];
    $dateBank = (string) $data['dateBank'];
    $check->bind_param('sss', $username, $bankNo, $dateBank);
    $check->execute();
    $count = (int) ($check->get_result()->fetch_assoc()['count'] ?? 0);
    if ($count > 0) {
        hd_json(200, ['message' => 'Duplicate deposit data']);
    }

    $stmt = $conn->prepare(
        'INSERT INTO deposit (username, bankName, bankNo, dateBank, detail, value, bonus, topUp, prefix, createDate, updateDate, actionName)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $bankName   = (string) $data['bankName'];
    $detail     = (string) $data['detail'];
    $topUp      = (string) $data['topUp'];
    $prefix     = (string) $data['prefix'];
    $createDate = (string) $data['createDate'];
    $updateDate = (string) $data['updateDate'];
    $actionName = (string) $data['actionName'];
    $stmt->bind_param('sssssiisssss', $username, $bankName, $bankNo, $dateBank,
        $detail, $value, $bonus, $topUp, $prefix, $createDate, $updateDate, $actionName);
    $stmt->execute();

    hd_json(201, ['message' => 'Deposit data received']);
}

function handle_withdraw(mysqli $conn, array $data): void
{
    $required = ['username', 'accountNumber', 'tel', 'bankName', 'name',
                 'value', 'beforeValue', 'afterValue', 'type', 'prefix',
                 'createDate', 'updateDate', 'actionName'];
    $missing = hd_missing_keys($data, $required);
    if ($missing) {
        hd_json(422, ['message' => 'Missing fields', 'fields' => $missing]);
    }

    $username      = hd_decrypt_data($data['username']);
    $accountNumber = hd_decrypt_data($data['accountNumber']);
    $tel           = hd_decrypt_data($data['tel']);
    if (!$username || !$accountNumber || !$tel) {
        hd_json(422, ['message' => 'Invalid data received (decrypt failed)']);
    }

    $value       = hd_to_int($data['value']);
    $beforeValue = hd_to_int($data['beforeValue']);
    if ($value === null || $beforeValue === null) {
        hd_json(422, ['message' => 'Fields "value" and "beforeValue" must be numeric']);
    }

    $check = $conn->prepare(
        'SELECT COUNT(*) AS count FROM withdraw WHERE username = ? AND accountNumber = ? AND createDate = ?'
    );
    $createDate = (string) $data['createDate'];
    $check->bind_param('sss', $username, $accountNumber, $createDate);
    $check->execute();
    $count = (int) ($check->get_result()->fetch_assoc()['count'] ?? 0);
    if ($count > 0) {
        hd_json(200, ['message' => 'Duplicate withdraw data']);
    }

    $stmt = $conn->prepare(
        'INSERT INTO withdraw (username, accountNumber, tel, bankName, name, value, beforeValue, afterValue, type, prefix, createDate, updateDate, actionName)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $bankName   = (string) $data['bankName'];
    $name       = (string) $data['name'];
    $afterValue = (string) $data['afterValue'];
    $type       = (string) $data['type'];
    $prefix     = (string) $data['prefix'];
    $updateDate = (string) $data['updateDate'];
    $actionName = (string) $data['actionName'];
    $stmt->bind_param('sssssiissssss', $username, $accountNumber, $tel, $bankName,
        $name, $value, $beforeValue, $afterValue, $type, $prefix, $createDate,
        $updateDate, $actionName);
    $stmt->execute();

    hd_json(201, ['message' => 'Withdraw data received']);
}
