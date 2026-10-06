<?php
/**
 * hookdata — admin dashboard (admin-only).
 *
 * Modern Bootstrap 5 UI: summary cards, tabs per event type, search/date
 * filters, sticky-header tables, masking of sensitive fields, pagination,
 * and a dark/light theme toggle. No build step (CDN assets only).
 *
 * Security preserved: HTTP Basic auth, prepared statements, htmlspecialchars.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

// Authenticate BEFORE touching the database or rendering anything.
hd_require_viewer_auth();

require_once __DIR__ . '/dbconnect.php';

const HD_ALLOWED_TABLES = ['register', 'deposit', 'withdraw'];
const HD_PER_PAGE = 25;

// Columns whose values are masked by default (show last 4 chars only).
const HD_SENSITIVE   = ['tel', 'accountNumber', 'bankNo'];
// Columns formatted as numbers.
const HD_NUMERIC_COLS = ['value', 'bonus', 'beforeValue', 'afterValue', 'topUp'];
// Columns formatted as date/time.
const HD_DATE_COLS    = ['createDate', 'updateDate', 'dateBank'];
// Columns rendered as badges.
const HD_BADGE_COLS   = ['bankName', 'type', 'actionName', 'prefix'];

// ---- Request --------------------------------------------------------------
$type = $_GET['type'] ?? 'register';
if (!in_array($type, HD_ALLOWED_TABLES, true)) {
    $type = 'register';
}
$username = trim((string) ($_GET['username'] ?? ''));
$date     = trim((string) ($_GET['date'] ?? ''));          // YYYY-MM-DD
$reveal   = isset($_GET['reveal']) && $_GET['reveal'] === '1';
$theme    = (($_GET['theme'] ?? '') === 'dark') ? 'dark' : 'light'; // initial theme (JS/localStorage can override)
$page     = max(1, (int) ($_GET['page'] ?? 1));
$offset   = ($page - 1) * HD_PER_PAGE;

// Only accept a valid ISO date; ignore anything else.
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = '';
}

/**
 * Build WHERE clause + bound params from the active filters.
 * Returns [sqlWhere, typesString, paramsArray].
 */
function hd_build_filters(string $username, string $date): array
{
    $where  = [];
    $types  = '';
    $params = [];
    if ($username !== '') {
        $where[]  = 'username = ?';
        $types   .= 's';
        $params[] = $username;
    }
    if ($date !== '') {
        $where[]  = 'createDate LIKE ?';
        $types   .= 's';
        $params[] = $date . '%';
    }
    $sql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    return [$sql, $types, $params];
}

/** Bind a dynamic params array to a statement (by reference). */
function hd_bind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') {
        return;
    }
    $refs = [];
    foreach ($params as $k => $v) {
        $refs[$k] = &$params[$k];
    }
    $stmt->bind_param($types, ...$refs);
}

/** Fetch a page of rows + total count for one whitelisted table. */
function hd_fetch_page(mysqli $conn, string $table, string $username, string $date, int $limit, int $offset): array
{
    if (!in_array($table, HD_ALLOWED_TABLES, true)) {
        return [[], 0];
    }
    [$where, $types, $params] = hd_build_filters($username, $date);

    // Count
    $cStmt = $conn->prepare("SELECT COUNT(*) AS c FROM {$table}{$where}");
    hd_bind($cStmt, $types, $params);
    $cStmt->execute();
    $total = (int) ($cStmt->get_result()->fetch_assoc()['c'] ?? 0);

    // Page
    $pStmt = $conn->prepare("SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT ? OFFSET ?");
    hd_bind($pStmt, $types . 'ii', array_merge($params, [$limit, $offset]));
    $pStmt->execute();
    $rows = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return [$rows, $total];
}

