<?php
include 'dbconnect.php';

function decryptData($encodedData) {
    if (!$encodedData) {
        return null;
    }

    $url = 'https://xxx.com/decrypted/' . urlencode($encodedData);
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
            // Check for duplicate
            $checkQuery = "SELECT COUNT(*) as count FROM register WHERE username = ? AND tel = ? AND accountNumber = ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("sss", $username, $tel, $accountNumber);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            if ($row['count'] == 0) {
                $stmt = $conn->prepare("INSERT INTO register (username, firstName, lastName, tel, accountNumber, bankName, prefix, createDate, bonus) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssssss", $username, $data['firstName'], $data['lastName'], $tel, $accountNumber, $data['bankName'], $data['prefix'], $data['createDate'], $data['bonus']);
                if ($stmt->execute()) {
                    echo json_encode(["message" => "Register data received"]);
                } else {
                    echo json_encode(["message" => "Error inserting register data"]);
                }
            } else {
                echo json_encode(["message" => "Duplicate register data"]);
            }
        } else {
            echo json_encode(["message" => "Invalid data received"]);
        }
    } elseif (isset($_GET['deposit'])) {
        $username = decryptData($data['username']);

        if ($username) {
            // Check for duplicate
            $checkQuery = "SELECT COUNT(*) as count FROM deposit WHERE username = ? AND bankNo = ? AND dateBank = ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("sss", $username, $data['bankNo'], $data['dateBank']);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            if ($row['count'] == 0) {
                $stmt = $conn->prepare("INSERT INTO deposit (username, bankName, bankNo, dateBank, detail, value, bonus, topUp, prefix, createDate, updateDate, actionName) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssiisssss", $username, $data['bankName'], $data['bankNo'], $data['dateBank'], $data['detail'], $data['value'], $data['bonus'], $data['topUp'], $data['prefix'], $data['createDate'], $data['updateDate'], $data['actionName']);
                if ($stmt->execute()) {
                    echo json_encode(["message" => "Deposit data received"]);
                } else {
                    echo json_encode(["message" => "Error inserting deposit data"]);
                }
            } else {
                echo json_encode(["message" => "Duplicate deposit data"]);
            }
        } else {
            echo json_encode(["message" => "Invalid data received"]);
        }
    } elseif (isset($_GET['withdraw'])) {
        $username = decryptData($data['username']);
        $accountNumber = decryptData($data['accountNumber']);
        $tel = decryptData($data['tel']);

        if ($username && $accountNumber && $tel) {
            // Check for duplicate
            $checkQuery = "SELECT COUNT(*) as count FROM withdraw WHERE username = ? AND accountNumber = ? AND createDate = ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("sss", $username, $accountNumber, $data['createDate']);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            if ($row['count'] == 0) {
                $stmt = $conn->prepare("INSERT INTO withdraw (username, accountNumber, tel, bankName, name, value, beforeValue, afterValue, type, prefix, createDate, updateDate, actionName) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssiissssss", $username, $accountNumber, $tel, $data['bankName'], $data['name'], $data['value'], $data['beforeValue'], $data['afterValue'], $data['type'], $data['prefix'], $data['createDate'], $data['updateDate'], $data['actionName']);
                if ($stmt->execute()) {
                    echo json_encode(["message" => "Withdraw data received"]);
                } else {
                    echo json_encode(["message" => "Error inserting withdraw data"]);
                }
            } else {
                echo json_encode(["message" => "Duplicate withdraw data"]);
            }
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
