<?php
$servername = "localhost";
$username = "test_master";
$password = "Passw0rd";
$dbname = "test_hook";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$type = isset($_POST['type']) ? $_POST['type'] : null;
$username = isset($_POST['username']) ? $_POST['username'] : null;

$registerRows = [];
$depositRows = [];
$withdrawRows = [];

if ($username) {
    if ($type == 'register') {
        $stmt = $conn->prepare("SELECT * FROM register WHERE username = ? ORDER BY id DESC LIMIT 30");
        $stmt->bind_param("s", $username);
    } elseif ($type == 'deposit') {
        $stmt = $conn->prepare("SELECT * FROM deposit WHERE username = ? ORDER BY id DESC LIMIT 30");
        $stmt->bind_param("s", $username);
    } elseif ($type == 'withdraw') {
        $stmt = $conn->prepare("SELECT * FROM withdraw WHERE username = ? ORDER BY id DESC LIMIT 30");
        $stmt->bind_param("s", $username);
    }
    
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        ${$type . 'Rows'} = $result->fetch_all(MYSQLI_ASSOC);
    }
} else {
    $result = $conn->query("SELECT * FROM register ORDER BY id DESC LIMIT 30");
    if ($result) {
        $registerRows = $result->fetch_all(MYSQLI_ASSOC);
    }

    $result = $conn->query("SELECT * FROM deposit ORDER BY id DESC LIMIT 30");
    if ($result) {
        $depositRows = $result->fetch_all(MYSQLI_ASSOC);
    }

    $result = $conn->query("SELECT * FROM withdraw ORDER BY id DESC LIMIT 30");
    if ($result) {
        $withdrawRows = $result->fetch_all(MYSQLI_ASSOC);
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Data</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            margin-top: 50px;
        }
        table {
            margin-top: 20px;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="text-center mb-4">View Data JKX</h2>
        <form method="POST" action="view.php" class="mb-4">
            <div class="form-group row">
                <label for="username" class="col-sm-2 col-form-label text-right">Username (optional):</label>
                <div class="col-sm-8">
                    <input type="text" id="username" name="username" class="form-control">
                </div>
            </div>
            <div class="form-group row">
                <div class="col-sm-10 offset-sm-2">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" id="register" name="type" value="register">
                        <label class="form-check-label" for="register">Register</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" id="deposit" name="type" value="deposit">
                        <label class="form-check-label" for="deposit">Deposit</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" id="withdraw" name="type" value="withdraw">
                        <label class="form-check-label" for="withdraw">Withdraw</label>
                    </div>
                </div>
            </div>
            <div class="form-group row">
                <div class="col-sm-10 offset-sm-2">
                    <button type="submit" class="btn btn-primary">View Data</button>
                </div>
            </div>
        </form>

        <?php if ($type == 'register' && !empty($registerRows)): ?>
            <h2 class="text-center mb-4">30 Latest Register Data</h2>
            <table class="table table-bordered table-hover table-striped text-center">
                <thead class="thead-dark">
                    <tr>
                        <?php foreach (array_keys($registerRows[0]) as $key): ?>
                            <th><?php echo htmlspecialchars($key); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registerRows as $row): ?>
                        <tr>
                            <?php foreach ($row as $value): ?>
                                <td><?php echo htmlspecialchars($value); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($type == 'deposit' && !empty($depositRows)): ?>
            <h2 class="text-center mb-4">30 Latest Deposit Data</h2>
            <table class="table table-bordered table-hover table-striped text-center">
                <thead class="thead-dark">
                    <tr>
                        <?php foreach (array_keys($depositRows[0]) as $key): ?>
                            <th><?php echo htmlspecialchars($key); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($depositRows as $row): ?>
                        <tr>
                            <?php foreach ($row as $value): ?>
                                <td><?php echo htmlspecialchars($value); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($type == 'withdraw' && !empty($withdrawRows)): ?>
            <h2 class="text-center mb-4">30 Latest Withdraw Data</h2>
            <table class="table table-bordered table-hover table-striped text-center">
                <thead class="thead-dark">
                    <tr>
                        <?php foreach (array_keys($withdrawRows[0]) as $key): ?>
                            <th><?php echo htmlspecialchars($key); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($withdrawRows as $row): ?>
                        <tr>
                            <?php foreach ($row as $value): ?>
                                <td><?php echo htmlspecialchars($value); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
