<?php
// Hanya untuk CLI: jangan pernah dijalankan lewat web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * import_webview.php — parse semarang webview MySQL dump → storage/webview-knowledge.json
 * and seed tour knowledge.json entries from real webview data.
 *
 *   php import_webview.php            build master storage/webview-knowledge.json
 *   php import_webview.php --seed     replace webview:objinfo2:{id} placeholders in existing tours
 *   php import_webview.php --self     run assertions (counts, Sandi Buana + Lawang Sewu groups)
 *
 * Bridge: hub objinfo2:{id} sourceId == db_items.itmgroupid.
 *         smg_itm_oi.old_par_id == itmgroupid (Sandi Buana: 2229..2256).
 *         smg_itm_loc.old_id -> db_items.id; smg_itm_oi.itm_loc_id -> smg_itm_loc.id.
 * Master JSON indexed by: locations, objects (byGroupId), discoveries, categories, city.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

const SQL_PATH  = 'C:/xampp/htdocs/semarang/webview/semarang-260828.sql';
const OUT_PATH  = __DIR__ . '/storage/webview-knowledge.json';
const TOURS_DIR = __DIR__ . '/storage/tours';

/* ---------- SQL dump parser ---------- */

function scan_statement_end(string $content, int $start): int {
    $len = strlen($content); $i = $start; $inStr = false; $esc = false;
    while ($i < $len) {
        $c = $content[$i];
        if ($esc) { $esc = false; $i++; continue; }
        if ($inStr) {
            if ($c === '\\') { $esc = true; $i++; continue; }
            if ($c === "'") { $inStr = false; }
            $i++; continue;
        }
        if ($c === "'") { $inStr = true; $i++; continue; }
        if ($c === ';') return $i;
        $i++;
    }
    return -1;
}

function split_rows(string $body): array {
    $rows = []; $len = strlen($body); $i = 0;
    while ($i < $len) {
        while ($i < $len && ctype_space($body[$i])) $i++;
        if ($i >= $len || $body[$i] !== '(') break;
        $depth = 0; $inStr = false; $esc = false; $row = '';
        for (; $i < $len; $i++) {
            $c = $body[$i];
            if ($esc) { $row .= $c; $esc = false; continue; }
            if ($inStr) {
                if ($c === '\\') { $row .= $c; $esc = true; continue; }
                if ($c === "'") { $row .= $c; $inStr = false; continue; }
                $row .= $c; continue;
            }
            if ($c === "'") { $inStr = true; $row .= $c; continue; }
            if ($c === '(') { $depth++; $row .= $c; continue; }
            if ($c === ')') { $depth--; $row .= $c; if ($depth === 0) { $i++; break; } continue; }
            $row .= $c;
        }
        $rows[] = split_row_values($row);
        while ($i < $len && ctype_space($body[$i])) $i++;
        if ($i < $len && $body[$i] === ',') { $i++; continue; }
        break;
    }
    return $rows;
}

function split_row_values(string $row): array {
    $row = trim($row);
    if ($row !== '' && $row[0] === '(') $row = substr($row, 1, -1);
    $vals = []; $cur = ''; $inStr = false; $esc = false;
    $len = strlen($row);
    for ($i = 0; $i < $len; $i++) {
        $c = $row[$i];
        if ($esc) { $cur .= $c; $esc = false; continue; }
        if ($inStr) {
            if ($c === '\\') { $cur .= $c; $esc = true; continue; }
            if ($c === "'") { $cur .= $c; $inStr = false; continue; }
            $cur .= $c; continue;
        }
        if ($c === "'") { $inStr = true; $cur .= $c; continue; }
        if ($c === ',') { $vals[] = $cur; $cur = ''; continue; }
        $cur .= $c;
    }
    $vals[] = $cur;
    return $vals;
}

function unescape_sql(string $v): ?string {
    $v = trim($v);
    if (strcasecmp($v, 'NULL') === 0) return null;
    if ($v === '') return '';
    if ($v[0] === "'" && substr($v, -1) === "'") {
        $s = substr($v, 1, -1);
        $out = ''; $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $n = $s[++$i];
                switch ($n) {
                    case 'n': $out .= "\n"; break;
                    case 't': $out .= "\t"; break;
                    case 'r': $out .= "\r"; break;
                    case '0': $out .= "\0"; break;
                    case 'Z': $out .= "\x1a"; break;
                    case '\\': $out .= '\\'; break;
                    case "'": $out .= "'"; break;
                    case '"': $out .= '"'; break;
                    default: $out .= '\\' . $n;
                }
            } else {
                $out .= $c;
            }
        }
        return $out;
    }
    return $v;
}

