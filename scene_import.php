<?php
declare(strict_types=1);

// Bulk Scene editor: download template (.xlsx berisi thumbnail panorama) -> edit Excel/CSV -> upload update scene-identity.json.
// Reuse qa_import.php matrix parsers (qa_csv_matrix / qa_xlsx_matrix) untuk baca file.

if (!function_exists('scene_thumb_file')) {

if (!defined('SCENE_CSV_HEADERS')) define('SCENE_CSV_HEADERS', ['Scene ID', 'Nama Panorama (displayName)', 'Place Name', 'Short Description', 'Deskripsi lengkap', 'Highlights (satu per baris)', 'Suggested Questions (JSON atau satu per baris)']);

function scene_thumb_file(array $tour, array $parsed, string $scene): string {
    $thumbPrefix = 'ht_node_thumb_1_';
    $imgDir = rtrim((string)$tour['export_path'], '/\\').'/images';
    if (is_dir($imgDir)) { foreach (scandir($imgDir) as $f) if (preg_match('/^(ht_node_thumb_\d+_)/', $f, $m)) { $thumbPrefix = $m[1]; break; } }
    $imgPath = '';
    foreach (($parsed['hotspots'] ?? []) as $h) {
        if (($h['target'] ?? '') !== $scene || empty($h['scene'])) continue;
        $p = $imgDir.'/'.$thumbPrefix.$h['scene'].'_'.$h['id'].'.webp';
        if (is_file($p)) { $imgPath = $p; break; }
    }
    if (!$imgPath) {
        foreach (($parsed['hotspots'] ?? []) as $h) {
            if ($h['scene'] !== $scene) continue;
            $p = $imgDir.'/'.$thumbPrefix.$scene.'_'.$h['id'].'.webp';
            if (is_file($p)) { $imgPath = $p; break; }
        }
    }
    if (!$imgPath && is_dir($imgDir)) {
        foreach (scandir($imgDir) as $f) if (str_starts_with($f, $thumbPrefix.$scene.'_')) { $imgPath = $imgDir.'/'.$f; break; }
    }
    return $imgPath;
}

function scene_thumb_png(string $imgPath): string {
    if ($imgPath === '' || !is_file($imgPath)) return '';
    $im = @imagecreatefromwebp($imgPath);
    if ($im === false) return '';
    $w = imagesx($im); $h = imagesy($im);
    if ($w > 320) {
        $nh = (int)round($h * 320 / $w);
        $dst = imagecreatetruecolor(320, $nh);
        imagecopyresampled($dst, $im, 0, 0, 0, 0, 320, $nh, $w, $h);
        imagedestroy($im); $im = $dst;
    }
    ob_start(); imagepng($im); $png = (string)ob_get_clean();
    imagedestroy($im);
    return $png;
}

/** Baris data scene + thumbnail PNG per scene id (untuk ditanam ke .xlsx). */
function scene_rows_for_export(array $tour, array $parsed, array $si): array {
    $rows = []; $thumbs = [];
    foreach (($parsed['scenes'] ?? []) as $scene) {
        $sid = (string)$scene['id'];
        $display = (string)($scene['displayName'] ?? $sid);
        $sc = $si['scenes'][$sid] ?? null;
        if ($sc) $display = (string)($sc['displayName'] ?? $display);
        $place = $sc ? (string)($sc['placeName'] ?? $display) : $display;
        $sug = [];
        foreach (($sc['suggestedQuestions'] ?? []) as $s) {
            if (is_string($s)) $sug[] = ['q' => $s, 'mode' => 'ai', 'answer' => ''];
            else $sug[] = ['q' => (string)($s['q'] ?? ''), 'mode' => (string)($s['mode'] ?? 'ai'), 'answer' => (string)($s['answer'] ?? '')];
        }
        $rows[] = [
            $sid,
            $display,
            $place,
            (string)($sc['shortDescription'] ?? ''),
            (string)($sc['description'] ?? ''),
            implode("\n", $sc['highlights'] ?? []),
            $sug ? json_encode($sug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ];
        $thumbs[$sid] = scene_thumb_png(scene_thumb_file($tour, $parsed, $sid));
    }
    return [$rows, $thumbs];
}

/* ---------------- XLSX writer (pure PHP, gambar panorama ditanam) ---------------- */

function scene_xlsx_esc(string $s): string {
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function scene_xlsx_col(int $i): string {
    $s = '';
    do { $s = chr(65 + ($i % 26)).$s; $i = intdiv($i, 26) - 1; } while ($i >= 0);
    return $s;
}
function scene_xlsx_zip(array $files): string {
    $data = ''; $cd = ''; $offset = 0;
    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $comp = gzdeflate($content, 6);
        $clen = strlen($comp); $ulen = strlen($content); $nh = strlen($name);
        $data .= "PK\x03\x04" . pack('v', 20) . pack('v', 0x0800) . pack('v', 8) . pack('V', 0) . pack('V', $crc) . pack('V', $clen) . pack('V', $ulen) . pack('v', $nh) . pack('v', 0) . $name . $comp;
        $cd .= "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0x0800) . pack('v', 8) . pack('V', 0) . pack('V', $crc) . pack('V', $clen) . pack('V', $ulen) . pack('v', $nh) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('V', 0) . pack('V', $offset) . $name;
        $offset += 30 + $nh + $clen;
    }
    $cdEnd = "PK\x05\x06" . pack('v', 0) . pack('v', 0) . pack('v', count($files)) . pack('v', count($files)) . pack('V', strlen($cd)) . pack('V', $offset) . pack('v', 0);
    return $data . $cd . $cdEnd;
}

function scene_build_xlsx(array $headers, array $rows, array $thumbs): string {
    $widths = [16, 30, 30, 42, 60, 40, 60];
    $cols = '<cols>';
    foreach ($widths as $i => $w) $cols .= '<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'" customWidth="1"/>';
    $cols .= '</cols>';
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'.$cols.'<sheetData>';
    $sheet .= '<row r="1" ht="18" customHeight="1">';
    foreach ($headers as $ci => $cell) if ((string)$cell !== '') $sheet .= '<c r="'.scene_xlsx_col($ci).'1" t="inlineStr"><is><t xml:space="preserve">'.scene_xlsx_esc((string)$cell).'</t></is></c>';
    $sheet .= '</row>';

    $media = []; $mediaIdx = 1; $relIdx = 2; $rowNum = 2; $hasImages = false;
    $draw = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $drawRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

    foreach ($rows as $r) {
        $sid = (string)$r[0];
        $png = $thumbs[$sid] ?? '';
        $ht = $png !== '' ? 80 : 18;
        // Kolom A = thumbnail panorama (tidak ada teks). Data mulai dari kolom B supaya sejajar header.
        $sheet .= '<row r="'.$rowNum.'" ht="'.$ht.'" customHeight="1">';
        foreach ($r as $ci => $cell) if ((string)$cell !== '') $sheet .= '<c r="'.scene_xlsx_col($ci + 1).$rowNum.'" t="inlineStr"><is><t xml:space="preserve">'.scene_xlsx_esc((string)$cell).'</t></is></c>';
        $sheet .= '</row>';
        if ($png !== '') {
            $hasImages = true;
            $mediaName = 'image'.$mediaIdx.'.png';
            $media[$mediaName] = $png;
            $cw = 300; $ch = 180;
            $draw .= '<xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.($rowNum-1).'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
                . '<xdr:ext cx="'.(int)round($cw*9525).'" cy="'.(int)round($ch*9525).'"/>'
                . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="'.$mediaIdx.'" name="Pic'.$mediaIdx.'"/><xdr:cNvPicPr/></xdr:nvPicPr>'
                . '<xdr:blipFill><a:blip r:embed="rId'.$relIdx.'"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.(int)round($cw*9525).'" cy="'.(int)round($ch*9525).'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
                . '<xdr:clientData/></xdr:oneCellAnchor>';
            $drawRels .= '<Relationship Id="rId'.$relIdx.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/'.$mediaName.'"/>';
            $mediaIdx++; $relIdx++;
        }
        $rowNum++;
    }
    if ($hasImages) {
        $draw .= '</xdr:wsDr>';
        $drawRels .= '</Relationships>';
        $sheet .= '</sheetData><drawing r:id="rId1"/></worksheet>';
    } else {
        $sheet .= '</sheetData></worksheet>';
    }

    $files = [];
    $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . ($hasImages ? '<Default Extension="png" ContentType="image/png"/>' : '')
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . ($hasImages ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '')
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Scenes" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $files['xl/worksheets/sheet1.xml'] = $sheet;
    if ($hasImages) {
        $files['xl/worksheets/_rels/sheet1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>';
        $files['xl/drawings/drawing1.xml'] = $draw;
        $files['xl/drawings/_rels/drawing1.xml.rels'] = $drawRels;
        foreach ($media as $n => $png) $files['xl/media/'.$n] = $png;
    }
    $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>';
    return scene_xlsx_zip($files);
}

/* ---------------- CSV export (tanpa gambar) ---------------- */

function scene_export_csv(array $tour, array $rows): string {
    $headers = array_merge(['Thumbnail URL (buka di browser)'], SCENE_CSV_HEADERS);
    $out = fopen('php://temp', 'r+');
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        $sid = (string)$r[0];
        fputcsv($out, array_merge(['?a=thumb&id=' . (int)$tour['id'] . '&scene=' . urlencode($sid)], $r));
    }
    rewind($out);
    $csv = (string)stream_get_contents($out);
    fclose($out);
    return $csv;
}

/* ---------------- Import parser (CSV + XLSX) ---------------- */

/** Deteksi kolom header template scene. */
function scene_import_columns(array $raw): ?array {
    for ($i = 0; $i < min(count($raw), 12); $i++) {
        $roles = [];
        foreach ($raw[$i] as $j => $cell) {
            $role = scene_header_role((string)$cell);
            if ($role !== null && !isset($roles[$role])) $roles[$role] = $j;
        }
        if (!isset($roles['sceneid'], $roles['display'])) continue;
        $roles['_headerRow'] = $i;
        return $roles;
    }
    return null;
}

function scene_header_role(string $s): ?string {
    $s = strtolower(trim($s));
    $s = (string)preg_replace('/\([^)]*\)/', ' ', $s);
    $s = (string)preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    $s = trim((string)preg_replace('/\s+/', ' ', $s));
    if ($s === '') return null;
    if (str_contains($s, 'scene id') || str_contains($s, 'id scene') || str_contains($s, 'panorama id')) return 'sceneid';
    if (str_contains($s, 'display name') || str_contains($s, 'nama panorama') || str_contains($s, 'nama')) return 'display';
    if (str_contains($s, 'place name') || str_contains($s, 'nama tempat')) return 'place';
    if (str_contains($s, 'short description') || str_contains($s, 'deskripsi singkat')) return 'shortdesc';
    if (str_contains($s, 'deskripsi lengkap') || str_contains($s, 'description')) return 'description';
    if (str_contains($s, 'highlights') || str_contains($s, 'sorot') || str_contains($s, 'poin')) return 'highlights';
    if (str_contains($s, 'suggested') || str_contains($s, 'question') || str_contains($s, 'pertanyaan')) return 'suggested';
    return null;
}

/** Parse matrix -> assoc dengan key kolom header. */
function scene_parse_rows(array $raw, array $columns): array {
    $out = [];
    for ($k = $columns['_headerRow'] + 1; $k < count($raw); $k++) {
        $row = $raw[$k];
        $sid = trim((string)($row[$columns['sceneid']] ?? ''));
        if ($sid === '') continue;
        $out[] = [
            'sceneid' => $sid,
            'display' => trim((string)($row[$columns['display']] ?? '')),
            'place' => trim((string)($row[$columns['place'] ?? 999] ?? '')),
            'shortdesc' => trim((string)($row[$columns['shortdesc'] ?? 999] ?? '')),
            'description' => trim((string)($row[$columns['description'] ?? 999] ?? '')),
            'highlights' => trim((string)($row[$columns['highlights'] ?? 999] ?? '')),
            'suggested' => trim((string)($row[$columns['suggested'] ?? 999] ?? '')),
        ];
    }
    return $out;
}

/** Apply import results ke scene-identity array. */
function scene_apply_import(array $si, array $imported): array {
    $count = 0;
    foreach ($imported as $r) {
        $sid = $r['sceneid'];
        if (!isset($si['scenes'][$sid])) continue;
        $sc = &$si['scenes'][$sid];
        if ($r['display'] !== '') { $sc['displayName'] = $r['display']; if (empty($sc['rawName'])) $sc['rawName'] = $r['display']; }
        if ($r['place'] !== '') { $sc['placeName'] = $r['place']; $sc['placeId'] = slugify($r['place']); }
        if ($r['shortdesc'] !== '') $sc['shortDescription'] = $r['shortdesc'];
        if ($r['description'] !== '') $sc['description'] = $r['description'];
        if ($r['highlights'] !== '') $sc['highlights'] = array_values(array_filter(array_map('trim', explode("\n", str_replace(',', "\n", $r['highlights'])))));
        if ($r['suggested'] !== '') {
            $parsed = json_decode($r['suggested'], true);
            if (is_array($parsed)) $sc['suggestedQuestions'] = $parsed;
        }
        if (!isset($sc['aiHints']) || !is_array($sc['aiHints'])) $sc['aiHints'] = [];
        $sc['aiHints']['currentLocation'] = "Anda sekarang berada di ".($sc['placeName'] ?? $sc['displayName'] ?? $sid).".";
        $sc['confidence'] = 'NEEDS_REVIEW';
        $sc['evidence'] = 'Bulk import via CSV/XLSX';
        $count++;
    }
    $si['generatedAt'] = date('Y-m-d');
    return [$si, $count];
}

/** Parse file template scene (.xlsx/.csv) -> daftar baris scene. Throws RuntimeException bila tidak valid. */
function scene_parse_file(string $path, ?string $hintName = null): array {
    if (!is_file($path)) throw new RuntimeException('File tidak ditemukan.');
    $ext = strtolower(pathinfo($hintName !== null ? $hintName : $path, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') $raw = qa_csv_matrix($path);
    elseif ($ext === 'xlsx') $raw = qa_xlsx_matrix($path);
    else throw new RuntimeException('Format tidak didukung: .' . $ext . ' (dukung .xlsx atau .csv).');
    $columns = scene_import_columns($raw);
    if ($columns === null) throw new RuntimeException('Header template tidak dikenali. Download template dulu lalu edit — jangan ubah baris pertama.');
    $rows = scene_parse_rows($raw, $columns);
    if ($rows === []) throw new RuntimeException('Tidak ada baris scene valid (Scene ID kosong semua).');
    return $rows;
}

} // function_exists guard
