<?php

declare(strict_types=1);

/**
 * Skema database bertahap. Idempotent: aman dijalankan berulang, dan dijalankan hanya SEKALI per request
 * (dipanggil dari db()). Jangan memanggil db() di sini — pakai $pdo yang diberikan.
 */

function db_migrate(PDO $pdo): void
{
    $now = date('c');

    // --- Kota/wilayah. Satu tabel; "province" teks bebas untuk pengelompokan tanpa mengunci hierarki. ---
    $pdo->exec('CREATE TABLE IF NOT EXISTS cities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        province TEXT NOT NULL DEFAULT "",
        categories TEXT NOT NULL DEFAULT "",
        theme TEXT NOT NULL DEFAULT "",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $tourCols = array_column($pdo->query('PRAGMA table_info(tours)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('city_id', $tourCols, true)) $pdo->exec('ALTER TABLE tours ADD COLUMN city_id INTEGER');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tours_city ON tours(city_id)');

    // Kota yang sudah punya folder storage/cities/{slug} (knowledge kota) masuk ke tabel, sekali saja.
    $done = $pdo->query("SELECT svalue FROM settings WHERE skey='schema.cities_imported'")->fetchColumn();
    if ($done === false) {
        $dir = APP_ROOT . '/storage/cities';
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $slug) {
                if ($slug === '.' || $slug === '..' || !is_dir($dir . '/' . $slug)) continue;
                $meta = json_decode((string)@file_get_contents($dir . '/' . $slug . '/meta.json'), true);
                $name = is_array($meta) && !empty($meta['name']) ? (string)$meta['name'] : ucwords(str_replace('-', ' ', $slug));
                $pdo->prepare('INSERT OR IGNORE INTO cities(slug,name,created_at,updated_at) VALUES(?,?,?,?)')->execute([$slug, $name, $now, $now]);
            }
        }
        $pdo->prepare("INSERT OR REPLACE INTO settings(skey,svalue,updated_at) VALUES('schema.cities_imported','1',?)")->execute([$now]);
    }

    // Tour lama (hanya teks "location") dihubungkan ke kota. Murah: hanya jalan bila ada tour tanpa city_id.
    $missing = $pdo->query('SELECT id, location FROM tours WHERE city_id IS NULL')->fetchAll(PDO::FETCH_ASSOC);
    if ($missing) {
        $find = $pdo->prepare('SELECT id FROM cities WHERE slug=?');
        $ins = $pdo->prepare('INSERT INTO cities(slug,name,created_at,updated_at) VALUES(?,?,?,?)');
        $upd = $pdo->prepare('UPDATE tours SET city_id=? WHERE id=?');
        foreach ($missing as $t) {
            $name = trim((string)$t['location']);
            if ($name === '') $name = 'Tanpa Kota';
            $slug = city_slugify($name);
            $find->execute([$slug]);
            $cid = $find->fetchColumn();
            if ($cid === false) { $ins->execute([$slug, $name, $now, $now]); $cid = (int)$pdo->lastInsertId(); }
            $upd->execute([(int)$cid, (int)$t['id']]);
        }
    }

    // --- Cache: hash file besar (skin.js/pano.xml) dan statistik tour, supaya dashboard tidak membaca ulang semuanya. ---
    $pdo->exec('CREATE TABLE IF NOT EXISTS file_hash_cache (path TEXT PRIMARY KEY, mtime INTEGER NOT NULL, size INTEGER NOT NULL, md5 TEXT NOT NULL)');
    if (function_exists('jobs_migrate')) jobs_migrate($pdo);
    if (function_exists('users_migrate')) users_migrate($pdo);
    $pdo->exec('CREATE TABLE IF NOT EXISTS tour_stats_cache (tour_id INTEGER PRIMARY KEY, sig TEXT NOT NULL, json TEXT NOT NULL)');
}
