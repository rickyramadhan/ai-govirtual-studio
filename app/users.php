<?php

declare(strict_types=1);

/**
 * Pengguna, peran, cakupan kota, dan jejak audit — semuanya hanya untuk Studio.
 *
 * Peran:  admin  = semua akses + kelola pengguna/API key
 *         editor = ubah konten & publikasi di kota yang menjadi cakupannya (tanpa cakupan = semua kota)
 *         qa     = hanya melihat dan menguji
 */

const USER_ROLES = ['admin' => 'Admin', 'editor' => 'Editor', 'qa' => 'QA (hanya lihat & uji)'];

function users_migrate(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('active', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN active INTEGER NOT NULL DEFAULT 1');
    if (!in_array('must_change', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN must_change INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_cities (user_id INTEGER NOT NULL, city_id INTEGER NOT NULL, PRIMARY KEY (user_id, city_id))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, username TEXT NOT NULL DEFAULT "", role TEXT NOT NULL DEFAULT "", action TEXT NOT NULL, tour_id INTEGER, city TEXT NOT NULL DEFAULT "", detail TEXT NOT NULL DEFAULT "", ip TEXT NOT NULL DEFAULT "")');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_at ON audit_log(at)');
}

/** Catat satu kejadian. Tidak pernah melempar error (log tidak boleh merusak aksi aslinya) dan tidak menyimpan nilai form/rahasia. */
function audit_write(string $action, array $ctx = []): void
{
    try {
        $u = current_user();
        db()->prepare('INSERT INTO audit_log(at,username,role,action,tour_id,city,detail,ip) VALUES(?,?,?,?,?,?,?,?)')->execute([
            date('c'), (string)($ctx['username'] ?? $u['username'] ?? ''), (string)($u['role'] ?? ''), $action,
            isset($ctx['tour_id']) ? (int)$ctx['tour_id'] : null, (string)($ctx['city'] ?? ''), mb_substr((string)($ctx['detail'] ?? ''), 0, 300), (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
    } catch (Throwable $e) { /* abaikan */ }
}

/** Kota yang boleh diakses pengguna saat ini: null = semua. Admin dan pengguna tanpa cakupan = semua. */
function user_scope(): ?array
{
    static $cache = [];
    $u = current_user();
    if (!$u || ($u['role'] ?? '') === 'admin') return null;
    $uid = (int)($u['id'] ?? 0);
    if (!array_key_exists($uid, $cache)) {
        $q = db()->prepare('SELECT city_id FROM user_cities WHERE user_id=?'); $q->execute([$uid]);
        $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $cache[$uid] = $ids ?: null;
    }
    return $cache[$uid];
}

function city_in_scope(int $cityId): bool
{
    $s = user_scope();
    return $s === null || in_array($cityId, $s, true);
}

function tour_in_scope(array $tour): bool
{
    $s = user_scope();
    if ($s === null) return true;
    return in_array((int)($tour['city_id'] ?? 0), $s, true);
}

function deny_page(string $msg): never
{
    http_response_code(403);
    layout('Akses ditolak', '<div class="card bad"><h2>Akses ditolak</h2><p>' . h($msg) . '</p><p><a class="btn" href="?">← Ke dashboard</a></p></div>');
    exit;
}

/** Gerbang cakupan: tour/kota di luar cakupan pengguna ditolak di semua rute. */
function enforce_scope(): void
{
    if (user_scope() === null) return;
    foreach ([$_GET['id'] ?? null, $_POST['id'] ?? null] as $rawId) {
        if ($rawId === null || (int)$rawId <= 0) continue;
        $t = get_tour_unscoped((int)$rawId);
        if ($t && !tour_in_scope($t)) deny_page('Tour ini berada di luar kota yang boleh Anda kelola.');
    }
    foreach ([$_GET['city'] ?? null, $_POST['city'] ?? null] as $slug) {
        if ($slug === null || $slug === '') continue;
        $c = city_row_by_slug(city_slugify((string)$slug));
        if ($c && !city_in_scope((int)$c['id'])) deny_page('Kota ini berada di luar cakupan Anda.');
    }
    foreach (['city_id'] as $k) if (isset($_POST[$k]) && (int)$_POST[$k] > 0 && !city_in_scope((int)$_POST[$k])) deny_page('Anda tidak punya akses ke kota yang dipilih.');
}

/** Ambil tour tanpa filter cakupan (untuk pengecekan cakupan itu sendiri). */
function get_tour_unscoped(int $id): ?array { return get_tour($id); }
function all_cities_unscoped(): array { return all_cities(false); }

function user_cities_of(int $uid): array
{
    $q = db()->prepare('SELECT city_id FROM user_cities WHERE user_id=?'); $q->execute([$uid]);
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

function users_set_cities(int $uid, array $cityIds): void
{
    db()->prepare('DELETE FROM user_cities WHERE user_id=?')->execute([$uid]);
    $ins = db()->prepare('INSERT OR IGNORE INTO user_cities(user_id,city_id) VALUES(?,?)');
    foreach (array_unique(array_map('intval', $cityIds)) as $cid) if (city_row_by_id($cid)) $ins->execute([$uid, $cid]);
}

/** Jumlah admin aktif — dipakai agar admin terakhir tidak bisa dinonaktifkan/diturunkan. */
function active_admin_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
}

/** POST pengguna: userCreate | userUpdate | userReset. Mengembalikan pesan sukses; melempar RuntimeException bila gagal. */
function process_user_action(string $action): string
{
    $me = current_user();
    if ($action === 'userCreate') {
        $username = trim((string)($_POST['username'] ?? '')); $role = (string)($_POST['role'] ?? 'editor'); $pw = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) throw new RuntimeException('Username 3–32 karakter: huruf, angka, titik, strip, underscore.');
        if (!isset(USER_ROLES[$role])) throw new RuntimeException('Peran tidak valid.');
        if (user_find($username)) throw new RuntimeException('Username sudah dipakai.');
        if ($e = password_policy_error($pw)) throw new RuntimeException($e);
        db()->prepare('INSERT INTO users(username,password_hash,role,created_at,must_change) VALUES(?,?,?,?,1)')->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $role, date('c')]);
        $uid = (int)db()->lastInsertId();
        users_set_cities($uid, (array)($_POST['cities'] ?? []));
        audit_write('userCreate', ['detail' => "$username ($role)"]);
        return "Pengguna $username dibuat. Dia akan diminta mengganti password saat login pertama.";
    }
    $uid = (int)($_POST['uid'] ?? 0);
    $row = db()->prepare('SELECT * FROM users WHERE id=?'); $row->execute([$uid]); $row = $row->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Pengguna tidak ditemukan.');
    if ($action === 'userReset') {
        $pw = (string)($_POST['password'] ?? '');
        if ($e = password_policy_error($pw)) throw new RuntimeException($e);
        db()->prepare('UPDATE users SET password_hash=?, must_change=1 WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
        audit_write('userReset', ['detail' => (string)$row['username']]);
        return 'Password ' . $row['username'] . ' direset; dia wajib menggantinya saat login berikutnya.';
    }
    // userUpdate
    $role = (string)($_POST['role'] ?? $row['role']); $active = !empty($_POST['active']) ? 1 : 0;
    if (!isset(USER_ROLES[$role])) throw new RuntimeException('Peran tidak valid.');
    $wasAdmin = $row['role'] === 'admin' && (int)$row['active'] === 1;
    if ($wasAdmin && ($role !== 'admin' || !$active) && active_admin_count() <= 1) throw new RuntimeException('Tidak bisa: ini admin aktif terakhir.');
    if ((int)($me['id'] ?? 0) === $uid && (!$active || $role !== 'admin')) throw new RuntimeException('Anda tidak bisa menonaktifkan atau menurunkan peran akun Anda sendiri.');
    db()->prepare('UPDATE users SET role=?, active=? WHERE id=?')->execute([$role, $active, $uid]);
    users_set_cities($uid, (array)($_POST['cities'] ?? []));
    audit_write('userUpdate', ['detail' => $row['username'] . " → $role" . ($active ? '' : ' (nonaktif)')]);
    return 'Pengguna ' . $row['username'] . ' diperbarui.';
}

function render_users_page(string $flash = '', string $err = ''): void
{
    $users = db()->query('SELECT * FROM users ORDER BY username COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    $cities = all_cities_unscoped();
    $cityBox = function (array $sel) use ($cities): string {
        $o = '<div style="display:flex;gap:10px;flex-wrap:wrap">';
        foreach ($cities as $c) $o .= '<label style="display:flex;gap:5px;align-items:center;font-size:12px;margin:0"><input type="checkbox" name="cities[]" value="' . (int)$c['id'] . '"' . (in_array((int)$c['id'], $sel, true) ? ' checked' : '') . '> ' . h($c['name']) . '</label>';
        return $o . '</div>';
    };
    $roleOpts = function (string $cur): string { $o = ''; foreach (USER_ROLES as $k => $l) $o .= '<option value="' . $k . '"' . ($k === $cur ? ' selected' : '') . '>' . h($l) . '</option>'; return $o; };
    $body = ($flash !== '' ? '<div class="card" style="border-color:var(--success)"><p class="ok">✓ ' . h($flash) . '</p></div>' : '') . ($err !== '' ? '<div class="card bad"><p>' . h($err) . '</p></div>' : '');
    $body .= '<div class="card"><h2 style="margin-top:0">Pengguna</h2><p class="muted">Admin mengelola semuanya. Editor hanya bisa mengubah dan mempublikasikan di <b>kota yang dicentang</b> (tanpa centang = semua kota). QA hanya melihat dan menguji.</p>';
    foreach ($users as $u) {
        $uid = (int)$u['id']; $sel = user_cities_of($uid);
        $body .= '<details class="card" style="margin-top:10px"' . ((int)$u['active'] === 0 ? ' style="opacity:.6"' : '') . '><summary style="cursor:pointer"><b>' . h((string)$u['username']) . '</b> <span class="badge">' . h(USER_ROLES[$u['role']] ?? $u['role']) . '</span>'
            . ((int)$u['active'] === 0 ? ' <span class="bad">nonaktif</span>' : '') . ($u['role'] !== 'admin' && $sel ? ' <span class="muted" style="font-size:12px">· ' . count($sel) . ' kota</span>' : '') . '</summary>'
            . '<form method="post" action="?a=userUpdate" style="display:grid;gap:8px;margin-top:10px"><input type="hidden" name="uid" value="' . $uid . '">'
            . '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><select name="role">' . $roleOpts((string)$u['role']) . '</select><label style="display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="active" value="1"' . ((int)$u['active'] === 1 ? ' checked' : '') . '> Aktif</label></div>'
            . '<div><div class="muted" style="font-size:12px;margin-bottom:4px">Cakupan kota (untuk Editor/QA)</div>' . $cityBox($sel) . '</div>'
            . '<div><button class="btn btn-primary btn-sm" type="submit">Simpan</button></div></form>'
            . '<form method="post" action="?a=userReset" style="display:flex;gap:8px;margin-top:10px;align-items:center;flex-wrap:wrap" onsubmit="return confirm(\'Reset password pengguna ini?\')"><input type="hidden" name="uid" value="' . $uid . '"><input type="password" name="password" placeholder="Password sementara baru" autocomplete="new-password" required minlength="10" style="max-width:260px"><button class="btn btn-ghost btn-sm" type="submit">Reset password</button></form></details>';
    }
    $body .= '</div><div class="card"><h3 style="margin-top:0">Tambah pengguna</h3><form method="post" action="?a=userCreate" style="display:grid;gap:8px;max-width:520px">'
        . '<input name="username" placeholder="username" required autocomplete="off" pattern="[A-Za-z0-9._-]{3,32}"><input type="password" name="password" placeholder="Password sementara (min. 10, huruf+angka)" required minlength="10" autocomplete="new-password">'
        . '<select name="role">' . $roleOpts('editor') . '</select><div><div class="muted" style="font-size:12px;margin-bottom:4px">Cakupan kota</div>' . $cityBox([]) . '</div>'
        . '<div><button class="btn btn-primary" type="submit">Buat pengguna</button></div></form></div>';
    layout('Pengguna', $body);
}

function render_audit_page(): void
{
    $per = 50; $page = max(1, (int)($_GET['page'] ?? 1));
    $fu = trim((string)($_GET['user'] ?? '')); $fa = trim((string)($_GET['act'] ?? ''));
    $where = []; $args = [];
    if ($fu !== '') { $where[] = 'username=?'; $args[] = $fu; }
    if ($fa !== '') { $where[] = 'action LIKE ?'; $args[] = '%' . $fa . '%'; }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $tot = db()->prepare("SELECT COUNT(*) FROM audit_log $w"); $tot->execute($args); $total = (int)$tot->fetchColumn();
    $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
    $q = db()->prepare("SELECT a.*, t.name AS tour_name FROM audit_log a LEFT JOIN tours t ON t.id=a.tour_id $w ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $q->execute($args);
    $users = db()->query('SELECT DISTINCT username FROM audit_log WHERE username<>"" ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);
    $uo = '<option value="">Semua pengguna</option>'; foreach ($users as $u) $uo .= '<option value="' . h((string)$u) . '"' . ($u === $fu ? ' selected' : '') . '>' . h((string)$u) . '</option>';
    $body = '<div class="card"><h2 style="margin-top:0">Jejak Audit</h2><p class="muted">Siapa melakukan apa dan kapan. Hanya nama aksi, tour, dan kota yang dicatat — tidak ada isi form atau password.</p>'
        . '<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px"><input type="hidden" name="a" value="audit"><select name="user" onchange="this.form.submit()">' . $uo . '</select><input type="search" name="act" value="' . h($fa) . '" placeholder="Cari aksi, mis. deploy"><button class="btn btn-ghost" type="submit">Cari</button></form>'
        . '<table style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr style="text-align:left;color:var(--text-muted)"><th style="padding:6px">Waktu</th><th>Pengguna</th><th>Aksi</th><th>Tour / kota</th><th>Rincian</th></tr></thead><tbody>';
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $when = DateTime::createFromFormat(DATE_ATOM, (string)$r['at']);
        $body .= '<tr style="border-top:1px solid var(--border)"><td style="padding:6px;white-space:nowrap">' . ($when ? h($when->format('d M H:i:s')) : h((string)$r['at'])) . '</td><td>' . h((string)$r['username']) . ' <span class="muted" style="font-size:11px">' . h((string)$r['role']) . '</span></td><td><code>' . h((string)$r['action']) . '</code></td><td>' . h((string)($r['tour_name'] ?? '') . ($r['city'] !== '' ? ' · ' . $r['city'] : '')) . '</td><td class="muted" style="font-size:12px">' . h((string)$r['detail']) . '</td></tr>';
    }
    $body .= '</tbody></table>';
    if ($pages > 1) {
        $base = ['a' => 'audit', 'user' => $fu, 'act' => $fa];
        $body .= '<nav class="pager" aria-label="Halaman">';
        for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++) $body .= '<a class="pager-i' . ($p === $page ? ' is-on' : '') . '" href="?' . h(http_build_query(array_filter($base) + ['page' => $p])) . '">' . $p . '</a>';
        $body .= '<span class="muted" style="margin-left:auto;font-size:12px">' . $total . ' catatan</span></nav>';
    } else $body .= '<p class="muted" style="font-size:12px;margin:10px 0 0">' . $total . ' catatan</p>';
    layout('Jejak Audit', $body . '</div>');
}
