<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/V2Workflow.php';

/**
 * Titik keputusan disposisi SIMARS v2 (SOP/AS/04 langkah 12-13).
 *
 * Yang diuji di sini adalah ATURAN, bukan request HTTP — pasangan uji HTTP-nya
 * ada di test_e2e.php (bagian "SOP/AS/04 langkah 13" dan "8b").
 */
final class V2WorkflowTest extends TestCase
{
    /** Sumber kebenaran: keputusan "perlu kebijakan pimpinan atau langsung" = Sekretaris/Panitera. */
    public function testDecisionPointHeldBySekretarisPanitera(): void
    {
        $roles = V2Workflow::allowedRolesForTransition('MENUNGGU_DISPOSISI', 'DIDISPOSISIKAN');
        foreach (['ADMIN', 'SEKRETARIS', 'PANITERA'] as $allowed) {
            $this->assertContains($allowed, $roles, "role $allowed harus boleh memutuskan");
        }
        foreach (['PIMPINAN', 'WAKIL_KETUA', 'KEPALA_SUB_UMUM', 'STAFF'] as $denied) {
            $this->assertNotContains($denied, $roles, "role $denied bukan pemutus disposisi");
        }
    }

    /** SOP langkah 4-7: Kasubag Umum/Sekretaris = "pengarah surat", bukan pimpinan. */
    public function testPengarahSuratBukanPimpinan(): void
    {
        $roles = V2Workflow::allowedRolesForTransition('MENUNGGU_PENGARAHAN', 'DIBACA_PENGARAH');
        $this->assertContains('KEPALA_SUB_UMUM', $roles);
        $this->assertContains('SEKRETARIS', $roles);
        $this->assertNotContains('PIMPINAN', $roles);
        $this->assertNotContains('WAKIL_KETUA', $roles);
    }

    /**
     * "Teruskan ke pelaksana" (SOP langkah 15-16) dijalankan Sekretaris/Panitera,
     * SESUDAH pimpinan mengembalikan surat beserta arahan. Pimpinan hanya memberi
     * arahan (kembali ke Sekretaris) — bukan meneruskan ke pelaksana.
     */
    public function testTeruskanDipegangSekretarisPanitera(): void
    {
        // Pimpinan di meja kebijakan hanya punya satu jalan: kembalikan ke Sekretaris
        // beserta arahan (SOP langkah 14-15). Kasubag/Sekretaris tidak bertindak di sini.
        $pimpinanRoles = V2Workflow::allowedRolesForTransition('MENUNGGU_KEBIJAKAN_PIMPINAN', 'DITERUSKAN_KE_SEKRETARIS_PANITERA');
        $this->assertContains('PIMPINAN', $pimpinanRoles);
        $this->assertContains('WAKIL_KETUA', $pimpinanRoles);
        $this->assertNotContains('SEKRETARIS', $pimpinanRoles);
        // Jalan pintas pimpinan -> pelaksana sudah ditutup (bukan transisi sah).
        $this->assertSame([], V2Workflow::allowedRolesForTransition('MENUNGGU_KEBIJAKAN_PIMPINAN', 'DITERUSKAN_KE_PELAKSANA'));

        // Pelaksanaan arahan (teruskan ke unit) dipegang Sekretaris/Panitera.
        $this->assertContains('SEKRETARIS', V2Workflow::allowedRolesForTransition('DITERUSKAN_KE_SEKRETARIS_PANITERA', 'DITERUSKAN_KE_PELAKSANA'));
        $this->assertNotContains('PIMPINAN', V2Workflow::allowedRolesForTransition('DITERUSKAN_KE_SEKRETARIS_PANITERA', 'DITERUSKAN_KE_PELAKSANA'));
    }

    /** Rute keputusan: kosong/asing ditolak, dua nilai sah diterima (case-insensitive). */
    public function testValidateDispositionRoute(): void
    {
        foreach ([null, '', '   '] as $kosong) {
            [$ok, $msg] = V2Workflow::validateDispositionRoute($kosong);
            $this->assertFalse($ok, 'rute kosong harus ditolak');
            $this->assertNotEmpty($msg, 'pesan error harus menjelaskan pilihan yang sah');
        }
        foreach (['KEBIJAKAN', 'LANGSUNG', 'kebijakan', ' langsung '] as $sah) {
            [$ok, $msg] = V2Workflow::validateDispositionRoute($sah);
            $this->assertTrue($ok, "rute '$sah' harus diterima");
            $this->assertNull($msg);
        }
        [$ok] = V2Workflow::validateDispositionRoute('NGAWUR');
        $this->assertFalse($ok, 'rute di luar daftar harus ditolak');
    }

