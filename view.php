<?php
/**
 * Data viewer (admin-only).
 *
 * Shows register / deposit / withdraw records with pagination.
 * Protected by HTTP Basic auth (see hd_require_viewer_auth()).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

// Authenticate BEFORE touching the database or rendering anything.
hd_require_viewer_auth();

require_once __DIR__ . '/dbconnect.php';

const HD_ALLOWED_TABLES = ['register', 'deposit', 'withdraw'];
const HD_PER_PAGE = 30;

$type     = $_POST['type']     ?? ($_GET['type'] ?? null);
$username = trim((string) ($_POST['username'] ?? ($_GET['username'] ?? '')));
$page     = max(1, (int) ($_POST['page'] ?? ($_GET['page'] ?? 1)));
$offset   = ($page - 1) * HD_PER_PAGE;

if ($type !== null && !in_array($type, HD_ALLOWED_TABLES, true)) {
    $type = null;
}

$rowsByType  = ['register' => [], 'deposit' => [], 'withdraw' => []];
$totalByType = ['register' => 0,  'deposit' => 0,  'withdraw' => 0];

/** Fetch a page of rows + total count for one (whitelisted) table. */
function hd_fetch_page(mysqli $conn, string $table, ?string $username, int $limit, int $offset): array
{
    if (!in_array($table, HD_ALLOWED_TABLES, true)) {
        return [[], 0];
    }

    if ($username !== null && $username !== '') {
        $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE username = ?");
        $countStmt->bind_param('s', $username);
        $countStmt->execute();
        $total = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);

        $stmt = $conn->prepare(
            "SELECT * FROM {$table} WHERE username = ? ORDER BY id DESC LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('sii', $username, $limit, $offset);
    } else {
        $total = (int) ($conn->query("SELECT COUNT(*) AS c FROM {$table}")->fetch_assoc()['c'] ?? 0);

        $stmt = $conn->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('ii', $limit, $offset);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return [$rows, $total];
}

try {
    if ($type !== null) {
        [$rowsByType[$type], $totalByType[$type]] =
            hd_fetch_page($conn, $type, $username, HD_PER_PAGE, $offset);
    } else {
        // No type selected: show the latest page of each table.
        foreach (HD_ALLOWED_TABLES as $t) {
            [$rowsByType[$t], $totalByType[$t]] =
                hd_fetch_page($conn, $t, $username !== '' ? $username : null, HD_PER_PAGE, $offset);
        }
    }
} catch (mysqli_sql_exception $e) {
    error_log('hookdata view error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Internal server error';
    exit;
} finally {
    $conn->close();
}

/** Render one table + its pagination. */
function hd_render_table(string $title, string $type, array $rows, int $total, int $page, string $username): void
{
    if (empty($rows)) {
        return;
    }
    $totalPages = max(1, (int) ceil($total / HD_PER_PAGE));
    echo '<h2 class="text-center mb-4">' . htmlspecialchars($title) . '</h2>';
    echo '<table class="table table-bordered table-hover table-striped text-center"><thead class="thead-dark"><tr>';
    foreach (array_keys($rows[0]) as $key) {
        echo '<th>' . htmlspecialchars((string) $key) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $value) {
            echo '<td>' . htmlspecialchars((string) $value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';

    // Pagination controls
    echo '<nav class="d-flex justify-content-between align-items-center mb-5">';
    $q = function (int $p) use ($type, $username) {
        return 'view.php?type=' . urlencode($type)
            . '&page=' . $p
            . ($username !== '' ? '&username=' . urlencode($username) : '');
    };
    if ($page > 1) {
        echo '<a class="btn btn-outline-secondary" href="' . htmlspecialchars($q($page - 1)) . '">&laquo; Prev</a>';
    } else {
        echo '<span></span>';
    }
    echo '<span class="text-muted">Page ' . $page . ' / ' . $totalPages
        . ' (' . $total . ' rows)</span>';
    if ($page < $totalPages) {
        echo '<a class="btn btn-outline-secondary" href="' . htmlspecialchars($q($page + 1)) . '">Next &raquo;</a>';
    } else {
        echo '<span></span>';
    }
    echo '</nav>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Data</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 50px; }
        table { margin-top: 20px; width: 100%; }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="text-center mb-4">View Data JKX</h2>
        <form method="GET" action="view.php" class="mb-4">
            <div class="form-group row">
                <label for="username" class="col-sm-2 col-form-label text-right">Username (optional):</label>
                <div class="col-sm-8">
                    <input type="text" id="username" name="username" class="form-control"
                           value="<?php echo htmlspecialchars($username); ?>">
                </div>
            </div>
            <div class="form-group row">
                <div class="col-sm-10 offset-sm-2">
                    <?php foreach (HD_ALLOWED_TABLES as $t): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" id="<?php echo $t; ?>"
                                   name="type" value="<?php echo $t; ?>"
                                   <?php echo $type === $t ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="<?php echo $t; ?>">
                                <?php echo ucfirst($t); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group row">
                <div class="col-sm-10 offset-sm-2">
                    <button type="submit" class="btn btn-primary">View Data</button>
                </div>
            </div>
        </form>

        <?php
        if ($type !== null) {
            hd_render_table(ucfirst($type) . ' Data', $type, $rowsByType[$type], $totalByType[$type], $page, $username);
        } else {
            foreach (HD_ALLOWED_TABLES as $t) {
                hd_render_table('Latest ' . ucfirst($t) . ' Data', $t, $rowsByType[$t], $totalByType[$t], $page, $username);
            }
        }
        ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
