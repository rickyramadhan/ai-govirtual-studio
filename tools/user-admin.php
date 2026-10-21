<?php
declare(strict_types=1);

// Hanya untuk CLI: jangan pernah dijalankan lewat web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Kelola akun AI Guide Studio dari terminal.
 *
 *   php tools/user-admin.php list
 *   php tools/user-admin.php create <username> <admin|qa>
 *   php tools/user-admin.php passwd <username>
 *
 * Password dibaca dari env GUIDE_PASSWORD, atau diminta di terminal (tampil saat diketik).
 * Aturan password: minimal 10 karakter, mengandung huruf dan angka, bukan password bawaan lama.
 */

ob_start();
require_once dirname(__DIR__) . '/index.php';
ob_end_clean();

function usage(): never {
    fwrite(STDERR, "Pemakaian:\n  php tools/user-admin.php list\n  php tools/user-admin.php create <username> <admin|qa>\n  php tools/user-admin.php passwd <username>\n");
    exit(2);
}
function read_password(): string {
    $env = getenv('GUIDE_PASSWORD');
    if (is_string($env) && $env !== '') return $env;
    fwrite(STDOUT, 'Password baru (tampil saat diketik): ');
    return trim((string)fgets(STDIN));
}

$cmd = $argv[1] ?? '';
$pdo = db();

if ($cmd === 'list') {
    foreach ($pdo->query('SELECT username, role, created_at, password_hash FROM users ORDER BY id') as $r) {
        $warn = user_uses_default_password($r) ? '  <-- MASIH PASSWORD BAWAAN, segera ganti' : '';
        printf("%-20s %-6s %s%s\n", $r['username'], $r['role'], $r['created_at'], $warn);
    }
    exit(0);
}

if ($cmd === 'create') {
    $username = trim((string)($argv[2] ?? '')); $role = (string)($argv[3] ?? '');
    if ($username === '' || !in_array($role, ['admin', 'qa'], true)) usage();
    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) { fwrite(STDERR, "Username harus 3-32 karakter: huruf, angka, titik, strip, underscore.\n"); exit(1); }
    if (user_find($username)) { fwrite(STDERR, "Username sudah ada. Pakai 'passwd' untuk mengganti password.\n"); exit(1); }
    $pw = read_password();
    if ($err = password_policy_error($pw)) { fwrite(STDERR, $err . "\n"); exit(1); }
    $pdo->prepare('INSERT INTO users(username,password_hash,role,created_at) VALUES(?,?,?,?)')->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $role, date('c')]);
    echo "Akun '$username' ($role) dibuat.\n";
    exit(0);
}

if ($cmd === 'passwd') {
    $username = trim((string)($argv[2] ?? ''));
    if ($username === '') usage();
    if (!user_find($username)) { fwrite(STDERR, "User tidak ditemukan.\n"); exit(1); }
    $pw = read_password();
    if ($err = password_policy_error($pw)) { fwrite(STDERR, $err . "\n"); exit(1); }
    $pdo->prepare('UPDATE users SET password_hash=? WHERE username=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $username]);
    echo "Password '$username' diganti.\n";
    exit(0);
}

usage();
