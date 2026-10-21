<?php
// Tes: merge_edited() harus mempertahankan field di luar form. Jalankan: php tests/merge_edited_test.php
$src = file_get_contents(__DIR__ . '/../index.php');
if (!preg_match('/function merge_edited\(.*?\r?\n\}\r?\n/s', $src, $m)) { fwrite(STDERR, "merge_edited tidak ditemukan\n"); exit(1); }
eval($m[0]);
$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? "PASS " : "FAIL ") . $name . "\n"; if (!$ok) $fail++; }

$old = [['id' => 'G01', 'category' => 'a', 'response' => 'R1', 'requiresState' => 'S', 'action' => 'nav', 'dynamic' => false]];
$edited = [['id' => 'G01', 'category' => 'b'], ['id' => 'G02', 'category' => 'c']];
$r = merge_edited($old, $edited, ['dynamic' => true, 'action' => null]);
check('field yang diedit berubah', $r[0]['category'] === 'b');
check('response dipertahankan', $r[0]['response'] === 'R1');
check('requiresState dipertahankan', $r[0]['requiresState'] === 'S');
check('action/dynamic lama tidak ditimpa default', $r[0]['action'] === 'nav' && $r[0]['dynamic'] === false);
check('record baru memakai default', $r[1]['dynamic'] === true && array_key_exists('action', $r[1]) && $r[1]['action'] === null);
check('record yang dihapus tidak muncul', count(merge_edited($old, [])) === 0);
check('urutan mengikuti form', array_column(merge_edited($old, [['id' => 'X'], ['id' => 'G01']]), 'id') === ['X', 'G01']);
exit($fail ? 1 : 0);
