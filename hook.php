<?php
$servername = "localhost";
$username = "test_master";
$password = "!kwr0i*%%qzelgkD";
$dbname = "test_hook";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

function decryptData($encodedData) {
    if (!$encodedData) {
        return null;
    }
    
    $url = 'https://krmmkqbuy4.execute-api.ap-southeast-1.amazonaws.com/prod/secure/decrypted/' . $encodedData;
    $response = @file_get_contents($url);

    if ($response === FALSE) {
        return null;
    }
    
    $data = json_decode($response, true);
    $decrypted = isset($data['decrypted']) ? $data['decrypted'] : null;
    $parts = $decrypted ? explode(':', $decrypted) : [];
    return count($parts) > 1 ? $parts[1] : $decrypted;
}

$inputData = file_get_contents('php://input');
$data = $inputData ? json_decode($inputData, true)['data'] : null;

if ($data) {
    if (isset($_GET['register'])) {
        $username = decryptData($data['username']);
        $tel = decryptData($data['tel']);
        $accountNumber = decryptData($data['accountNumber']);

        if ($username && $tel && $accountNumber) {
            $stmt = $conn->prepare("INSERT INTO register (username, firstName, lastName, tel, accountNumber, bankName, prefix, createDate, bonus) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssss", $username, $data['firstName'], $data['lastName'], $tel, $accountNumber, $data['bankName'], $data['prefix'], $data['createDate'], $data['bonus']);
            $stmt->execute();
            echo json_encode(["message" => "Register data received"]);
        } else {
            echo json_encode(["message" => "Invalid data received"]);
        }
    } elseif (isset($_GET['deposit'])) {
        $username = decryptData($data['username']);

        if ($username) {
            $stmt = $conn->prepare("INSERT INTO deposit (username, bankName, bankNo, dateBank, detail, value, bonus, topUp, prefix, createDate, updateDate, actionName) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssiisssss", $username, $data['bankName'], $data['bankNo'], $data['dateBank'], $data['detail'], $data['value'], $data['bonus'], $data['topUp'], $data['prefix'], $data['createDate'], $data['updateDate'], $data['actionName']);
            $stmt->execute();
            echo json_encode(["message" => "Deposit data received"]);
        } else {
            echo json_encode(["message" => "Invalid data received"]);
        }
    } elseif (isset($_GET['withdraw'])) {
        $username = decryptData($data['username']);
        $accountNumber = decryptData($data['accountNumber']);
        $tel = decryptData($data['tel']);

        if ($username && $accountNumber && $tel) {
            $stmt = $conn->prepare("INSERT INTO withdraw (username, accountNumber, tel, bankName, name, value, beforeValue, afterValue, type, prefix, createDate, updateDate, actionName) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssiissssss", $username, $accountNumber, $tel, $data['bankName'], $data['name'], $data['value'], $data['beforeValue'], $data['afterValue'], $data['type'], $data['prefix'], $data['createDate'], $data['updateDate'], $data['actionName']);
            $stmt->execute();
            echo json_encode(["message" => "Withdraw data received"]);
        } else {
            echo json_encode(["message" => "Invalid data received"]);
        }
    } else {
        http_response_code(404);
        echo json_encode(["message" => "Not Found"]);
    }
} else {
    echo json_encode(["message" => "No data received"]);
}

$conn->close();
?>