    /** Pemetaan rute -> cabang tahap berikutnya dari DIDISPOSISIKAN. */
    public function testStagesAllowedByRoute(): void
    {
        // Revisi SOP/AS/04: kedua rute wajib lewat Sekretaris/Panitera (langkah 13).
        $this->assertSame(
            ['DITERUSKAN_KE_SEKRETARIS_PANITERA', 'MENUNGGU_KEBIJAKAN_PIMPINAN'],
            V2Workflow::stagesAllowedByRoute('KEBIJAKAN'));
        $this->assertSame(
            ['DITERUSKAN_KE_SEKRETARIS_PANITERA', 'DITERUSKAN_KE_PELAKSANA'],
            V2Workflow::stagesAllowedByRoute('langsung'));
        foreach ([null, '', 'NGAWUR'] as $tanpaRute) {
            $this->assertSame([], V2Workflow::stagesAllowedByRoute($tanpaRute), 'tanpa rute = tanpa pembatasan');
        }
    }

    /** Rekomendasi Kasubag: rute + alasan wajib min 10 karakter. */
    public function testValidateRekomendasi(): void
    {
        [$ok] = V2Workflow::validateRekomendasi('KEBIJAKAN', 'Butuh penetapan anggaran pimpinan.');
        $this->assertTrue($ok);
        [$ok] = V2Workflow::validateRekomendasi('KEBIJAKAN', '');
        $this->assertFalse($ok, 'alasan wajib');
        [$ok] = V2Workflow::validateRekomendasi('KEBIJAKAN', 'pendek');
        $this->assertFalse($ok, 'alasan min 10 karakter');
        [$ok] = V2Workflow::validateRekomendasi('', 'Alasan cukup panjang di sini.');
        $this->assertFalse($ok, 'rute wajib');
    }

    /** Rute final HANYA Sekretaris/Panitera/Admin; Kasubag/pegawai/Ketua ditolak. */
    public function testFinalRouteRoles(): void
    {
        foreach (['ADMIN', 'SEKRETARIS', 'PANITERA'] as $r) {
            $this->assertTrue(V2Workflow::canSetFinalRoute($r), "$r boleh tetapkan rute final");
        }
        foreach (['KEPALA_SUB_UMUM', 'PIMPINAN', 'WAKIL_KETUA', 'STAFF', 'ARSIPARIS'] as $r) {
            $this->assertFalse(V2Workflow::canSetFinalRoute($r), "$r ditolak tetapkan rute final");
        }
        foreach (['ADMIN', 'SEKRETARIS', 'PANITERA', 'KEPALA_SUB_UMUM'] as $r) {
            $this->assertTrue(V2Workflow::canGiveRekomendasi($r), "$r boleh beri rekomendasi");
        }
        $this->assertFalse(V2Workflow::canGiveRekomendasi('STAFF'));
    }

    /** Unit tujuan: wajib + harus dari daftar sah. */
    public function testValidateUnitTujuan(): void
    {
        [$ok] = V2Workflow::validateUnitTujuan('KASUBAG_UMUM');
        $this->assertTrue($ok);
        [$ok] = V2Workflow::validateUnitTujuan('');
        $this->assertFalse($ok, 'unit wajib');
        [$ok] = V2Workflow::validateUnitTujuan('UNIT_NGAWUR');
        $this->assertFalse($ok, 'unit asing ditolak');
    }

    /** Arahan pimpinan: wajib + min 10 karakter (Q1: final). */
    public function testValidateArahan(): void
    {
        [$ok] = V2Workflow::validateArahan('Setujui, teruskan ke unit pelaksana.');
        $this->assertTrue($ok);
        [$ok] = V2Workflow::validateArahan('');
        $this->assertFalse($ok, 'arahan wajib');
        [$ok] = V2Workflow::validateArahan('ok');
        $this->assertFalse($ok, 'arahan min 10 karakter');
    }

    /** DIARSIPKAN hanya ARSIPARIS/Admin (Q2). Kepala unit = Kasubag/Panmud. */
    public function testArsiparisAndUnitHead(): void
    {
        $this->assertTrue(V2Workflow::canMarkArchived('ARSIPARIS'));
        $this->assertTrue(V2Workflow::canMarkArchived('ADMIN'));
        $this->assertFalse(V2Workflow::canMarkArchived('STAFF'));
        $this->assertFalse(V2Workflow::canMarkArchived('KEPALA_SUB_UMUM'));
        $this->assertTrue(V2Workflow::isUnitHead('KEPALA_SUB_UMUM'));
        $this->assertTrue(V2Workflow::isUnitHead('PANITERA_MUDA_GUGATAN'));
        $this->assertFalse(V2Workflow::isUnitHead('STAFF'));
        $this->assertFalse(V2Workflow::isUnitHead('SEKRETARIS'));
    }

