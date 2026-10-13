<?php
declare(strict_types=1);

// Q&A importer: Excel (.xlsx) atau CSV -> items curated-qa.json.
// xlsx dibaca langsung (ZIP central directory + XML) tanpa extension tambahan.

if (!function_exists('qa_parse_file_to_items')) {

/** Parse file -> daftar item Q&A. Throws RuntimeException bila format/isi tidak valid. */
function qa_parse_file_to_items(string $path, ?string $hintName = null): array {
    if (!is_file($path)) throw new RuntimeException('File tidak ditemukan.');
    $ext = strtolower(pathinfo($hintName !== null ? $hintName : $path, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') {
        $raw = qa_csv_matrix($path);
    } elseif ($ext === 'xlsx') {
        $raw = qa_xlsx_matrix($path);
    } else {
        throw new RuntimeException('Format tidak didukung: .' . $ext . ' (dukung .xlsx atau .csv).');
    }
    $assoc = qa_map_rows($raw);
    if (!$assoc) throw new RuntimeException('Tidak ada baris Q&A valid. Pastikan ada kolom Question/Pertanyaan dan Answer/Jawaban.');
    return qa_rows_to_items($assoc);
}

/** Parse template Question_*.xlsx/csv: B/C = themes, F/G/H = starters. */
function prompt_parse_file(string $path, ?string $hintName = null): array {
    if (!is_file($path)) throw new RuntimeException('File tidak ditemukan.');
    $ext = strtolower(pathinfo($hintName ?? $path, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') $rows = qa_csv_matrix($path);
    elseif ($ext === 'xlsx') $rows = qa_xlsx_matrix($path);
    else throw new RuntimeException('Format tidak didukung: .' . $ext . ' (dukung .xlsx atau .csv).');

    $header = null;
    foreach (array_slice($rows, 0, 12, true) as $i => $row) {
        if (strtolower(trim((string)($row[1] ?? ''))) === 'label'
            && strtolower(trim((string)($row[2] ?? ''))) === 'query'
            && strtolower(trim((string)($row[5] ?? ''))) === 'label'
            && strtolower(trim((string)($row[6] ?? ''))) === 'query') {
            $header = $i;
            break;
        }
    }
    if ($header === null) throw new RuntimeException('Header tidak dikenali. Ikuti format Question_ANGKRINGAN_KAMPUNG_JAWI.xlsx.');

    $themes = $starters = [];
    foreach (array_slice($rows, $header + 1) as $row) {
        $themeLabel = trim((string)($row[1] ?? ''));
        $themeQuery = trim((string)($row[2] ?? ''));
        if ($themeLabel !== '' && $themeQuery !== '' && count($themes) < 6) {
            $themes[] = ['label' => $themeLabel, 'query' => $themeQuery];
        }
        $starterLabel = trim((string)($row[5] ?? ''));
        $starterQuery = trim((string)($row[6] ?? ''));
        $starterDesc = trim((string)($row[7] ?? '')) ?: $starterQuery;
        if ($starterLabel !== '' && $starterQuery !== '' && count($starters) < 3) {
            $starters[] = ['label' => $starterLabel, 'query' => $starterQuery, 'desc' => $starterDesc];
        }
    }
    if (!$themes && !$starters) throw new RuntimeException('Tidak ada pertanyaan valid dalam file.');
    return ['themes' => $themes, 'starters' => $starters];
}

/** Cari baris header (Question/Answer/Aliases/Keywords) di baris awal, lalu map data jadi array assoc. */
function qa_map_rows(array $raw): array {
    for ($i = 0; $i < min(count($raw), 12); $i++) {
        $roleCol = [];
        foreach ($raw[$i] as $j => $cell) {
            $role = qa_header_role((string)$cell);
            if ($role !== null && !isset($roleCol[$role])) $roleCol[$role] = $j;
        }
        if (!isset($roleCol['question'], $roleCol['answer'])) continue;
        $out = [];
        for ($k = $i + 1; $k < count($raw); $k++) {
            $row = $raw[$k];
            $r = [
                'question' => trim((string)($row[$roleCol['question']] ?? '')),
                'answer' => trim((string)($row[$roleCol['answer']] ?? '')),
                'aliases' => isset($roleCol['aliases']) ? trim((string)($row[$roleCol['aliases']] ?? '')) : '',
                'keywords' => isset($roleCol['keywords']) ? trim((string)($row[$roleCol['keywords']] ?? '')) : '',
            ];
            if ($r['question'] !== '' || $r['answer'] !== '') $out[] = $r;
        }
        if ($out) return $out;
    }
    return [];
}

function qa_header_role(string $s): ?string {
    $s = strtolower(trim($s));
    $s = (string)preg_replace('/\([^)]*\)/', ' ', $s);      // buang "(PISAH KOMA)"
    $s = (string)preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    $s = trim((string)preg_replace('/\s+/', ' ', $s));
    if ($s === '') return null;
    if (str_contains($s, 'alias') || str_contains($s, 'pertanyaan lain') || str_contains($s, 'variasi') || str_contains($s, 'sinonim')) return 'aliases';
    if (str_contains($s, 'kata kunci') || str_contains($s, 'keyword') || str_contains($s, 'tag')) return 'keywords';
    if (str_contains($s, 'jawaban') || str_contains($s, 'answer') || $s === 'a' || $s === 'respon') return 'answer';
    if (str_contains($s, 'pertanyaan') || str_contains($s, 'question') || str_contains($s, 'query') || $s === 'q') return 'question';
    return null;
}

function qa_rows_to_items(array $assoc): array {
    $items = [];
    foreach ($assoc as $r) {
        $items[] = [
            'id' => 'QA' . str_pad((string)(count($items) + 1), 2, '0', STR_PAD_LEFT),
            'question' => $r['question'],
            'answer' => $r['answer'],
            'aliases' => qa_split_list($r['aliases']),
            'keywords' => qa_split_list($r['keywords']),
        ];
    }
    return $items;
}

function qa_split_list(string $s): array {
    $parts = (array)preg_split('/[,;|\/•·–—-]+/u', $s);
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p !== '' && !in_array($p, $out, true)) $out[] = $p;
    }
    return $out;
}

/* ---------------- CSV ---------------- */

function qa_csv_matrix(string $path): array {
    $raw = (string)file_get_contents($path);
    if ($raw === '') return [];
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    $first = strtok($raw, "\r\n") ?: '';
    $delim = ',';
    $best = -1;
    foreach ([',', ';', "\t"] as $d) {
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $first); rewind($fh);
        $n = count(fgetcsv($fh, 0, $d) ?: []);
        fclose($fh);
        if ($n > $best) { $best = $n; $delim = $d; }
    }
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
    $rows = [];
    while (($line = fgetcsv($fh, 0, $delim)) !== false) {
        if ($line === [null]) continue;
        $rows[] = $line;
    }
    fclose($fh);
    return $rows;
}

/* ---------------- XLSX (pure PHP) ---------------- */

function qa_xlsx_matrix(string $path): array {
    $bin = (string)file_get_contents($path);
    $zip = qa_zip_index($bin);
    if (!$zip) throw new RuntimeException('File .xlsx rusak (bukan ZIP valid).');

    // Pilih sheet pertama via workbook.xml + rels.
    $sheetPath = null;
    if (isset($zip['xl/workbook.xml'])) {
        $wb = @simplexml_load_string($zip['xl/workbook.xml']);
        if ($wb !== false) {
            $sheetNodes = $wb->xpath('//*[local-name()="sheet"]');
            $rid = null;
            if ($sheetNodes && preg_match('/r:id="([^"]+)"/', (string)$sheetNodes[0]->asXML(), $m)) $rid = $m[1];
            if ($rid !== null && isset($zip['xl/_rels/workbook.xml.rels'])) {
                $rels = @simplexml_load_string($zip['xl/_rels/workbook.xml.rels']);
                if ($rels !== false) {
                    foreach ($rels->xpath('//*[local-name()="Relationship"]') as $rel) {
                        $axml = (string)$rel->asXML();
                        if (preg_match('/Id="' . preg_quote($rid, '/') . '"/', $axml) && preg_match('/Target="([^"]+)"/', $axml, $t)) {
                            $target = $t[1];
                            if (str_starts_with($target, '/')) $target = ltrim($target, '/');
                            elseif (!str_starts_with($target, 'xl/')) $target = 'xl/' . $target;
                            if (isset($zip[$target])) $sheetPath = $target;
                            break;
                        }
                    }
                }
            }
        }
    }
    if ($sheetPath === null) {
        foreach (array_keys($zip) as $name) {
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) { $sheetPath = $name; break; }
        }
    }
    if ($sheetPath === null || !isset($zip[$sheetPath])) throw new RuntimeException('Sheet tidak ditemukan di file .xlsx.');

    // sharedStrings (string sel pakai index)
    $shared = [];
    if (isset($zip['xl/sharedStrings.xml'])) {
        $sx = @simplexml_load_string($zip['xl/sharedStrings.xml']);
        if ($sx !== false) {
            foreach ($sx->xpath('//*[local-name()="si"]') as $si) {
                $txt = '';
                foreach ($si->xpath('.//*[local-name()="t"]') as $t) $txt .= (string)$t;
                $shared[] = $txt;
            }
        }
    }

    $sh = @simplexml_load_string($zip[$sheetPath]);
    if ($sh === false) throw new RuntimeException('Sheet tidak bisa dibaca.');
    $rows = [];
    foreach ($sh->xpath('//*[local-name()="row"]') as $row) {
        $vals = [];
        $auto = 0;
        foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
            $axml = (string)$cell->asXML();
            preg_match('/ r="([A-Z]+)\d+"/', $axml, $cm);
            $idx = $cm ? qa_col_index($cm[1]) : $auto;
            $auto = $idx + 1;
            $type = '';
            if (preg_match('/ t="([^"]+)"/', $axml, $tm)) $type = $tm[1];
            $tNodes = $cell->xpath('.//*[local-name()="t"]');
            if ($type === 's') {
                $vNodes = $cell->xpath('.//*[local-name()="v"]');
                $si = (int)($vNodes[0] ?? 0);
                $vals[$idx] = $shared[$si] ?? '';
            } elseif ($type === 'inlineStr' || $type === 'str' || $tNodes) {
                $txt = '';
                foreach ($tNodes as $t) $txt .= (string)$t;
                $vals[$idx] = $txt;
            } else {
                $vNodes = $cell->xpath('.//*[local-name()="v"]');
                $vals[$idx] = trim((string)($vNodes[0] ?? ''));
            }
        }
        if ($vals === []) continue;
        $rowArr = [];
        for ($i = 0; $i <= max(array_keys($vals)); $i++) $rowArr[] = $vals[$i] ?? '';
        $rows[] = $rowArr;
    }
    return $rows;
}

