<?php
/**
 * hookdata — Monthly Deposit Leaderboard (admin-only).
 *
 * Ranks users by total deposits (SUM of deposit.value) within a selected
 * month (Asia/Bangkok). Phone number is resolved from the register table by
 * username (deposit has no tel column). Shows rank, phone, total deposit and
 * a small deposit count; top 3 are highlighted. Includes a CSV export.
 *
 * Security preserved: HTTP Basic auth, prepared statements, htmlspecialchars.
 * Date filtering uses an index-friendly half-open range
 * (createDate >= first-day AND createDate < first-day-of-next-month).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

// Authenticate BEFORE touching the database or emitting anything.
hd_require_viewer_auth();

require_once __DIR__ . '/dbconnect.php';

date_default_timezone_set('Asia/Bangkok');

const HD_ALLOWED_PER = [10, 25, 50, 100];

// ---- Request --------------------------------------------------------------
$month = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$reveal = isset($_GET['reveal']) && $_GET['reveal'] === '1';
$theme  = (($_GET['theme'] ?? '') === 'dark') ? 'dark' : 'light';
$export = ($_GET['export'] ?? '') === 'csv';
$per    = (int) ($_GET['per'] ?? 25);
if (!in_array($per, HD_ALLOWED_PER, true)) {
    $per = 25;
}
$page   = max(1, (int) ($_GET['page'] ?? 1));

// Half-open month range (index-friendly; works lexicographically on ISO strings).
$start = $month . '-01 00:00:00';
$next  = date('Y-m-d H:i:s', strtotime($month . '-01 00:00:00 +1 month'));

/**
 * Return a prepared statement for the month's deposit ranking.
 * tel is taken from the user's most recent register row (by id).
 * $limit < 0 means "no limit" (used for CSV export).
 */
function hd_leaderboard_stmt(mysqli $conn, string $start, string $next, int $limit, int $offset): mysqli_stmt
{
    $sql = "SELECT d.username,
                   SUM(d.value)   AS total_deposit,
                   COUNT(*)       AS deposit_count,
                   r.tel          AS tel
            FROM deposit d
            LEFT JOIN register r
                   ON r.username = d.username
                  AND r.id = (SELECT MAX(r2.id) FROM register r2 WHERE r2.username = d.username)
            WHERE d.createDate >= ? AND d.createDate < ?
            GROUP BY d.username, r.tel
            ORDER BY total_deposit DESC, d.username ASC";

    if ($limit >= 0) {
        $sql .= ' LIMIT ? OFFSET ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssii', $start, $next, $limit, $offset);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $start, $next);
    }
    $stmt->execute();
    return $stmt;
}

/** Month-level summary: distinct depositing users + total deposit value. */
function hd_month_summary(mysqli $conn, string $start, string $next): array
{
    $stmt = $conn->prepare(
        'SELECT COUNT(DISTINCT username) AS users, COALESCE(SUM(value),0) AS total
         FROM deposit WHERE createDate >= ? AND createDate < ?'
    );
    $stmt->bind_param('ss', $start, $next);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return ['users' => (int) ($row['users'] ?? 0), 'total' => (int) ($row['total'] ?? 0)];
}