    /** Tombol yang dirender UI = transisi yang divalidasi server. */
    public function testAllowedTransitionsForDecisionPoint(): void
    {
        $this->assertSame([], V2Workflow::allowedTransitionsFor(['role' => 'PIMPINAN'], 'MENUNGGU_DISPOSISI'),
            'pimpinan tidak punya tombol apa pun di titik keputusan');

        $tr = V2Workflow::allowedTransitionsFor(['role' => 'SEKRETARIS'], 'MENUNGGU_DISPOSISI');
        $this->assertCount(1, $tr);
        $this->assertSame('DIDISPOSISIKAN', $tr[0]['toStage']);
        $this->assertTrue($tr[0]['requiresRoute'], 'tombol masuk DIDISPOSISIKAN wajib minta rute');
    }

    /** Jalur pintas TERREGISTRASI -> DIDISPOSISIKAN tetap wajib memilih rute. */
    public function testShortcutIntoDisposedStillRequiresRoute(): void
    {
        $tr = V2Workflow::allowedTransitionsFor(['role' => 'SEKRETARIS'], 'TERREGISTRASI');
        $stages = array_column($tr, 'toStage');
        $this->assertContains('DIDISPOSISIKAN', $stages);
        $tr = array_values(array_filter($tr, fn($t) => $t['toStage'] === 'DIDISPOSISIKAN'));
        $this->assertTrue($tr[0]['requiresRoute']);
    }

    /**
     * Rute mengunci percabangan sesudah DIDISPOSISIKAN. Revisi SOP/AS/04: kedua
     * rute WAJIB lewat Sekretaris/Panitera dulu (langkah 13), baru bercabang —
     * KEBIJAKAN naik ke pimpinan, LANGSUNG ke unit pelaksana.
     */
    public function testRouteLocksBranchAfterDecision(): void
    {
        $sekre = ['role' => 'SEKRETARIS'];
        $this->assertSame(['DITERUSKAN_KE_SEKRETARIS_PANITERA', 'DITERUSKAN_KE_PELAKSANA'],
            array_column(V2Workflow::allowedTransitionsFor($sekre, 'DIDISPOSISIKAN', 'LANGSUNG'), 'toStage'));
        $this->assertSame(['DITERUSKAN_KE_SEKRETARIS_PANITERA', 'MENUNGGU_KEBIJAKAN_PIMPINAN'],
            array_column(V2Workflow::allowedTransitionsFor($sekre, 'DIDISPOSISIKAN', 'KEBIJAKAN'), 'toStage'));
    }

    /** Regresi: rute TIDAK boleh mengunci selamanya setelah surat keluar dari DIDISPOSISIKAN. */
    public function testRouteDoesNotLockDownstreamStages(): void
    {
        $this->assertSame(['DALAM_TINDAK_LANJUT'],
            array_column(V2Workflow::allowedTransitionsFor(['role' => 'STAFF'], 'DITERUSKAN_KE_PELAKSANA', 'LANGSUNG'), 'toStage'),
            'surat rute LANGSUNG harus tetap bisa maju ke tindak lanjut');
        // Sesudah pimpinan memberi arahan, surat kembali ke Sekretaris/Panitera;
        // di sanalah "teruskan ke unit pelaksana" dijalankan (SOP langkah 16).
        $sekreOps = array_column(V2Workflow::allowedTransitionsFor(['role' => 'SEKRETARIS'], 'DITERUSKAN_KE_SEKRETARIS_PANITERA', 'KEBIJAKAN'), 'toStage');
        $this->assertContains('DITERUSKAN_KE_PELAKSANA', $sekreOps,
            'surat rute KEBIJAKAN harus tetap bisa diteruskan ke pelaksana oleh Sekretaris');
        // Pimpinan hanya punya jalan mengembalikan surat ke Sekretaris (beri arahan).
        $this->assertSame(['DITERUSKAN_KE_SEKRETARIS_PANITERA'],
            array_column(V2Workflow::allowedTransitionsFor(['role' => 'PIMPINAN'], 'MENUNGGU_KEBIJAKAN_PIMPINAN', 'KEBIJAKAN'), 'toStage'));
    }

