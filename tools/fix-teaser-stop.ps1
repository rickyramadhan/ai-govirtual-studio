<#
.SYNOPSIS
  Patch hasil export Pano2VR agar tombol Skip benar-benar menghentikan
  video teaser (desktop + mobile), bukan cuma me-hide popup-nya.

.DESCRIPTION
  Root cause: action Skip di skin hanya melakukan Hide container
  __2video_teaser + player.stopSound("_main"). Hide tidak menghentikan
  elemen <video> (audio tetap jalan), dan ID "_main" bahkan tidak ada
  di pano.xml — video diputar sebagai sound "video - desktop" /
  "video - mobile" yang tidak pernah di-stop.

  Script ini idempoten (marker FIX-TEASER-STOP): aman dijalankan
  berulang, file yang sudah dipatch akan di-skip.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File tools\fix-teaser-stop.ps1
  powershell -ExecutionPolicy Bypass -File tools\fix-teaser-stop.ps1 -ExportRoot "D:\export\semarang"
#>
param(
  [string]$ExportRoot = "C:\xampp\htdocs\semarang"
)

$ErrorActionPreference = 'Stop'

$old = 'player.stopSound("_main");'
$fix = 'player.stopSound("_main");/*FIX-TEASER-STOP*/player.stopSound("video - desktop");player.stopSound("video - mobile");try{if(me._video_desktop__vid){me._video_desktop__vid.pause();}}catch(_se){}try{if(me._video_mobile__vid){me._video_mobile__vid.pause();}}catch(_se2){}try{if(me.__2video_teaser){var _vs=me.__2video_teaser.querySelectorAll(''video'');for(var _vi=0;_vi<_vs.length;_vi++){try{_vs[_vi].pause();}catch(_e){}}}}catch(_se3){}'

if (-not (Test-Path -LiteralPath $ExportRoot)) {
  Write-Error "ExportRoot tidak ditemukan: $ExportRoot"
}

$files = Get-ChildItem -Path $ExportRoot -Recurse -Filter 'skin.js' -ErrorAction SilentlyContinue
if (-not $files) { Write-Error "Tidak ada skin.js di bawah $ExportRoot" }

$patched = 0; $skipped = 0
foreach ($f in $files) {
  $t = [System.IO.File]::ReadAllText($f.FullName, [System.Text.Encoding]::UTF8)
  if ($t.Contains('FIX-TEASER-STOP')) { Write-Output ("SKIP (sudah dipatch): " + $f.FullName); $skipped++; continue }
  if (-not $t.Contains($old)) { Write-Output ("SKIP (target tidak ada): " + $f.FullName); $skipped++; continue }
  $n = ([regex]::Matches($t, [regex]::Escape($old))).Count
  $bak = $f.FullName + '.preskipfix.bak'
  if (-not (Test-Path -LiteralPath $bak)) { Copy-Item -LiteralPath $f.FullName -Destination $bak }
  [System.IO.File]::WriteAllText($f.FullName, $t.Replace($old, $fix), (New-Object System.Text.UTF8Encoding $false))
  Write-Output ("PATCHED $n titik :: " + $f.FullName)
  $patched++
}

Write-Output ("Selesai. Dipatch: $patched, diskip: $skipped.")

# Verifikasi syntax bila node tersedia
if (Get-Command node -ErrorAction SilentlyContinue) {
  $fail = 0
  foreach ($f in $files) {
    & node --check $f.FullName 2>$null
    if ($LASTEXITCODE -ne 0) { Write-Warning ("SYNTAX FAIL: " + $f.FullName); $fail++ }
  }
  if ($fail -eq 0) { Write-Output 'Syntax OK semua file.' }
} else {
  Write-Output 'node tidak ditemukan, lewati verifikasi syntax.'
}