function parse_dump(string $path): array {
    $content = file_get_contents($path);
    if ($content === false) { fwrite(STDERR, "ERR: cannot read $path\n"); exit(1); }
    $tables = []; $len = strlen($content); $pos = 0;
    $need = ['db_items', 'smg_itm_oi', 'smg_itm_loc', 'smg_itm_disc', 'smg_cat',
             'smg_lang', 'smg_ai_intent', 'sideinfo'];
    while (($pos = strpos($content, 'INSERT INTO `', $pos)) !== false) {
        $nameStart = $pos + strlen('INSERT INTO `');
        $endTick = strpos($content, '`', $nameStart);
        if ($endTick === false) break;
        $tbl = substr($content, $nameStart, $endTick - $nameStart);
        if (in_array($tbl, $need, true)) {
            $valPos = stripos($content, 'VALUES', $endTick);
            $end = scan_statement_end($content, $valPos + 6);
            if ($end < 0) break;
            $header = substr($content, $endTick + 1, $valPos - ($endTick + 1));
            if (!preg_match('/\(([^)]*)\)/s', $header, $hm)) { $pos = $end + 1; continue; }
            $cols = preg_split('/`\s*,\s*`/', trim($hm[1], '` '));
            $cols = array_map(static fn($c) => trim($c, '` '), $cols);
            $body = substr($content, $valPos + 6, $end - ($valPos + 6));
            foreach (split_rows($body) as $raw) {
                if (count($raw) !== count($cols)) continue;
                $row = [];
                foreach ($cols as $ci => $col) $row[$col] = unescape_sql($raw[$ci]);
                $tables[$tbl][] = $row;
            }
            $pos = $end + 1;
        } else {
            $pos = $endTick + 1;
        }
    }
    return $tables;
}

/* ---------- master JSON builders ---------- */

function clean_text(?string $s): string { return trim((string)$s); }