    /** Label tombol per transisi + fallback ke nama tahap. */
    public function testTransitionLabel(): void
    {
        $this->assertSame('Teruskan langsung ke pelaksana', V2Workflow::transitionLabel('DIDISPOSISIKAN', 'DITERUSKAN_KE_PELAKSANA'));
        $this->assertSame('Teruskan ke pimpinan (kebijakan)', V2Workflow::transitionLabel('DIDISPOSISIKAN', 'MENUNGGU_KEBIJAKAN_PIMPINAN'));
        $this->assertSame('DALAM_TINDAK_LANJUT', V2Workflow::transitionLabel('DITERUSKAN_KE_PELAKSANA', 'DALAM_TINDAK_LANJUT'));
    }

    /** Label rute untuk log audit. */
    public function testRouteLabel(): void
    {
        $this->assertSame('Perlu kebijakan pimpinan', V2Workflow::routeLabel('KEBIJAKAN'));
        $this->assertSame('Langsung ke unit pelaksana', V2Workflow::routeLabel('langsung'));
        $this->assertSame('-', V2Workflow::routeLabel(null));
    }

    /** KMA 131 BAB V: isi surat Rahasia/Sangat Rahasia tidak boleh masuk notifikasi WA. */
    public function testWithholdWaContent(): void
    {
        foreach (['RAHASIA', 'SANGAT_RAHASIA', 'rahasia'] as $sensitif) {
            $this->assertTrue(V2Workflow::mustWithholdWaContent($sensitif), "level $sensitif harus ditahan");
        }
        foreach (['BIASA', 'TERBATAS', null] as $aman) {
            $this->assertFalse(V2Workflow::mustWithholdWaContent($aman), 'level non-rahasia boleh tampil');
        }
    }

    /**
     * AS-9: batas tahap untuk koreksi/hapus data surat (menu "Surat Masuk" lama
     * difungsikan ulang di Buku Kendali). Boleh selama BELUM ada keputusan
     * disposisi; dikunci sejak DIDISPOSISIKAN karena salinan instruksi sudah
     * beredar ke pimpinan/pelaksana.
     *
     * Syarat kedua - surat belum punya baris disposisi - diuji pada
     * letterAllowsCorrection() lewat test_e2e.php (butuh database).
     */
    public function testStageAllowsCorrection(): void
    {
        foreach (V2Workflow::CORRECTABLE_STAGES as $stage) {
            $this->assertTrue(V2Workflow::stageAllowsCorrection($stage), "$stage harus boleh dikoreksi");
        }
        foreach (['DIDISPOSISIKAN', 'DITERUSKAN_KE_KASUBAG', 'MENUNGGU_KEBIJAKAN_PIMPINAN', 'DIARSIPKAN'] as $stage) {
            $this->assertFalse(V2Workflow::stageAllowsCorrection($stage), "$stage sudah terkunci (surat beredar)");
        }
        // Nilai kosong/asing TIDAK boleh lolos sebagai "boleh diubah".
        foreach ([null, '', '   ', 'NGAWUR', 'DITERIMA; DROP TABLE'] as $kosong) {
            $this->assertFalse(V2Workflow::stageAllowsCorrection($kosong), 'tahap tak dikenal harus ditolak');
        }
        // Spasi/kapitalisasi tidak boleh membuat aturan gagal cocok.
        $this->assertTrue(V2Workflow::stageAllowsCorrection(' diterima '));
    }

    /** Pesan penolakan koreksi harus menyebut tahap saat ini + tahap yang masih boleh. */
    public function testCorrectionBlockedMessage(): void
    {
        $msg = V2Workflow::correctionBlockedMessage('DIDISPOSISIKAN');
        $this->assertStringContainsString('DIDISPOSISIKAN', $msg);
        $this->assertStringContainsString('DITERIMA', $msg, 'pesan harus menyebut tahap yang masih boleh dikoreksi');
    }

