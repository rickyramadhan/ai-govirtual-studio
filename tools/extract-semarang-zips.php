<?php
/** Extract UNIQUE Semarang ZIP exports without overwrite or path traversal. */
declare(strict_types=1);

$root = 'C:/xampp/htdocs/semarang';
$inventory = __DIR__ . '/semarang-zip-inventory.csv';
if (!class_exists(ZipArchive::class)) throw new RuntimeException('PHP ext-zip tidak tersedia');
if (!is_file($inventory)) throw new RuntimeException('inventory tidak ditemukan');

$csv = fopen($inventory, 'rb');
if ($csv === false) throw new RuntimeException('inventory gagal dibuka');
fgetcsv($csv); // header
$rows = [];
while (($row = fgetcsv($csv)) !== false) {
    if (count($row) >= 2 && $row[1] === 'UNIQUE') $rows[] = $row[0];
}
fclose($csv);

$ok = 0;
$failed = [];
foreach ($rows as $zipName) {
    $zipPath = $root . '/' . $zipName;
    $folder = pathinfo($zipName, PATHINFO_FILENAME);
    $target = $root . '/' . $folder;
    echo "[$folder] ";

    try {
        if (!is_file($zipPath)) throw new RuntimeException('ZIP tidak ditemukan');
        if (file_exists($target)) throw new RuntimeException('target sudah ada; tidak ditimpa');

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new RuntimeException('ZIP gagal dibuka');
        try {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
                if ($name === '' || str_starts_with($name, '/') || preg_match('~(^|/)\.\.(/|$)~', $name) || preg_match('~^[A-Za-z]:/~', $name)) {
                    throw new RuntimeException("path ZIP tidak aman: $name");
                }
                $names[] = $name;
            }
            $files = array_values(array_filter($names, fn(string $name): bool => !str_ends_with($name, '/')));
            $index = array_values(array_filter($files, fn(string $name): bool => strtolower(basename($name)) === 'index.html'));
            $pano = array_values(array_filter($files, fn(string $name): bool => strtolower(basename($name)) === 'pano.xml'));
            if (count($index) !== 1 || count($pano) !== 1) throw new RuntimeException('harus tepat satu index.html dan pano.xml');

            $indexDir = trim(str_replace('\\', '/', dirname($index[0])), './');
            $panoDir = trim(str_replace('\\', '/', dirname($pano[0])), './');
            if ($indexDir !== $panoDir) throw new RuntimeException('index.html dan pano.xml beda root');

            if (!mkdir($target, 0775, false) && !is_dir($target)) throw new RuntimeException('target gagal dibuat');
            foreach ($files as $i => $name) {
                $relative = $indexDir !== '' && str_starts_with($name, $indexDir . '/')
                    ? substr($name, strlen($indexDir) + 1)
                    : $name;
                if ($relative === '' || ($indexDir !== '' && $relative === $name)) continue;
                $destination = $target . '/' . $relative;
                $directory = dirname($destination);
                if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException("folder gagal dibuat: $relative");
                $source = $zip->getStream($zip->getNameIndex(array_search($name, $names, true)));
                $output = fopen($destination, 'wb');
                if ($source === false || $output === false) throw new RuntimeException("file gagal dibuka: $relative");
                stream_copy_to_stream($source, $output);
                fclose($source);
                fclose($output);
            }
        } finally {
            $zip->close();
        }

        if (!is_file($target . '/index.html') || !is_file($target . '/pano.xml')) throw new RuntimeException('hasil ekstraksi tidak valid');
        echo "OK\n";
        $ok++;
    } catch (Throwable $e) {
        $failed[$zipName] = $e->getMessage();
        echo 'GAGAL: ' . $e->getMessage() . "\n";
    }
}

echo "\nHasil: $ok/" . count($rows) . " ZIP unik berhasil diekstrak.\n";
if ($failed) {
    echo json_encode($failed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
}