function build_master(array $t): array {
    $langMap = [1 => 'id', 2 => 'en', 3 => 'zh', 4 => 'ar', 5 => 'it'];
    foreach ($t['smg_lang'] ?? [] as $r) {
        if (isset($r['id'], $r['lang_iso'])) $langMap[(int)$r['id']] = (string)$r['lang_iso'];
    }
    $isoLang = static fn($id) => $langMap[(int)$id] ?? ('l' . $id);

    // db_items: id → {group, title, desc, link, lang}
    $items = [];
    foreach ($t['db_items'] ?? [] as $r) {
        $items[(int)$r['id']] = [
            'group' => isset($r['itmgroupid']) ? (int)$r['itmgroupid'] : 0,
            'title' => clean_text($r['itmtitle'] ?? ''),
            'desc'  => clean_text($r['itmdesc'] ?? $r['itminfo'] ?? ''),
            'link'  => clean_text($r['itmlink'] ?? ''),
            'lang'  => (string)($r['itmlang'] ?? 'id'),
        ];
    }
    $itemGroup = [];
    foreach ($items as $id => $it) if ($it['group'] > 0) $itemGroup[$id] = $it['group'];
    // group → first id-lang Indonesian item (fallback when smg content missing)
    $itemByGroup = [];
    foreach ($items as $id => $it) {
        if ($it['group'] > 0 && $it['lang'] === 'id') $itemByGroup[$it['group']] = $it;
    }

    // locations
    $locations = [];
    foreach ($t['smg_itm_loc'] ?? [] as $r) {
        $id = (int)$r['id'];
        $iso = $isoLang($r['itm_lang_id'] ?? 1);
        if ($iso === 'id' || !isset($locations[$id])) {
            $locations[$id] = [
                'id' => $id,
                'catId' => (int)($r['cat_id'] ?? 0),
                'name' => clean_text($r['itm_name'] ?? ''),
                'alias' => clean_text($r['itm_alias'] ?? ''),
                'desc' => clean_text($r['itm_desc'] ?? $r['itm_welcometext'] ?? ''),
                'welcome' => clean_text($r['itm_welcometext'] ?? ''),
                'link' => clean_text($r['itm_link'] ?? ''),
                'lat' => $r['geo_lat'] ?? null,
                'lng' => $r['geo_lang'] ?? null,
                'oldId' => isset($r['old_id']) ? (int)$r['old_id'] : 0,
                'translations' => [],
                'objectIds' => [],
                'discoveryIds' => [],
            ];
        }
        $locations[$id]['translations'][$iso] = [
            'name' => clean_text($r['itm_name'] ?? ''),
            'desc' => clean_text($r['itm_desc'] ?? $r['itm_welcometext'] ?? ''),
        ];
    }

    // objects — translation rows are separate ids sharing a groupId;
    // merge by groupId, base = Indonesian (id) row, primary fields never non-Indonesian.
    $objects = [];
    $objectsById = [];
    $objectsByGroup = [];
    foreach ($t['smg_itm_oi'] ?? [] as $r) {
        $id = (int)$r['id'];
        $iso = $isoLang($r['oi_lang_id'] ?? 1);
        $locId = (int)($r['itm_loc_id'] ?? 0);
        $oldId = isset($r['old_id']) ? (int)$r['old_id'] : 0;
        $oldPar = isset($r['old_par_id']) ? (int)$r['old_par_id'] : 0;
        $groupId = $oldPar > 0 ? $oldPar : ($itemGroup[$oldId] ?? 0);
        $base = [
            'id' => $id,
            'locId' => $locId,
            'name' => clean_text($r['oi_name'] ?? ''),
            'alias' => clean_text($r['oi_alias'] ?? ''),
            'desc' => clean_text($r['oi_desc'] ?? $r['oi_desc_2'] ?? ''),
            'link' => clean_text($r['oi_vo'] ?? ''),
            'groupId' => $groupId,
            'oldId' => $oldId,
            'oldParId' => $oldPar,
            'baseIso' => $iso,
            'tr' => ['name' => clean_text($r['oi_name'] ?? ''), 'desc' => clean_text($r['oi_desc'] ?? $r['oi_desc_2'] ?? '')],
        ];
        if (!isset($objectsByGroup[$groupId])) {
            $objectsByGroup[$groupId] = $base;
            $objectsByGroup[$groupId]['translations'] = [$iso => $base['tr']];
            $objectsById[$id] = $objectsByGroup[$groupId];
        } else {
            $objectsByGroup[$groupId]['translations'][$iso] = $base['tr'];
            if ($iso === 'id') { // replace primary name/desc with Indonesian when it arrives later
                $objectsByGroup[$groupId]['name'] = $base['name'];
                $objectsByGroup[$groupId]['alias'] = $base['alias'];
                $objectsByGroup[$groupId]['desc'] = $base['desc'];
                $objectsByGroup[$groupId]['locId'] = $locId;
                $objectsByGroup[$groupId]['id'] = $id;
                $objectsById[$id] = $objectsByGroup[$groupId];
            }
        }
    }
    foreach ($objectsByGroup as $g => $o) unset($objectsByGroup[$g]['baseIso'], $objectsByGroup[$g]['tr']);

    // discoveries — same group-based merge
    $discoveriesById = [];
    $discoveriesByGroup = [];
    foreach ($t['smg_itm_disc'] ?? [] as $r) {
        $id = (int)$r['id'];
        $iso = $isoLang($r['itm_lang_id'] ?? 1);
        $locId = (int)($r['itm_loc_id'] ?? 0);
        $oldId = isset($r['old_id']) ? (int)$r['old_id'] : 0;
        $oldPar = isset($r['old_par_id']) ? (int)$r['old_par_id'] : 0;
        $groupId = $oldPar > 0 ? $oldPar : ($itemGroup[$oldId] ?? 0);
        $base = [
            'id' => $id,
            'locId' => $locId,
            'catId' => (int)($r['cat_id'] ?? 0),
            'name' => clean_text($r['itm_name'] ?? ''),
            'alias' => clean_text($r['disc_alias'] ?? ''),
            'desc' => clean_text($r['itm_desc'] ?? ''),
            'link' => clean_text($r['itm_link'] ?? ''),
            'groupId' => $groupId,
            'oldId' => $oldId,
            'oldParId' => $oldPar,
            'baseIso' => $iso,
            'tr' => ['name' => clean_text($r['itm_name'] ?? ''), 'desc' => clean_text($r['itm_desc'] ?? '')],
        ];
        if (!isset($discoveriesByGroup[$groupId])) {
            $discoveriesByGroup[$groupId] = $base;
            $discoveriesByGroup[$groupId]['translations'] = [$iso => $base['tr']];
            $discoveriesById[$id] = $discoveriesByGroup[$groupId];
        } else {
            $discoveriesByGroup[$groupId]['translations'][$iso] = $base['tr'];
            if ($iso === 'id') {
                $discoveriesByGroup[$groupId]['name'] = $base['name'];
                $discoveriesByGroup[$groupId]['desc'] = $base['desc'];
                $discoveriesByGroup[$groupId]['locId'] = $locId;
                $discoveriesByGroup[$groupId]['id'] = $id;
                $discoveriesById[$id] = $discoveriesByGroup[$groupId];
            }
        }
    }
    foreach ($discoveriesByGroup as $g => $d) unset($discoveriesByGroup[$g]['baseIso'], $discoveriesByGroup[$g]['tr']);

    // attach object/discovery groupIds to locations
    foreach ($objectsByGroup as $obj) {
        if ($obj['locId'] > 0 && isset($locations[$obj['locId']])) {
            $locations[$obj['locId']]['objectIds'][] = $obj['id'];
            $locations[$obj['locId']]['groupIds'][] = $obj['groupId'];
        }
    }
    foreach ($discoveriesByGroup as $d) {
        if ($d['locId'] > 0 && isset($locations[$d['locId']])) {
            $locations[$d['locId']]['discoveryIds'][] = $d['id'];
            $locations[$d['locId']]['groupIds'][] = $d['groupId'];
        }
    }
    foreach ($locations as $lid => $loc) $locations[$lid]['groupIds'] = array_values(array_unique($loc['groupIds'] ?? []));
    $objects = array_values($objectsByGroup);
    $discoveries = array_values($discoveriesByGroup);

    // categories
    $categories = [];
    foreach ($t['smg_cat'] ?? [] as $r) {
        $categories[(int)$r['id']] = [
            'id' => (int)$r['id'],
            'parId' => isset($r['par_id']) ? (int)$r['par_id'] : 0,
            'name' => clean_text($r['cat_name'] ?? ''),
            'desc' => clean_text($r['cat_desc'] ?? ''),
            'langId' => (int)($r['cat_lang_id'] ?? 1),
        ];
    }

    // city info
    $city = null;
    foreach ($t['sideinfo'] ?? [] as $r) {
        $city = [
            'id' => (int)$r['id'],
            'name' => clean_text($r['judul'] ?? ''),
            'sejarah' => clean_text($r['sejarah'] ?? ''),
            'hariJadi' => clean_text($r['hari_jadi'] ?? ''),
            'luasWilayah' => clean_text($r['luas_wilayah'] ?? ''),
            'kecamatan' => clean_text($r['kecamatan'] ?? ''),
            'kelurahan' => clean_text($r['kelurahan'] ?? ''),
            'penduduk' => clean_text($r['penduduk'] ?? ''),
            'slogan' => clean_text($r['slogan'] ?? ''),
            'makananKhas' => clean_text($r['makanan_khas'] ?? ''),
            'gambar' => clean_text($r['gambar'] ?? ''),
        ];
    }

    return [
        'version' => 2,
        'updatedAt' => date('c'),
        'generatedFrom' => 'semarang-260828.sql',
        'counts' => [
            'locations' => count($locations),
            'objects' => count($objects),
            'discoveries' => count($discoveries),
            'categories' => count($categories),
            'dbItems' => count($items),
        ],
        'locations' => $locations,
        'objects' => $objects,
        'discoveries' => $discoveries,
        'categories' => $categories,
        'city' => $city,
        // indexed by groupId for fast lookup when seeding
        'objectsByGroup' => index_by_group($objects),
        'discoveriesByGroup' => index_by_group($discoveries),
        'itemByGroup' => $itemByGroup,
    ];
}

