# Bangun ulang paket API (simars-api-<tanggal>.zip) dari folder api-php/.
#
# Pakai:  powershell -NoProfile -ExecutionPolicy Bypass -File scripts\build_api_zip.ps1
#
# Aturan paket (jangan diubah tanpa alasan):
#   - semua berkas diberi prefix "api/"  -> isi hosting public_html/simars/api/
#   - pemisah path SELALU "/" (bukan "\") supaya extract lewat File Manager cPanel benar
#   - config.php & router-local.php TIDAK ikut: config.php berisi kredensial DB
#     (kalau ikut ter-zip, password produksi ikut terkirim) dan router-local.php
#     hanya untuk server pengembangan lokal.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$repo = Split-Path -Parent $PSScriptRoot
$src  = Join-Path $repo 'api-php'
$out  = Join-Path $repo 'simars-api-2026-09-21.zip'
$skip = @('config.php', 'router-local.php')
$root = (Resolve-Path $src).Path

if (Test-Path $out) { Remove-Item $out -Force }

$zip = [System.IO.Compression.ZipFile]::Open($out, 'Create')
try {
    $files = Get-ChildItem -Path $root -Recurse -File -Force |
        Where-Object { $skip -notcontains $_.Name } |
        Sort-Object FullName

    foreach ($f in $files) {
        $rel   = $f.FullName.Substring($root.Length + 1).Replace('\', '/')
        $entry = $zip.CreateEntry('api/' + $rel, [System.IO.Compression.CompressionLevel]::Optimal)
        $es = $entry.Open()
        try {
            $fs = [System.IO.File]::OpenRead($f.FullName)
            try { $fs.CopyTo($es) } finally { $fs.Dispose() }
        } finally { $es.Dispose() }
    }
} finally {
    $zip.Dispose()
}

$zi = Get-Item $out
$z2 = [System.IO.Compression.ZipFile]::OpenRead($out)
$n  = $z2.Entries.Count
$bs = ($z2.Entries | Where-Object { $_.FullName -like '*\*' }).Count
$z2.Dispose()

Write-Host ("ZIP  : {0}" -f $zi.FullName)
Write-Host ("Entri: {0}   Bytes: {1}" -f $n, $zi.Length)
Write-Host ("Pemisah backslash: {0} (harus 0)" -f $bs)