    /**
     * Fase 1 (keputusan #1): laporan pelaksana (PROSES/SELESAI) menggerakkan
     * tahap surat. Fungsi perencanaannya MURNI supaya aturan ini bisa dikunci
     * di sini tanpa database; penulisan DB-nya diuji di test_e2e.php.
     */
    public function testPlanAutoAdvance(): void
    {
        // PROSES dari rantai pelaksanaan -> satu langkah.
        $this->assertSame([
            'applied' => true, 'reason' => 'DITERAPKAN',
            'target' => 'DALAM_TINDAK_LANJUT', 'path' => ['DALAM_TINDAK_LANJUT'],
        ], V2Workflow::planAutoAdvance('DITERUSKAN_KE_PELAKSANA', 'PROSES'));

        // SELESAI: pelaksana sering tak pernah melapor PROSES -> dua langkah sekaligus.
        $plan = V2Workflow::planAutoAdvance('DITERUSKAN_KE_PELAKSANA', 'SELESAI');
        $this->assertTrue($plan['applied']);
        $this->assertSame(['DALAM_TINDAK_LANJUT', 'SELESAI_DITINDAKLANJUTI'], $plan['path']);
        $this->assertSame('SELESAI_DITINDAKLANJUTI', $plan['target']);

        // Idempoten: laporan ulang tidak menaikkan tahap lagi (anti double-advance).
        foreach (['DALAM_TINDAK_LANJUT' => 'PROSES', 'SELESAI_DITINDAKLANJUTI' => 'SELESAI'] as $stage => $status) {
            $again = V2Workflow::planAutoAdvance($stage, $status);
            $this->assertFalse($again['applied'], "$stage + $status tidak boleh mengubah tahap");
            $this->assertSame('SUDAH_PADA_TAHAP', $again['reason']);
            $this->assertSame([], $again['path']);
        }

        // Surat yang belum sampai rantai pelaksanaan TIDAK boleh dilompati.
        foreach (['DITERUSKAN_KE_KASUBAG', 'MENUNGGU_DISPOSISI', 'DIDISPOSISIKAN',
                  'MENUNGGU_KEBIJAKAN_PIMPINAN', 'DIARSIPKAN', 'DITERIMA'] as $stage) {
            $blocked = V2Workflow::planAutoAdvance($stage, 'SELESAI');
            $this->assertFalse($blocked['applied'], "$stage tidak boleh auto-advance");
            $this->assertSame('BUKAN_TAHAP_PELAKSANAAN', $blocked['reason'], $stage);
        }

        // Status non-laporan tidak menggerakkan apa pun.
        $pending = V2Workflow::planAutoAdvance('DITERUSKAN_KE_PELAKSANA', 'PENDING');
        $this->assertFalse($pending['applied']);
        $this->assertSame('STATUS_IRRELEVAN', $pending['reason']);

        // Tahap asal kosong/asing tak pernah menghasilkan langkah.
        foreach ([null, '', '   ', 'NGAWUR'] as $kosong) {
            $this->assertFalse(V2Workflow::planAutoAdvance($kosong, 'PROSES')['applied'], var_export($kosong, true));
        }
    }

    /** Pemetaan status -> tahap tujuan + rantai yang dihasilkan selalu sah. */
    public function testDispositionEventStages(): void
    {
        $this->assertSame('DALAM_TINDAK_LANJUT', V2Workflow::stageForDispositionEvent('PROSES'));
        $this->assertSame('SELESAI_DITINDAKLANJUTI', V2Workflow::stageForDispositionEvent('selesai'));
        $this->assertNull(V2Workflow::stageForDispositionEvent('PENDING'));
        $this->assertNull(V2Workflow::stageForDispositionEvent(null));

        foreach (V2Workflow::DISPOSITION_EVENT_STAGES as $status => $stage) {
            $this->assertContains($stage, V2Workflow::STAGES, "tahap $stage harus tahap resmi");
            foreach (V2Workflow::autoAdvanceSourcesForEvent($status) as $src) {
                $this->assertContains($src, V2Workflow::STAGES, "tahap asal $src harus tahap resmi");
                $plan = V2Workflow::planAutoAdvance($src, $status);
                $this->assertTrue($plan['applied'], "$status dari $src harus bisa maju");
                // Setiap mata rantai wajib sah menurut state machine resmi.
                $prev = $src;
                foreach ($plan['path'] as $next) {
                    $this->assertTrue(V2Workflow::canTransition($prev, $next), "$prev -> $next harus sah");
                    $prev = $next;
                }
                $this->assertSame($stage, $prev, 'tahap akhir rantai harus = tahap tujuan');
            }
        }
    }