function index_by_group(array $items): array {
    $idx = [];
    foreach ($items as $it) {
        $g = $it['groupId'];
        if ($g <= 0) continue;
        // prefer Indonesian (base) row over translation-only rows so primary title/desc
        // never comes from a non-Indonesian language (e.g. Arabic المبنى A).
        if (!isset($idx[$g]) || (!isset($it['translations']['id']) && isset($idx[$g]['translations']['id']))) {
            $idx[$g] = $it;
        }
    }
    return $idx;
}

/* ---------- seed mode: replace placeholders in tour knowledge.json ---------- */

function seed_tour(string $dir, array $master): void {
    $path = $dir . '/knowledge.json';
    if (!file_exists($path)) { fwrite(STDERR, "  SKIP: no knowledge.json in $dir\n"); return; }
    $data = json_decode(file_get_contents($path), true);
    if (!$data || !isset($data['entries'])) { fwrite(STDERR, "  SKIP: invalid knowledge.json in $dir\n"); return; }

    $changed = false;
    $locName = '';
    // try to extract location name from seed:1 entry
    foreach ($data['entries'] as $e) {
        if (($e['id'] ?? '') === 'seed:1') { $locName = strtolower(trim($e['title'] ?? '')); break; }
    }

    // find best matching location by name
    $matchedLoc = null;
    foreach ($master['locations'] as $loc) {
        $ln = strtolower($loc['name']);
        // fuzzy match: loc name contains tour name or vice versa
        if ($locName !== '' && (strpos($ln, $locName) !== false || strpos($locName, $ln) !== false)) {
            $matchedLoc = $loc; break;
        }
    }
    if ($matchedLoc) {
        $locName = $matchedLoc['name'];
        fwrite(STDERR, "  matched location: {$matchedLoc['name']} (id={$matchedLoc['id']})\n");
    } else {
        fwrite(STDERR, "  no location match for tour name '$locName', will use groupId-only matching\n");
    }

    foreach ($data['entries'] as &$e) {
        $id = $e['id'] ?? '';
        // always replace webview:objinfo2 entries with fresh data from master
        if (!preg_match('/^webview:objinfo2:(\d+)$/', $id, $m)) continue;
        $oid = $m[1];
        $groupId = (int)$oid;

        // find matching object or discovery by groupId, then fallback to db_items
        $source = $master['objectsByGroup'][$groupId] ?? $master['discoveriesByGroup'][$groupId] ?? null;
        $item = $master['itemByGroup'][$groupId] ?? null;

        if (!$source && !$item) continue; // no data for this groupId

        $title = $source ? $source['name'] : ($item['title'] ?? '');
        $desc = $source ? $source['desc'] : ($item['desc'] ?? '');
        $translations = $source ? $source['translations'] : [];

        // build multilingual content
        $idTrans = $translations['id'] ?? ['name' => $title, 'desc' => $desc];
        $enTrans = $translations['en'] ?? ['name' => 'Object ' . $oid, 'desc' => 'Information for Object ' . $oid . '.'];

        $e['title'] = $idTrans['name'] ?: $title;
        $e['content'] = $idTrans['desc'] ?: $desc;
        $e['translations'] = [
            'id' => ['title' => $idTrans['name'] ?: $title, 'content' => $idTrans['desc'] ?: $desc],
            'en' => ['title' => $enTrans['name'] ?: 'Object ' . $oid, 'content' => $enTrans['desc'] ?: 'Information for Object ' . $oid . '.'],
        ];
        // add other available translations
        foreach ($translations as $lang => $tr) {
            if ($lang === 'id' || $lang === 'en') continue;
            $e['translations'][$lang] = ['title' => $tr['name'] ?: $title, 'content' => $tr['desc'] ?: $desc];
        }

        $e['mappingConfidence'] = 'WEBVIEW';
        $e['mappingEvidence'] = 'webview-knowledge import';
        $e['source'] = 'webview-import';
        $e['sourceFile'] = 'semarang-260828.sql';
        $changed = true;
        fwrite(STDERR, "  replaced: $id -> {$e['title']}\n");
    }
    unset($e);

    if ($changed) {
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fwrite(STDERR, "  UPDATED knowledge.json\n");
    } else {
        fwrite(STDERR, "  no changes\n");
    }
}

