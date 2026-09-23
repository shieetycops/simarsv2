# Bangun ulang paket frontend (simars-frontend-<tanggal>.zip) dari folder dist/.
#
# Pakai:  powershell -NoProfile -ExecutionPolicy Bypass -File scripts\build_frontend_zip.ps1
#
# Aturan paket (jangan diubah tanpa alasan):
#   - TANPA prefix: isi dist/ menjadi isi document root (public_html/simars/),
#     jadi index.html harus berada di akar zip, bukan di dalam subfolder.
#   - pemisah path SELALU "/" (bukan "\") supaya extract lewat File Manager cPanel benar.
#   - .htaccess WAJIB ikut: di dalamnya ada SPA fallback + header CSP. Tanpa berkas
#     ini halaman dalam (mis. /surat-masuk) balas 404 saat di-refresh, dan header
#     keamanan hilang.
#   - api/ TIDAK ikut: backend dikirim terpisah lewat build_api_zip.ps1, supaya
#     upload frontend tidak pernah menimpa api/config.php di hosting.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$repo = Split-Path -Parent $PSScriptRoot
$src  = Join-Path $repo 'dist'
$out  = Join-Path $repo 'simars-frontend-2026-09-21.zip'
$root = (Resolve-Path $src).Path

if (-not (Test-Path (Join-Path $root 'index.html'))) {
    throw "dist/index.html tidak ada - jalankan 'npm run build' dulu."
}

if (Test-Path $out) { Remove-Item $out -Force }

$zip = [System.IO.Compression.ZipFile]::Open($out, 'Create')
try {
    $files = Get-ChildItem -Path $root -Recurse -File -Force |
        Sort-Object FullName

    foreach ($f in $files) {
        $rel   = $f.FullName.Substring($root.Length + 1).Replace('\', '/')
        $entry = $zip.CreateEntry($rel, [System.IO.Compression.CompressionLevel]::Optimal)
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
$hasIndex = ($z2.Entries | Where-Object { $_.FullName -eq 'index.html' }).Count
$hasHt    = ($z2.Entries | Where-Object { $_.FullName -eq '.htaccess' }).Count
$hasApi   = ($z2.Entries | Where-Object { $_.FullName -like 'api/*' }).Count
$z2.Dispose()

Write-Host ("ZIP  : {0}" -f $zi.FullName)
Write-Host ("Entri: {0}   Bytes: {1}" -f $n, $zi.Length)
Write-Host ("Pemisah backslash: {0} (harus 0)" -f $bs)
Write-Host ("index.html di akar: {0} (harus 1)" -f $hasIndex)
Write-Host (".htaccess di akar: {0} (harus 1)" -f $hasHt)
Write-Host ("Entri api/*: {0} (harus 0)" -f $hasApi)