    /**
     * Fase 2: siapa yang harus diberi tahu saat surat masuk ke sebuah tahap.
     * Sebelum ini Kasubag/Sekretaris/Panitera tidak pernah menerima notifikasi.
     */
    public function testStageOwners(): void
    {
        $this->assertSame(['KEPALA_SUB_UMUM'], V2Workflow::stageOwners('DITERUSKAN_KE_KASUBAG'));
        $this->assertContains('SEKRETARIS', V2Workflow::stageOwners('MENUNGGU_DISPOSISI'));
        $this->assertContains('PANITERA', V2Workflow::stageOwners('MENUNGGU_DISPOSISI'));
        $this->assertSame(['PIMPINAN', 'WAKIL_KETUA'], V2Workflow::stageOwners('MENUNGGU_KEBIJAKAN_PIMPINAN'));
        // P8 (revisi kedua): Arsiparis ikut memegang MENUNGGU_PENGARSIPAN.
        $this->assertSame(['SEKRETARIS', 'PANITERA', 'ARSIPARIS'], V2Workflow::stageOwners('menunggu_pengarsipan'));
        // P2 (revisi kedua): tahap awal = meja Kasubag Umum saja (pengarah
        // surat, langkah 4-11); Sekretaris jadi pemegang mulai langkah 13.
        $this->assertSame(['KEPALA_SUB_UMUM'], V2Workflow::stageOwners('MENUNGGU_PENGARAHAN'));
        $this->assertSame(['KEPALA_SUB_UMUM'], V2Workflow::stageOwners('DIBACA_PENGARAH'));
        // Tahap pelaksanaan pemegangnya = assignee surat, bukan role.
        $this->assertSame([], V2Workflow::stageOwners('DITERUSKAN_KE_PELAKSANA'));
        $this->assertSame([], V2Workflow::stageOwners('DALAM_TINDAK_LANJUT'));
        foreach ([null, '', 'NGAWUR'] as $kosong) {
            $this->assertSame([], V2Workflow::stageOwners($kosong));
        }
        // ADMIN tidak boleh dibanjiri notifikasi tiap perpindahan.
        foreach (V2Workflow::STAGE_OWNER_ROLES as $stage => $roles) {
            $this->assertContains($stage, V2Workflow::STAGES, "tahap $stage harus tahap resmi");
            $this->assertNotContains('ADMIN', $roles, "tahap $stage tak boleh menyasar ADMIN");
            $this->assertNotEmpty($roles, "tahap $stage harus punya daftar role");
        }
    }

    /**
     * K3 (DEFAULT SEMENTARA - menunggu review pimpinan): kanal koreksi kini
     * hanya ADMIN. Aturan lama "Kasubag Umum boleh menarik surat dari rantai
     * pelaksanaan" DIGANTI: pemegang surat TOLAK satu langkah, pengirim RECALL
     * sebelum penerima bertindak (lihat docs/CATATAN_ASUMSI_REVISI.md bag. 10).
     */
    public function testReopenRules(): void
    {
        $kasubag = ['id' => 'u-kasubag', 'role' => 'KEPALA_SUB_UMUM'];
        $admin   = ['id' => 'u-admin', 'role' => 'ADMIN'];

        // Koreksi kasus khusus: hanya ADMIN, pada tahap yang dikenal.
        foreach (V2Workflow::REOPEN_ADMIN_STAGES as $stage) {
            $this->assertTrue(V2Workflow::canReopenLetter($admin, $stage), "admin: $stage");
            $this->assertFalse(V2Workflow::canReopenLetter($kasubag, $stage), "kasubag bukan kanal koreksi: $stage");
        }
        foreach (['DIARSIPKAN', 'DITERIMA', 'MENUNGGU_DISPOSISI', 'DIDISPOSISIKAN', 'DITERUSKAN_KE_KASUBAG'] as $stage) {
            $this->assertFalse(V2Workflow::canReopenLetter($kasubag, $stage), "tidak boleh ditarik: $stage");
        }
        foreach ([null, '', 'NGAWUR'] as $kosong) {
            $this->assertFalse(V2Workflow::canReopenLetter($kasubag, $kosong));
        }
        // Sekretaris/Panitera/Pimpinan/Staff bukan kanal koreksi (mereka
        // memakai TOLAK/RECALL lewat planReject, bukan kanal ADMIN).
        foreach (['SEKRETARIS', 'PANITERA', 'PIMPINAN', 'WAKIL_KETUA', 'STAFF', '', null] as $role) {
            $this->assertSame([], V2Workflow::reopenableStagesFor(['role' => $role]), 'role: ' . var_export($role, true));
            $this->assertFalse(V2Workflow::canReopenLetter(['role' => $role], 'DALAM_TINDAK_LANJUT'));
        }
        $this->assertFalse(V2Workflow::canReopenLetter(null, 'DALAM_TINDAK_LANJUT'));

        // Target koreksi ADMIN = meja Kasubag Umum.
        $this->assertSame('DITERUSKAN_KE_KASUBAG', V2Workflow::REOPEN_TARGET_STAGE);
        // Gerak mundur BUKAN bagian state machine maju (K3): tidak bisa
        // dipakai lewat transisi biasa, punya pintu + action log sendiri
        // (STAGE_TOLAK/STAGE_RECALL/STAGE_REOPEN) agar bisa dibedakan saat
        // diaudit (KMA 131 BAB IV).
        foreach (['DALAM_TINDAK_LANJUT', 'DITERUSKAN_KE_PELAKSANA', 'DITERUSKAN_KE_SEKRETARIS_PANITERA', 'SELESAI_DITINDAKLANJUTI'] as $s) {
            $this->assertFalse(V2Workflow::canTransition($s, 'DITERUSKAN_KE_KASUBAG'), "$s -> DITERUSKAN_KE_KASUBAG bukan transisi maju");
        }
    }