/* ---------- self-check assertions ---------- */

function run_self_check(array $master): void {
    $pass = 0; $fail = 0;
    $check = static function(string $label, bool $ok) use (&$pass, &$fail) {
        if ($ok) { $pass++; fwrite(STDERR, "  OK: $label\n"); }
        else { $fail++; fwrite(STDERR, "  FAIL: $label\n"); }
    };
    $c = $master['counts'];
    $check('locations >= 448', $c['locations'] >= 448);
    $check('objects >= 1000', $c['objects'] >= 1000);
    $check('discoveries >= 500', $c['discoveries'] >= 500);
    $check('categories >= 36', $c['categories'] >= 36);
    $check('dbItems >= 2400', $c['dbItems'] >= 2400);

    // Sandi Buana-specific: groupIds 2229-2256 should exist (2242 legitimately absent)
    $sandibuanaGroups = [2229, 2230, 2231, 2233, 2234, 2235, 2236, 2237, 2238, 2239, 2240, 2241, 2243, 2244, 2245, 2246, 2247, 2248, 2249, 2250, 2251, 2252, 2253, 2254, 2255, 2256];
    foreach ($sandibuanaGroups as $g) {
        $check("objectsByGroup[$g] exists", isset($master['objectsByGroup'][$g]) || isset($master['discoveriesByGroup'][$g]));
    }

    // Lawang Sewu: groupIds from tour objectIds
    $lawangSewu = [631, 632, 977, 978, 991, 992, 993, 994, 995, 1198, 1199, 1200, 1201];
    foreach ($lawangSewu as $g) {
        $check("objectsByGroup[$g] or itemByGroup[$g] exists", isset($master['objectsByGroup'][$g]) || isset($master['discoveriesByGroup'][$g]) || isset($master['itemByGroup'][$g]));
    }

    // city info
    $check('city info present', $master['city'] !== null);
    $check('city name is Semarang', $master['city'] && stripos($master['city']['name'] ?? '', 'semarang') !== false);

    fwrite(STDERR, "\n  $pass passed, $fail failed\n");
    if ($fail > 0) exit(1);
}