// ---- CSV export (must run before any HTML) --------------------------------
if ($export) {
    try {
        $stmt = hd_leaderboard_stmt($conn, $start, $next, -1, 0);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $e) {
        error_log('hookdata leaderboard export error: ' . $e->getMessage());
        http_response_code(500);
        echo 'Internal server error';
        exit;
    } finally {
        $conn->close();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="deposit-leaderboard-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Thai correctly
    fputcsv($out, ['rank', 'tel', 'total_deposit', 'deposit_count']);
    $rank = 0;
    foreach ($rows as $r) {
        $rank++;
        // Full (unmasked) tel in the export — it is behind auth.
        fputcsv($out, [$rank, (string) ($r['tel'] ?? ''), (int) $r['total_deposit'], (int) $r['deposit_count']]);
    }
    fclose($out);
    exit;
}

// ---- Normal page ----------------------------------------------------------
try {
    $summary = hd_month_summary($conn, $start, $next);
    $total   = $summary['users'];
    $totalPages = max(1, (int) ceil($total / $per));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $per;

    $stmt = hd_leaderboard_stmt($conn, $start, $next, $per, $offset);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    error_log('hookdata leaderboard error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Internal server error';
    exit;
} finally {
    $conn->close();
}

// ---- Presentation helpers -------------------------------------------------

/** Mask a value keeping only the last 4 chars (ASCII phone => byte-safe). */
function hd_mask(string $v): string
{
    $len = strlen($v);
    if ($len <= 4) {
        return str_repeat('•', max($len, 1));
    }
    return str_repeat('•', min($len - 4, 6)) . substr($v, -4);
}

/** Preserve current query params while overriding some. */
function hd_url(array $overrides): string
{
    $base = [];
    foreach (['month', 'reveal', 'per', 'page'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') {
            $base[$k] = $_GET[$k];
        }
    }
    $q = array_merge($base, $overrides);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return 'view.php?' . http_build_query($q);
}

function hd_nf(int $n): string { return number_format($n); }

/** Human month label, e.g. "ตุลาคม 2026". */
function hd_month_label(string $month): string
{
    $months = [1=>'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
               'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    [$y, $m] = array_map('intval', explode('-', $month));
    return ($months[$m] ?? $month) . ' ' . $y;
}

$avg = $total > 0 ? (int) round($summary['total'] / $total) : 0;
?>
<!doctype html>
<html lang="th" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>hookdata · Leaderboard ยอดฝาก</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&family=IBM+Plex+Sans+Thai:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root { --hd-radius: 14px; }
        body {
            font-family: "Noto Sans Thai", "IBM Plex Sans Thai", system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
        }
        [data-bs-theme="light"] body { background: #f4f6fb; }
        [data-bs-theme="dark"]  body { background: #0f1420; }
        .navbar-brand { font-weight: 700; letter-spacing: .3px; }
        .stat-card {
            border: 1px solid var(--bs-border-color-translucent);
            border-radius: var(--hd-radius);
        }
        .stat-icon { width: 46px; height: 46px; border-radius: 12px; display: grid; place-items: center; font-size: 1.35rem; }
        .stat-value { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
        .stat-label { font-size: .78rem; letter-spacing: .4px; text-transform: uppercase; }
        .panel { border: 1px solid var(--bs-border-color-translucent); border-radius: var(--hd-radius); background: var(--bs-body-bg); }
        .table-wrap { max-height: 62vh; overflow: auto; border-radius: 0 0 var(--hd-radius) var(--hd-radius); }
        .table thead th {
            position: sticky; top: 0; z-index: 2; background: var(--bs-tertiary-bg);
            white-space: nowrap; font-size: .78rem; text-transform: uppercase; letter-spacing: .3px;
        }
        .table td { vertical-align: middle; }
        .num { font-variant-numeric: tabular-nums; }
        .rank-badge { width: 34px; height: 34px; border-radius: 50%; display: inline-grid; place-items: center; font-weight: 700; }
        .rank-1 { background: linear-gradient(135deg,#ffd700,#f0b000); color:#4d3b00; }
        .rank-2 { background: linear-gradient(135deg,#d7dce3,#aeb6c2); color:#2b303a; }
        .rank-3 { background: linear-gradient(135deg,#e0a06a,#c57b3e); color:#3a240f; }
        tr.top-row td { background: color-mix(in srgb, var(--bs-warning) 8%, transparent); }
        .amount { font-weight: 700; font-size: 1.02rem; }
        .empty-state { padding: 3.5rem 1rem; text-align: center; color: var(--bs-secondary-color); }
        .empty-state i { font-size: 2.6rem; opacity: .5; }
        .tel { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand bg-body shadow-sm sticky-top">
    <div class="container-fluid px-4">
        <span class="navbar-brand d-flex align-items-center gap-2">
            <i class="bi bi-trophy-fill text-warning"></i> hookdata
            <span class="text-body-secondary fw-normal fs-6">อันดับยอดฝากรายเดือน</span>
        </span>
        <div class="ms-auto d-flex align-items-center gap-2">
            <a class="btn btn-sm btn-success"
               href="<?php echo htmlspecialchars(hd_url(['export' => 'csv'])); ?>">
                <i class="bi bi-filetype-csv"></i> <span class="d-none d-sm-inline">ดาวน์โหลด CSV</span>
            </a>
            <a class="btn btn-sm btn-outline-secondary <?php echo $reveal ? 'active' : ''; ?>"
               href="<?php echo htmlspecialchars(hd_url(['reveal' => $reveal ? '0' : '1'])); ?>"
               title="แสดง/ซ่อนเบอร์โทรเต็ม">
                <i class="bi bi-<?php echo $reveal ? 'eye-slash' : 'eye'; ?>"></i>
                <span class="d-none d-sm-inline"><?php echo $reveal ? 'ซ่อนเบอร์' : 'แสดงเบอร์'; ?></span>
            </a>
            <button id="themeToggle" class="btn btn-sm btn-outline-secondary" title="สลับธีม">
                <i class="bi bi-moon-stars"></i>
            </button>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">

    <!-- Controls -->
    <form method="GET" action="view.php" class="panel p-3 mb-4">
        <?php if ($reveal): ?><input type="hidden" name="reveal" value="1"><?php endif; ?>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small text-body-secondary mb-1">เดือน / Month</label>
                <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($month); ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-body-secondary mb-1">แสดงต่อหน้า / Top-N</label>
                <select name="per" class="form-select">
                    <?php foreach (HD_ALLOWED_PER as $opt): ?>
                        <option value="<?php echo $opt; ?>" <?php echo $opt === $per ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2 d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-repeat"></i> ดูอันดับ</button>
            </div>
            <div class="col-12 col-md-3 text-md-end">
                <span class="text-body-secondary small">เดือน</span>
                <div class="fw-semibold"><?php echo htmlspecialchars(hd_month_label($month)); ?></div>
            </div>
        </div>
    </form>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="stat-card bg-body p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-cash-stack"></i></div>
                    <div>
                        <div class="stat-label text-body-secondary">ยอดฝากรวมทั้งเดือน</div>
                        <div class="stat-value num">฿<?php echo hd_nf($summary['total']); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stat-card bg-body p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-people-fill"></i></div>
                    <div>
                        <div class="stat-label text-body-secondary">จำนวนผู้ฝาก</div>
                        <div class="stat-value num"><?php echo hd_nf($total); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stat-card bg-body p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-graph-up"></i></div>
                    <div>
                        <div class="stat-label text-body-secondary">เฉลี่ยต่อคน</div>
                        <div class="stat-value num">฿<?php echo hd_nf($avg); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Leaderboard -->
    <div class="panel">
        <div class="d-flex align-items-center justify-content-between px-3 pt-3">
            <h5 class="mb-0"><i class="bi bi-trophy text-warning"></i> อันดับยอดฝาก · <?php echo htmlspecialchars(hd_month_label($month)); ?></h5>
        </div>

        <?php if (empty($rows)): ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <p class="mt-3 mb-1 fw-medium">ไม่มีรายการฝากในเดือนนี้</p>
                <p class="small mb-0">ลองเลือกเดือนอื่น</p>
            </div>
        <?php else: ?>
            <div class="table-wrap mt-2">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:72px">อันดับ</th>
                            <th>เบอร์โทร (tel)</th>
                            <th class="text-end">ยอดฝากรวม</th>
                            <th class="text-end" style="width:110px">ครั้ง</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $i => $row):
                            $rank = $offset + $i + 1;
                            $tel  = (string) ($row['tel'] ?? '');
                            $telDisplay = $tel === '' ? '—' : ($reveal ? $tel : hd_mask($tel));
                            $isTop = $rank <= 3;
                        ?>
                            <tr class="<?php echo $isTop ? 'top-row' : ''; ?>">
                                <td>
                                    <?php if ($isTop): ?>
                                        <span class="rank-badge rank-<?php echo $rank; ?>"><?php echo $rank; ?></span>
                                    <?php else: ?>
                                        <span class="text-body-secondary ps-2"><?php echo $rank; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="tel"><?php echo htmlspecialchars($telDisplay); ?></td>
                                <td class="text-end amount num">฿<?php echo hd_nf((int) $row['total_deposit']); ?></td>
                                <td class="text-end"><span class="badge rounded-pill text-bg-secondary num"><?php echo hd_nf((int) $row['deposit_count']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                <span class="small text-body-secondary">
                    แสดงอันดับ <?php echo hd_nf($offset + 1); ?>–<?php echo hd_nf(min($offset + $per, $total)); ?>
                    จาก <?php echo hd_nf($total); ?> คน
                </span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars(hd_url(['page' => $page - 1])); ?>"><i class="bi bi-chevron-left"></i></a>
                        </li>
                        <?php
                        $s = max(1, $page - 2);
                        $e = min($totalPages, $page + 2);
                        if ($s > 1) echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars(hd_url(['page' => 1])) . '">1</a></li>' . ($s > 2 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '');
                        for ($p = $s; $p <= $e; $p++):
                        ?>
                            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars(hd_url(['page' => $p])); ?>"><?php echo $p; ?></a>
                            </li>
                        <?php endfor;
                        if ($e < $totalPages) echo ($e < $totalPages - 1 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '') . '<li class="page-item"><a class="page-link" href="' . htmlspecialchars(hd_url(['page' => $totalPages])) . '">' . $totalPages . '</a></li>';
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
        <i class="bi bi-shield-lock"></i> เบอร์โทรถูกปิดบังโดยค่าเริ่มต้น · กด "แสดงเบอร์" เพื่อดูเต็ม · ไฟล์ CSV มีเบอร์เต็ม
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
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
