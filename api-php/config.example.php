<?php
// Salin ke config.php lalu isi kredensial hosting. config.php di-gitignore.
return [
    'db_host' => 'localhost',
    'db_name' => 'simars',
    'db_user' => 'root',
    'db_pass' => '',
    // Origin frontend yang boleh memanggil API. Ganti ke domain asli saat
    // produksi (mis. 'https://simars.pa-pasarwajo.go.id'). '*' berarti situs
    // mana pun boleh memanggil API ini dari browser pengguna.
    'cors_origin' => 'https://simars.pa-pasarwajo.go.id',
    'turnstile_secret_key' => '', // Secret key Cloudflare Turnstile
    'turnstile_enabled' => false, // Set true untuk mengaktifkan validasi Turnstile
    'turnstile_debug' => false, // true = tampilkan penyebab teknis kegagalan verifikasi
    'debug' => false, // true = tampilkan detail error 500 di respons API (jangan di produksi)
    // Kunci HMAC untuk menandatangani tautan lampiran view-only yang dikirim ke
    // WhatsApp (/surats/{id}?t=...). Isi dengan string acak panjang, mis. hasil
    // `php -r "echo bin2hex(random_bytes(32));"`. Selama masih kosong, tautan
    // view-only tetap bisa dibuka siapa pun yang memegang URL-nya.
    'app_secret' => '',
    // Kunci rahasia webhook WhatsApp: webhook hanya diproses bila URL-nya
    // memuat ?k=<nilai ini> (mis. https://domain/api/wabot?k=rahasia).
    // Selama kosong, webhook menerima request dari mana pun.
    'wabot_secret' => '',
];