/** Overall summary stats for the cards (global, independent of filters). */
function hd_stats(mysqli $conn): array
{
    $one = fn(string $sql) => (int) ($conn->query($sql)->fetch_assoc()['v'] ?? 0);
    return [
        'register_count' => $one('SELECT COUNT(*) AS v FROM register'),
        'deposit_count'  => $one('SELECT COUNT(*) AS v FROM deposit'),
        'deposit_sum'    => $one('SELECT COALESCE(SUM(value),0) AS v FROM deposit'),
        'deposit_bonus'  => $one('SELECT COALESCE(SUM(bonus),0) AS v FROM deposit'),
        'withdraw_count' => $one('SELECT COUNT(*) AS v FROM withdraw'),
        'withdraw_sum'   => $one('SELECT COALESCE(SUM(value),0) AS v FROM withdraw'),
    ];
}

try {
    $stats = hd_stats($conn);
    [$rows, $total] = hd_fetch_page($conn, $type, $username, $date, HD_PER_PAGE, $offset);
} catch (mysqli_sql_exception $e) {
    error_log('hookdata view error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Internal server error';
    exit;
} finally {
    $conn->close();
}

$totalPages = max(1, (int) ceil($total / HD_PER_PAGE));
if ($page > $totalPages) {
    $page = $totalPages;
}

// ---- Presentation helpers -------------------------------------------------

/** Mask a value, keeping only the last 4 characters visible. */
function hd_mask(string $v): string
{
    // Sensitive fields are ASCII (phone / account numbers); byte-safe is fine
    // and avoids a hard dependency on the mbstring extension.
    $len = strlen($v);
    if ($len <= 4) {
        return str_repeat('•', max($len, 1));
    }
    return str_repeat('•', min($len - 4, 6)) . substr($v, -4);
}

/** Format a single cell for display (always escapes). */
function hd_cell(string $col, $val, bool $reveal): string
{
    $s = (string) $val;

    if (in_array($col, HD_SENSITIVE, true) && !$reveal && $s !== '') {
        return '<span class="text-nowrap font-monospace" title="masked">'
            . htmlspecialchars(hd_mask($s)) . '</span>';
    }
    if (in_array($col, HD_NUMERIC_COLS, true) && is_numeric($s)) {
        return '<span class="num">' . htmlspecialchars(number_format((float) $s)) . '</span>';
    }
    if (in_array($col, HD_BADGE_COLS, true) && $s !== '') {
        return '<span class="badge rounded-pill badge-soft">' . htmlspecialchars($s) . '</span>';
    }
    if (in_array($col, HD_DATE_COLS, true) && $s !== '') {
        return '<span class="text-nowrap text-body-secondary small">' . htmlspecialchars($s) . '</span>';
    }
    return htmlspecialchars($s);
}

/** Preserve current query params while overriding some. */
function hd_url(array $overrides): string
{
    $base = ['type' => $_GET['type'] ?? 'register'];
    foreach (['username', 'date', 'reveal', 'page'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') {
            $base[$k] = $_GET[$k];
        }
    }
    $q = array_merge($base, $overrides);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return 'view.php?' . http_build_query($q);
}

function hd_nf(int $n): string { return number_format($n); }
?>
<!doctype html>
<html lang="th" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>hookdata · Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&family=IBM+Plex+Sans+Thai:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root { --hd-radius: 14px; }
        body {
            font-family: "Noto Sans Thai", "IBM Plex Sans Thai", system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: var(--bs-body-bg);
        }
        [data-bs-theme="light"] body { background: #f4f6fb; }
        [data-bs-theme="dark"]  body { background: #0f1420; }
        .navbar-brand { font-weight: 700; letter-spacing: .3px; }
        .stat-card {
            border: 1px solid var(--bs-border-color-translucent);
            border-radius: var(--hd-radius);
            overflow: hidden;
            transition: transform .12s ease, box-shadow .12s ease;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1.2rem rgba(0,0,0,.08); }
        .stat-icon {
            width: 46px; height: 46px; border-radius: 12px;
            display: grid; place-items: center; font-size: 1.35rem;
        }
        .stat-value { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
        .stat-label { font-size: .8rem; letter-spacing: .4px; text-transform: uppercase; }
        .card, .nav-tabs, .table { --bs-border-color: var(--bs-border-color-translucent); }
        .panel {
            border: 1px solid var(--bs-border-color-translucent);
            border-radius: var(--hd-radius);
            background: var(--bs-body-bg);
        }
        .table-wrap { max-height: 65vh; overflow: auto; border-radius: 0 0 var(--hd-radius) var(--hd-radius); }
        .table thead th {
            position: sticky; top: 0; z-index: 2;
            background: var(--bs-tertiary-bg);
            white-space: nowrap; font-size: .78rem;
            text-transform: uppercase; letter-spacing: .3px;
        }
        .table td { vertical-align: middle; font-size: .9rem; }
        .num { font-variant-numeric: tabular-nums; }
        .badge-soft {
            background: color-mix(in srgb, var(--bs-primary) 14%, transparent);
            color: var(--bs-primary); font-weight: 500;
        }
        .nav-tabs .nav-link { border: 0; color: var(--bs-secondary-color); font-weight: 500; }
        .nav-tabs .nav-link.active {
            color: var(--bs-primary); background: transparent;
            border-bottom: 3px solid var(--bs-primary);
        }
        .empty-state { padding: 3.5rem 1rem; text-align: center; color: var(--bs-secondary-color); }
        .empty-state i { font-size: 2.6rem; opacity: .5; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand bg-body shadow-sm sticky-top">
    <div class="container-fluid px-4">
        <span class="navbar-brand d-flex align-items-center gap-2">
            <i class="bi bi-diagram-3-fill text-primary"></i> hookdata <span class="text-body-secondary fw-normal fs-6">dashboard</span>
        </span>
        <div class="ms-auto d-flex align-items-center gap-2">
            <a class="btn btn-sm btn-outline-secondary <?php echo $reveal ? 'active' : ''; ?>"
               href="<?php echo htmlspecialchars(hd_url(['reveal' => $reveal ? '0' : '1'])); ?>"
               title="แสดง/ซ่อนข้อมูลเต็ม">
                <i class="bi bi-<?php echo $reveal ? 'eye-slash' : 'eye'; ?>"></i>
                <span class="d-none d-sm-inline"><?php echo $reveal ? 'ซ่อนข้อมูล' : 'แสดงเต็ม'; ?></span>
            </a>
            <button id="themeToggle" class="btn btn-sm btn-outline-secondary" title="สลับธีม">
                <i class="bi bi-moon-stars"></i>
            </button>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card bg-body p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon text-bg-primary bg-opacity-10 text-primary"><i class="bi bi-person-plus-fill"></i></div>
                    <div>
                        <div class="stat-label text-body-secondary">สมัคร / Register</div>
                        <div class="stat-value"><?php echo hd_nf($stats['register_count']); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card bg-body p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-arrow-down-circle-fill"></i></div>
                    <div>
                        <div class="stat-label text-body-secondary">ฝาก / Deposit</div>
                        <div class="stat-value"><?php echo hd_nf($stats['deposit_count']); ?></div>
                        <div class="small text-body-secondary num">฿<?php echo hd_nf($stats['deposit_sum']); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card bg-body p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-arrow-up-circle-fill"></i></div>
                    <div>
                        <div class="stat-label text-body-secondary">ถอน / Withdraw</div>
                        <div class="stat-value"><?php echo hd_nf($stats['withdraw_count']); ?></div>
                        <div class="small text-body-secondary num">฿<?php echo hd_nf($stats['withdraw_sum']); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card bg-body p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-gift-fill"></i></div>
                    <div>
                        <div class="stat-label text-body-secondary">โบนัสฝากรวม / Bonus</div>
                        <div class="stat-value num">฿<?php echo hd_nf($stats['deposit_bonus']); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" action="view.php" class="panel p-3 mb-3">
        <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
        <?php if ($reveal): ?><input type="hidden" name="reveal" value="1"><?php endif; ?>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label small text-body-secondary mb-1">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="username" class="form-control" placeholder="ค้นหาด้วย username"
                           value="<?php echo htmlspecialchars($username); ?>">
                </div>
            </div>
            <div class="col-8 col-md-4">
                <label class="form-label small text-body-secondary mb-1">วันที่ (createDate)</label>
                <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($date); ?>">
            </div>
            <div class="col-4 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i> กรอง</button>
                <a href="view.php?type=<?php echo htmlspecialchars($type); ?>" class="btn btn-outline-secondary" title="ล้างตัวกรอง"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </form>

    <!-- Tabs + table -->
    <div class="panel">
        <ul class="nav nav-tabs px-3 pt-2">
            <?php
            $tabs = [
                'register' => ['สมัคร', 'bi-person-plus'],
                'deposit'  => ['ฝาก', 'bi-arrow-down-circle'],
                'withdraw' => ['ถอน', 'bi-arrow-up-circle'],
            ];
            foreach ($tabs as $t => [$label, $icon]):
                $count = $stats[$t . '_count'];
            ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $t === $type ? 'active' : ''; ?>"
                       href="<?php echo htmlspecialchars(hd_url(['type' => $t, 'page' => '1'])); ?>">
                        <i class="bi <?php echo $icon; ?>"></i> <?php echo $label; ?>
                        <span class="badge text-bg-secondary rounded-pill ms-1"><?php echo hd_nf($count); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if (empty($rows)): ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <p class="mt-3 mb-1 fw-medium">ไม่พบข้อมูล</p>
                <p class="small mb-0">ลองปรับตัวกรองหรือล้างเงื่อนไขการค้นหา</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <?php foreach (array_keys($rows[0]) as $key): ?>
                                <th><?php echo htmlspecialchars((string) $key); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php foreach ($row as $col => $value): ?>
                                    <td><?php echo hd_cell((string) $col, $value, $reveal); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                <span class="small text-body-secondary">
                    แสดง <?php echo hd_nf(($page - 1) * HD_PER_PAGE + 1); ?>–<?php echo hd_nf(min($page * HD_PER_PAGE, $total)); ?>
                    จาก <?php echo hd_nf($total); ?> รายการ
                </span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars(hd_url(['page' => $page - 1])); ?>"><i class="bi bi-chevron-left"></i></a>
                        </li>
                        <?php
                        $start = max(1, $page - 2);
                        $end   = min($totalPages, $page + 2);
                        if ($start > 1) echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars(hd_url(['page' => 1])) . '">1</a></li>' . ($start > 2 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '');
                        for ($p = $start; $p <= $end; $p++):
                        ?>
                            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(hd_url(['page' => $p])); ?>"><?php echo $p; ?></a>
                            </li>
                        <?php endfor;
                        if ($end < $totalPages) echo ($end < $totalPages - 1 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '') . '<li class="page-item"><a class="page-link" href="' . htmlspecialchars(hd_url(['page' => $totalPages])) . '">' . $totalPages . '</a></li>';
                        ?>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars(hd_url(['page' => $page + 1])); ?>"><i class="bi bi-chevron-right"></i></a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>

    <p class="text-center text-body-secondary small mt-4 mb-0">
        <i class="bi bi-shield-lock"></i> ข้อมูลส่วนตัวถูกปิดบังโดยค่าเริ่มต้น · กด"แสดงเต็ม" เพื่อดูค่าจริง
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Theme toggle with persistence.
    (function () {
        const html = document.documentElement;
        const btn = document.getElementById('themeToggle');
        const saved = localStorage.getItem('hd-theme');
        if (saved) html.setAttribute('data-bs-theme', saved);
        const sync = () => {
            const dark = html.getAttribute('data-bs-theme') === 'dark';
            btn.innerHTML = '<i class="bi bi-' + (dark ? 'sun' : 'moon-stars') + '"></i>';
        };
        sync();
        btn.addEventListener('click', () => {
            const dark = html.getAttribute('data-bs-theme') === 'dark';
            const next = dark ? 'light' : 'dark';
            html.setAttribute('data-bs-theme', next);
            localStorage.setItem('hd-theme', next);
            sync();
        });
    })();
</script>
</body>
</html>
