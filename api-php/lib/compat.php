<?php
// Polyfill fungsi bawaan PHP 8 supaya kode lib/ tetap jalan di PHP 7.4.
//
// Kenapa perlu: hosting shared hosting kadang masih PHP 7.4. Di sana
// str_contains()/str_starts_with()/str_ends_with() belum ada, sehingga
// pemanggilannya jadi "Call to undefined function" (fatal 500 tanpa pesan).
// Di PHP 8+ ketiganya sudah bawaan, jadi blok di bawah dilewati oleh
// function_exists() dan sama sekali tidak berpengaruh.
//
// Di-require PALING AWAL oleh bootstrap.php (sebelum glob(lib/*.php)) agar
// pasti sudah terdefinisi sebelum handler mana pun berjalan.
//
// Catatan: match() TIDAK bisa di-polyfill karena itu sintaks, bukan fungsi —
// karena itu Disposition::statusNotificationTitle() ditulis ulang jadi if-chain.

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