/* ---------- main ---------- */

$mode = $argv[1] ?? '';

if ($mode === '--self') {
    $master = json_decode(file_get_contents(OUT_PATH), true);
    if (!$master) { fwrite(STDERR, "Run `php import_webview.php` first to build master JSON.\n"); exit(1); }
    run_self_check($master);
    exit;
}

fwrite(STDERR, "Parsing SQL dump...\n");
$tables = parse_dump(SQL_PATH);
fwrite(STDERR, "  db_items: " . count($tables['db_items'] ?? []) . "\n");
fwrite(STDERR, "  smg_itm_loc: " . count($tables['smg_itm_loc'] ?? []) . "\n");
fwrite(STDERR, "  smg_itm_oi: " . count($tables['smg_itm_oi'] ?? []) . "\n");
fwrite(STDERR, "  smg_itm_disc: " . count($tables['smg_itm_disc'] ?? []) . "\n");
fwrite(STDERR, "  smg_cat: " . count($tables['smg_cat'] ?? []) . "\n");
fwrite(STDERR, "  sideinfo: " . count($tables['sideinfo'] ?? []) . "\n");

fwrite(STDERR, "Building master JSON...\n");
$master = build_master($tables);

if ($mode !== '--seed') {
    file_put_contents(OUT_PATH, json_encode($master, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fwrite(STDERR, "Wrote " . OUT_PATH . " (" . round(filesize(OUT_PATH) / 1024) . " KB)\n");
    run_self_check($master);
}

if ($mode === '--seed') {
    file_put_contents(OUT_PATH, json_encode($master, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $master = json_decode(file_get_contents(OUT_PATH), true);
    if (!$master) { fwrite(STDERR, "ERR: cannot read master JSON\n"); exit(1); }

    fwrite(STDERR, "\nSeeding tours...\n");
    $dirs = glob(TOURS_DIR . '/tour-*', GLOB_ONLYDIR);
    foreach ($dirs as $dir) {
        $slug = basename($dir);
        fwrite(STDERR, "\n[$slug]\n");
        seed_tour($dir, $master);
    }
}

fwrite(STDERR, "Done.\n");