    /** K3/P8: semua key/value peta TOLAK/RECALL/keputusan harus tahap resmi. */
    public function testRejectMapsUseOfficialStages(): void
    {
        foreach (V2Workflow::TOLAK_TARGETS as $from => $to) {
            $this->assertContains($from, V2Workflow::STAGES, "TOLAK $from harus tahap resmi");
            $this->assertContains($to, V2Workflow::STAGES, "TOLAK target $to harus tahap resmi");
        }
        foreach (V2Workflow::RECALL_TARGETS as $from => $to) {
            $this->assertContains($from, V2Workflow::STAGES, "RECALL $from harus tahap resmi");
            $this->assertContains($to, V2Workflow::STAGES, "RECALL target $to harus tahap resmi");
        }
        foreach (V2Workflow::DECISION_ALLOWED_STAGES as $s) {
            $this->assertContains($s, V2Workflow::STAGES, "DECISION_ALLOWED $s harus tahap resmi");
        }
        foreach (V2Workflow::TERUSKAN_ALLOWED_STAGES as $s) {
            $this->assertContains($s, V2Workflow::STAGES, "TERUSKAN_ALLOWED $s harus tahap resmi");
        }
    }

    /** K3: planReject — TOLAK pemegang, RECALL pengirim, Ketua/WK tanpa TOLAK. */
    public function testPlanRejectK3(): void
    {
        $pegawai  = ['id' => 'u-staff', 'role' => 'STAFF'];
        $kasubag  = ['id' => 'u-kasubag', 'role' => 'KEPALA_SUB_UMUM'];
        $sekre    = ['id' => 'u-sekre', 'role' => 'SEKRETARIS'];
        $pimpinan = ['id' => 'u-pimp', 'role' => 'PIMPINAN'];

        // 1) Pegawai (assignee) TOLAK dari DALAM_TINDAK_LANJUT -> meja unit.
        $res = V2Workflow::planReject($pegawai, ['currentStage' => 'DALAM_TINDAK_LANJUT', 'assigneeUserId' => 'u-staff']);
        $this->assertTrue($res['ok']);
        $this->assertSame('TOLAK', $res['mode']);
        $this->assertSame('DITERUSKAN_KE_PELAKSANA', $res['to']);
        // Pegawai BUKAN assignee -> tidak berwenang.
        $res = V2Workflow::planReject($pegawai, ['currentStage' => 'DALAM_TINDAK_LANJUT', 'assigneeUserId' => 'u-lain']);
        $this->assertFalse($res['ok']);

        // 2) Kepala unit TOLAK dari DITERUSKAN_KE_PELAKSANA -> Sekretaris/Panitera.
        $res = V2Workflow::planReject($kasubag, ['currentStage' => 'DITERUSKAN_KE_PELAKSANA', 'unitTujuan' => 'KASUBAG_UMUM']);
        $this->assertTrue($res['ok']);
        $this->assertSame('TOLAK', $res['mode']);
        $this->assertSame('DITERUSKAN_KE_SEKRETARIS_PANITERA', $res['to']);
        // Kepala unit BERBEDA unit -> ditolak (unit sudah terisi).
        $res = V2Workflow::planReject(['id' => 'u-ptip', 'role' => 'KEPALA_SUB_PTIP'],
            ['currentStage' => 'DITERUSKAN_KE_PELAKSANA', 'unitTujuan' => 'KASUBAG_UMUM']);
        $this->assertFalse($res['ok']);

        // 3) Sekretaris TOLAK dari DITERUSKAN_KE_SEKRETARIS_PANITERA -> Kasubag.
        $res = V2Workflow::planReject($sekre, ['currentStage' => 'DITERUSKAN_KE_SEKRETARIS_PANITERA']);
        $this->assertTrue($res['ok']);
        $this->assertSame('DITERUSKAN_KE_KASUBAG', $res['to']);

        // 4) Ketua/WK tidak punya TOLAK (Q1: arahan final).
        foreach (['MENUNGGU_KEBIJAKAN_PIMPINAN', 'DITERUSKAN_KE_SEKRETARIS_PANITERA'] as $stage) {
            $res = V2Workflow::planReject($pimpinan, ['currentStage' => $stage]);
            $this->assertFalse($res['ok'], "pimpinan tanpa TOLAK di $stage");
        }

        // 5) RECALL Kasubag: kiriman ke meja Sekretaris, penerima belum bertindak.
        $fresh = ['actor_user_id' => 'u-kasubag', 'from_stage' => 'DITERUSKAN_KE_KASUBAG', 'to_stage' => 'DITERUSKAN_KE_SEKRETARIS_PANITERA'];
        $res = V2Workflow::planReject($kasubag, ['currentStage' => 'DITERUSKAN_KE_SEKRETARIS_PANITERA'], $fresh);
        $this->assertTrue($res['ok']);
        $this->assertSame('RECALL', $res['mode']);
        $this->assertSame('DITERUSKAN_KE_KASUBAG', $res['to']);

        // Penerima SUDAH bertindak di tempat (mis. TUNJUK: from == to) ->
        // recall Sekretaris dari DITERUSKAN_KE_PELAKSANA ditolak.
        $tunjuk = ['actor_user_id' => 'u-kasubag', 'from_stage' => 'DITERUSKAN_KE_PELAKSANA', 'to_stage' => 'DITERUSKAN_KE_PELAKSANA'];
        $res = V2Workflow::planReject($sekre, ['currentStage' => 'DITERUSKAN_KE_PELAKSANA'], $tunjuk);
        $this->assertFalse($res['ok'], 'recall ditolak setelah penerima bertindak');

        // Kedatangan oleh orang lain (bukan pengirimnya) -> bukan recall dia.
        $byAdmin = ['actor_user_id' => 'u-admin', 'from_stage' => 'DITERUSKAN_KE_KASUBAG', 'to_stage' => 'DITERUSKAN_KE_SEKRETARIS_PANITERA'];
        $res = V2Workflow::planReject($kasubag, ['currentStage' => 'DITERUSKAN_KE_SEKRETARIS_PANITERA'], $byAdmin);
        $this->assertFalse($res['ok']);

        // 6) ADMIN koreksi kasus khusus tetap boleh (tercatat).
        $res = V2Workflow::planReject(['id' => 'u-admin', 'role' => 'ADMIN'], ['currentStage' => 'MENUNGGU_KEBIJAKAN_PIMPINAN']);
        $this->assertTrue($res['ok']);
        $this->assertSame('ADMIN_REOPEN', $res['mode']);
    }

