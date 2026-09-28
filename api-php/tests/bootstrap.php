<?php
// Bootstrap uji PHPUnit.
//
// WAJIB: pasang stub pengirim Fonnte sejak awal. Sebelum ini, uji yang tidak
// memasang stub sendiri (mis. notifikasi surat masuk) benar-benar menembak
// https://api.fonnte.com/send — di mesin pengembang token-nya kosong sehingga
// hanya berbalas "invalid token", tapi di mesin yang token-nya terisi uji akan
// MENGIRIM pesan WhatsApp sungguhan ke grup/pimpinan.
//
// Uji yang perlu memeriksa isi pesan tetap boleh memasang stub sendiri
// (Whatsapp::$sender = function (...) {...}) dan mengembalikannya ke stub ini.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Whatsapp.php';

Whatsapp::$sender = function (string $token, string $target, string $message): void {
    // Sengaja tidak melakukan apa pun: uji tidak boleh mengirim WhatsApp nyata.
};
