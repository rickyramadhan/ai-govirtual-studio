$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = 'C:\xampp\htdocs\semarang'
$inventory = Join-Path $PSScriptRoot 'semarang-zip-inventory.csv'
$rows = Import-Csv -LiteralPath $inventory | Where-Object status -eq 'UNIQUE'
$ok = 0
$failed = [ordered]@{}

foreach ($row in $rows) {
    $zipPath = Join-Path $root $row.zip
    $folder = [IO.Path]::GetFileNameWithoutExtension($row.zip)
    $target = Join-Path $root $folder
    Write-Host -NoNewline "[$folder] "

    try {
        if (-not (Test-Path -LiteralPath $zipPath -PathType Leaf)) { throw 'ZIP tidak ditemukan' }
        if (Test-Path -LiteralPath $target) { throw 'target sudah ada; tidak ditimpa' }

        $zip = [IO.Compression.ZipFile]::OpenRead($zipPath)
        try {
            $files = @($zip.Entries | Where-Object { $_.Name })
            $indexes = @($files | Where-Object { $_.Name -ieq 'index.html' })
            $panos = @($files | Where-Object { $_.Name -ieq 'pano.xml' })
            if ($indexes.Count -ne 1 -or $panos.Count -ne 1) { throw 'harus tepat satu index.html dan pano.xml' }

            $indexRoot = $indexes[0].FullName.Substring(0, $indexes[0].FullName.Length - $indexes[0].Name.Length).TrimEnd('/', '\')
            $panoRoot = $panos[0].FullName.Substring(0, $panos[0].FullName.Length - $panos[0].Name.Length).TrimEnd('/', '\')
            if ($indexRoot -ne $panoRoot) { throw 'index.html dan pano.xml beda root' }

            New-Item -ItemType Directory -Path $target | Out-Null
            $targetFull = [IO.Path]::GetFullPath($target + [IO.Path]::DirectorySeparatorChar)
            foreach ($entry in $files) {
                $name = $entry.FullName.Replace('\', '/')
                $prefix = if ($indexRoot) { $indexRoot.Replace('\', '/').TrimEnd('/') + '/' } else { '' }
                if ($prefix -and -not $name.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) { continue }
                $relative = if ($prefix) { $name.Substring($prefix.Length) } else { $name }
                if (-not $relative) { continue }
                $destination = [IO.Path]::GetFullPath((Join-Path $target $relative))
                if (-not $destination.StartsWith($targetFull, [StringComparison]::OrdinalIgnoreCase)) { throw "path ZIP tidak aman: $name" }
                $directory = Split-Path -Parent $destination
                if (-not (Test-Path -LiteralPath $directory)) { New-Item -ItemType Directory -Path $directory -Force | Out-Null }
                [IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $destination, $false)
            }
        } finally {
            if ($zip) { $zip.Dispose() }
        }

        if (-not (Test-Path -LiteralPath (Join-Path $target 'index.html') -PathType Leaf) -or
            -not (Test-Path -LiteralPath (Join-Path $target 'pano.xml') -PathType Leaf)) {
            throw 'hasil ekstraksi tidak valid'
        }
        Write-Host 'OK'
        $ok++
    } catch {
        $failed[$row.zip] = $_.Exception.Message
        Write-Host "GAGAL: $($_.Exception.Message)"
    }
}

Write-Host "`nHasil: $ok/$($rows.Count) ZIP unik berhasil diekstrak."
if ($failed.Count) {
    $failed | ConvertTo-Json | Write-Host
    exit 1
}