    /** Alasan penarikan wajib & cukup spesifik supaya berguna saat diaudit. */
    public function testValidateReopenReason(): void
    {
        foreach ([null, '', '   ', 'ok', 'salah'] as $buruk) {
            [$ok, $msg] = V2Workflow::validateReopenReason($buruk);
            $this->assertFalse($ok, var_export($buruk, true) . ' harus ditolak');
            $this->assertNotEmpty($msg);
        }
        [$ok, $msg] = V2Workflow::validateReopenReason('  Salah unit pelaksana, harusnya Subbag Kepegawaian  ');
        $this->assertTrue($ok);
        $this->assertNull($msg);
    }

    /**
     * Kontrak nilai enum server = cerminan src/lib/v2Workflow.ts (UI). Uji ini
     * memaksa keduanya diperiksa ulang setiap kali salah satu daftar berubah,
     * supaya dropdown UI tidak pernah menawarkan nilai yang ditolak server.
     */
    public function testEnumContractsMatchUi(): void
    {
        $this->assertSame(['DINAS', 'PRIBADI'], V2Workflow::LETTER_CATEGORIES);
        $this->assertSame(['NORMAL', 'SEGERA', 'PENTING'], V2Workflow::URGENCY_LEVELS);
        $this->assertSame(['POS', 'KURIR', 'EMAIL', 'FAX', 'INTERNAL', 'LAINNYA'], V2Workflow::SOURCE_CHANNELS);
        $this->assertContains('SURAT_DINAS', V2Workflow::DOCUMENT_TYPES);
        $this->assertCount(17, V2Workflow::STAGES, 'kontrak 17 tahap tidak boleh berubah tanpa sadar');
    }
}
