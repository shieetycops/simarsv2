<?php
// Setup database uji simars_v2 (TIDAK menyentuh database simars versi lama).
// Menjalankan: schema.sql -> migrasi v2 -> seed klasifikasi -> user uji.
// Idempoten: tahap yang sudah diterapkan di database dilewati, aman diulang.
$config = require __DIR__ . '/api-php/config.php';
$pdo = new PDO("mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4", $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$run = function (string $file) use ($pdo) {
    $sql = file_get_contents($file);
    $pdo->exec($sql);
    echo "OK: $file\n";
};
$tableExists = function (string $name) use ($pdo): bool {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$name]);
    return (bool) $stmt->fetch();
};
$columnExists = function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetch();
};

// 1. Skema dasar — lewati bila tabel users sudah ada (schema.sql memakai
//    CREATE TABLE polos, bukan IF NOT EXISTS).
if ($tableExists('users')) {
    echo "SKIP: schema.sql (database sudah berisi tabel)\n";
} else {
    $run(__DIR__ . '/api-php/schema.sql');
}

// 2. Migrasi workflow v2 — lewati bila kolom khas v2 sudah ada (ALTER TABLE
//    MySQL tidak mendukung ADD COLUMN IF NOT EXISTS).
if ($columnExists('incoming_letters', 'current_stage')) {
    echo "SKIP: migrasi v2_workflow (sudah diterapkan)\n";
} else {
    $run(__DIR__ . '/api-php/migrations/2026_09_23_v2_workflow.sql');
}

// 3. Seed master klasifikasi — lewati bila master sudah terisi.
if (!$tableExists('archive_classifications')) {
    $run(__DIR__ . '/api-php/migrations/2026_09_23_v2_workflow.sql');
}
$seedCount = (int) $pdo->query('SELECT COUNT(*) FROM archive_classifications')->fetchColumn();
if ($seedCount > 0) {
    echo "SKIP: seed klasifikasi (master sudah terisi $seedCount entri)\n";
} else {
    $run(__DIR__ . '/api-php/migrations/2026_09_23_v2_seed_classifications.sql');
}

// 3b. Rute keputusan Sekretaris/Panitera (SOP/AS/04 langkah 13) — lewati bila
//     kolomnya sudah ada.
if ($columnExists('incoming_letters', 'disposition_route')) {
    echo "SKIP: migrasi disposition_route (sudah diterapkan)\n";
} else {
    $run(__DIR__ . '/api-php/migrations/2026_09_24_add_disposition_route.sql');
}

// 3c. Pelaksana surat + jejak penunjukan (Fase 0 WA & Buku Kendali) — lewati bila
//     kolomnya sudah ada. Tanpa kolom ini, daftar Buku Kendali gagal query.
if ($columnExists('incoming_letters', 'assignee_user_id')) {
    echo "SKIP: migrasi letter_assignee (sudah diterapkan)\n";
} else {
    $run(__DIR__ . '/api-php/migrations/2026_09_26_add_letter_assignee.sql');
}

// 3d. Revisi disposisi SOP/AS/04 langkah 11-18 (rekomendasi vs keputusan final,
//     arahan pimpinan, unit tujuan) — lewati bila kolomnya sudah ada.
if ($columnExists('incoming_letters', 'rekomendasi_route')) {
    echo "SKIP: migrasi revisi_disposisi_sop (sudah diterapkan)\n";
} else {
    $run(__DIR__ . '/api-php/migrations/2026_09_28_revisi_disposisi_sop.sql');
}

// 3f. Patch revisi kedua P1-P8 (workflow_settings + lembar 1 disposisi) —
//     lewati bila kolomnya sudah ada; tabel memakai IF NOT EXISTS jadi aman.
if ($columnExists('incoming_letters', 'lembar1_diserahkan_oleh')) {
    echo "SKIP: migrasi sop_patch (sudah diterapkan)\n";
} else {
    $run(__DIR__ . '/api-php/migrations/2026_09_29_sop_patch.sql');
}

// 3e. Kolom lain yang ditambah migrasi terpisah — lewati bila sudah ada.
foreach ([
    '2026_09_21_add_login_attempts.sql',
    '2026_09_24_seed_archive_classifications.sql',
    '2026_09_25_fix_address_status_typo.sql',
] as $extra) {
    $f = __DIR__ . '/api-php/migrations/' . $extra;
    if (is_file($f)) {
        try { $run($f); } catch (Throwable $e) { echo "SKIP-ERR: $extra (" . substr($e->getMessage(), 0, 100) . ")\n"; }
    }
}


// User uji (password hash bcrypt).
$users = [
    ['admin_v2', 'admin123', 'Admin Uji v2', 'ADMIN'],
    ['sekre_v2', 'sekre123', 'Sekretaris Uji v2', 'SEKRETARIS'],
    ['pimpin_v2', 'pimpin123', 'Pimpinan Uji v2', 'PIMPINAN'],
    ['staf_v2', 'staf123', 'Staf Uji v2', 'STAFF'],
    ['kasubag_v2', 'kasubag123', 'Kasubag Umum Uji v2', 'KEPALA_SUB_UMUM'],
    ['panitera_v2', 'panitera123', 'Panitera Uji v2', 'PANITERA'],
    ['arsip_v2', 'arsip123', 'Arsiparis Uji v2', 'ARSIPARIS'],
    ['pegawai_v2', 'pegawai123', 'Pegawai Uji v2', 'STAFF'],
];
$stmt = $pdo->prepare('INSERT INTO users (id, username, password, name, role) VALUES (?, ?, ?, ?, ?)');
$upd = $pdo->prepare('UPDATE users SET password = ?, name = ?, role = ?, is_active = 1 WHERE username = ?');
foreach ($users as [$username, $password, $name, $role]) {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    // UPDATE in-place mempertahankan user id, sehingga baris lain yang
    // memakai FOREIGN KEY ke users (riwayat kendali, pemeriksaan, audit)
    // tidak rusak. INSERT hanya untuk user yang belum ada.
    $upd->execute([$hash, $name, $role, $username]);
    if ($upd->rowCount() === 0) {
        $stmt->execute([bin2hex(random_bytes(16)), $username, $hash, $name, $role]);
    }
    echo "OK: user $username ($role)\n";
}
echo 'Klasifikasi resmi: ' . $pdo->query("SELECT COUNT(*) FROM archive_classifications WHERE validation_status = 'OFFICIAL'")->fetchColumn() . " entri\n";
echo "SETUP_DB_OK\n";
