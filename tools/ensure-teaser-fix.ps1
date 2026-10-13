<#
.SYNOPSIS
  Tambah ulang / pindahkan referensi teaser-fix.js di index.html hasil export Pano2VR baru.

.DESCRIPTION
  Export Pano2VR baru menimpa index.html. Script ini memastikan satu baris
  <script src="../teaser-fix.js"></script> ada di posisi AWAL (tepat setelah
  skin.js, SEBELUM skin instance dibuat) sehingga patch prototype getSoundsPermitted
  berlaku sebelum video teaser dibuat. Idempoten.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File tools\ensure-teaser-fix.ps1
#>
param(
  [string]$SemarangRoot = "C:\xampp\htdocs\semarang"
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath "$SemarangRoot\teaser-fix.js")) {
  Write-Error "teaser-fix.js tidak ditemukan di $SemarangRoot. Salin file pusat ke sana dulu."
}

$files = Get-ChildItem -Path $SemarangRoot -Recurse -Filter "index.html" -ErrorAction SilentlyContinue |
  Where-Object { Select-String -LiteralPath $_.FullName -Pattern "pano2vr_player" -Quiet }

if (-not $files) { Write-Output "Tidak ada index.html Pano2VR di bawah $SemarangRoot."; exit }

$done = 0; $skip = 0
foreach ($f in $files) {
  $lines = [System.IO.File]::ReadAllLines($f.FullName, [System.Text.Encoding]::UTF8)

  # hapus semua baris berisi teaser-fix.js (hilangkan duplikat/pengaturan lama)
  $lines = @($lines | Where-Object { $_ -notmatch 'teaser-fix\.js' })

  # cari posisi penutup </script> untuk skin.js
  $skinIdx = -1
  for ($i = 0; $i -lt $lines.Count; $i++) { if ($lines[$i] -match 'src=["'']skin\.js') { $skinIdx = $i; break } }
  if ($skinIdx -lt 0) { Write-Output ("SKIP (skin.js tak ditemukan): " + $f.FullName); $skip++; continue }

  $closeIdx = -1
  for ($i = $skinIdx; $i -lt $lines.Count; $i++) { if ($lines[$i] -match '</script>') { $closeIdx = $i; break } }
  if ($closeIdx -lt 0) { Write-Output ("SKIP (penutup skin.js tak ditemukan): " + $f.FullName); $skip++; continue }

  $newLines = New-Object System.Collections.Generic.List[string]
  for ($i = 0; $i -le $closeIdx; $i++) { $newLines.Add($lines[$i]) }
  $newLines.Add("`t`t<script src=`"../teaser-fix.js`"></script>")
  for ($i = $closeIdx + 1; $i -lt $lines.Count; $i++) { $newLines.Add($lines[$i]) }

  [System.IO.File]::WriteAllLines($f.FullName, $newLines, (New-Object System.Text.UTF8Encoding $false))
  Write-Output ("PASANG: " + $f.FullName)
  $done++
}
Write-Output ("Selesai. Dipasang: $done, diskip: $skip.")