function qa_col_index(string $letters): int {
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) $n = $n * 26 + (ord($letters[$i]) - 64);
    return $n - 1;
}

/** Index entry ZIP (central directory) -> [nama => [method, compressed, uncompressed, dataOffset]]. */
function qa_zip_index(string $bin): array {
    $eocd = strrpos($bin, "PK\x05\x06");
    if ($eocd === false) return [];
    $count = unpack('v', substr($bin, $eocd + 10, 2))[1];
    $cdOff = unpack('V', substr($bin, $eocd + 16, 4))[1];
    $p = $cdOff;
    $files = [];
    for ($i = 0; $i < $count && $p + 46 <= strlen($bin); $i++) {
        if (substr($bin, $p, 4) !== "PK\x01\x02") break;
        $method = unpack('v', substr($bin, $p + 10, 2))[1];
        $clen = unpack('V', substr($bin, $p + 20, 4))[1];
        $ulen = unpack('V', substr($bin, $p + 24, 4))[1];
        $nlen = unpack('v', substr($bin, $p + 28, 2))[1];
        $elen = unpack('v', substr($bin, $p + 30, 2))[1];
        $clen2 = unpack('v', substr($bin, $p + 32, 2))[1];
        $lho = unpack('V', substr($bin, $p + 42, 4))[1];
        $name = substr($bin, $p + 46, $nlen);
        if ($name !== '') $files[$name] = ['m' => $method, 'clen' => $clen, 'ulen' => $ulen, 'lho' => $lho];
        $p += 46 + $nlen + $elen + $clen2;
    }
    foreach ($files as $name => $f) {
        $lp = $f['lho'];
        if (substr($bin, $lp, 4) !== "PK\x03\x04") continue;
        $nlen = unpack('v', substr($bin, $lp + 26, 2))[1];
        $elen = unpack('v', substr($bin, $lp + 28, 2))[1];
        $data = substr($bin, $lp + 30 + $nlen + $elen, $f['clen']);
        if ($f['m'] === 0) {
            $files[$name]['data'] = $data;
        } elseif ($f['m'] === 8) {
            $out = @gzinflate($data);
            if ($out === false || strlen($out) !== $f['ulen']) $out = @gzuncompress($data); // fallback zlib header
            if ($out === false) $out = '';
            $files[$name]['data'] = $out;
        } else {
            $files[$name]['data'] = ''; // metode tak didukung
        }
    }
    $out = [];
    foreach ($files as $name => $f) $out[$name] = $f['data'];
    return $out;
}

} // guard function_exists
