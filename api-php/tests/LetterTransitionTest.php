<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/compat.php';
require_once __DIR__ . '/../lib/V2Workflow.php';
require_once __DIR__ . '/../lib/LetterTransition.php';
require_once __DIR__ . '/../lib/WaStageNotifier.php';
require_once __DIR__ . '/../lib/Wabot.php';

/**
 * Penjaga (guard) satu pintu perpindahan tahap.
 *
 * Yang diuji di sini adalah SEMUA jalur yang MENOLAK — jalur ini berhenti sebelum
 * menyentuh database, jadi bisa dikunci tanpa MySQL. Penulisan tahap yang
 * berhasil + jejak audit + notifikasi WA diuji terhadap MySQL nyata di
 * tests/run_fase1_check.php.
 */
final class LetterTransitionTest extends TestCase
{
    private function letter(string $stage = 'DITERUSKAN_KE_PELAKSANA', ?string $route = null, ?string $assignee = null): array
    {
        return ['id' => 'L1', 'currentStage' => $stage, 'dispositionRoute' => $route,
            'assigneeUserId' => $assignee, 'subject' => 'Surat uji'];
    }

    /** Tahap sama = tidak ada yang dikerjakan (bukan error server). */
    public function testSameStageRejected(): void
    {
        $res = LetterTransition::apply(['id' => 'u1', 'role' => 'SEKRETARIS'],
            $this->letter('DALAM_TINDAK_LANJUT'), 'DALAM_TINDAK_LANJUT');
        $this->assertFalse($res['ok']);
        $this->assertSame('SAME_STAGE', $res['code']);
        $this->assertSame([], $res['steps']);
    }

    /** Transisi di luar state machine ditolak sebelum role diperiksa. */
    public function testIllegalTransitionRejected(): void
    {
        foreach ([['DITERUSKAN_KE_PELAKSANA', 'DIARSIPKAN'], ['DITERIMA', 'DIDISPOSISIKAN'], ['', 'DIARSIPKAN']] as [$from, $to]) {
            $res = LetterTransition::apply(['id' => 'u1', 'role' => 'ADMIN'], $this->letter($from), $to);
            $this->assertFalse($res['ok'], "$from -> $to harus ditolak");
            $this->assertSame('ILLEGAL_TRANSITION', $res['code'], "$from -> $to");
        }
    }

    /** Role yang tidak berwenang ditolak walau transisinya sah. */
    public function testRoleDenied(): void
    {
        // STAFF bukan pengarah surat (SOP/AS/04 langkah 4-7).
        $res = LetterTransition::apply(['id' => 'u1', 'role' => 'STAFF'],
            $this->letter('MENUNGGU_PENGARAHAN'), 'DIBACA_PENGARAH');
        $this->assertFalse($res['ok']);
        $this->assertSame('ROLE_DENIED', $res['code']);

        // Pimpinan tidak meneruskan surat ke pelaksana. Sejak revisi SOP/AS/04
        // langkah 14-16, jalur itu bahkan BUKAN transisi sah lagi (pimpinan hanya
        // mengembalikan surat ke Sekretaris beserta arahan) — jadi penolakan
        // datang lebih awal sebagai ILLEGAL_TRANSITION.
        $res = LetterTransition::apply(['id' => 'u2', 'role' => 'PIMPINAN'],
            $this->letter('MENUNGGU_KEBIJAKAN_PIMPINAN'), 'DITERUSKAN_KE_PELAKSANA');
        $this->assertFalse($res['ok']);
        $this->assertSame('ILLEGAL_TRANSITION', $res['code']);

        // Sekretaris juga tidak boleh melompat ke pelaksana dari meja pimpinan;
        // ia baru melakukannya SETELAH surat kembali ke mejanya (berikut arahan).
        $res = LetterTransition::apply(['id' => 'u3', 'role' => 'SEKRETARIS'],
            $this->letter('MENUNGGU_KEBIJAKAN_PIMPINAN'), 'DITERUSKAN_KE_PELAKSANA');
        $this->assertFalse($res['ok']);
        $this->assertSame('ILLEGAL_TRANSITION', $res['code']);
    }

    /** Masuk DIDISPOSISIKAN wajib membawa rute keputusan (SOP langkah 13). */
    public function testRouteRequiredForDecision(): void
    {
        foreach ([null, '', 'NGAWUR'] as $rute) {
            $res = LetterTransition::apply(['id' => 'u1', 'role' => 'SEKRETARIS'],
                $this->letter('MENUNGGU_DISPOSISI'), 'DIDISPOSISIKAN', ['route' => $rute]);
            $this->assertFalse($res['ok'], 'rute ' . var_export($rute, true) . ' harus ditolak');
            $this->assertSame('ROUTE_REQUIRED', $res['code']);
        }
        // Jalur pintas TERREGISTRASI -> DIDISPOSISIKAN tidak boleh melewatinya.
        $res = LetterTransition::apply(['id' => 'u1', 'role' => 'PANITERA'],
            $this->letter('TERREGISTRASI'), 'DIDISPOSISIKAN');
        $this->assertSame('ROUTE_REQUIRED', $res['code']);
    }

    /** Rute yang sudah diputuskan mengunci percabangan sesudah DIDISPOSISIKAN. */
    public function testRouteLockedBranch(): void
    {
        // Revisi SOP/AS/04: KEBIJAKAN -> ke Sekretaris lalu pimpinan;
        // LANGSUNG -> ke Sekretaris lalu unit. Keduanya lewat Sekretaris.
        $res = LetterTransition::apply(['id' => 'u1', 'role' => 'SEKRETARIS'],
            $this->letter('DIDISPOSISIKAN', 'LANGSUNG'), 'MENUNGGU_KEBIJAKAN_PIMPINAN');
        $this->assertFalse($res['ok']);
        $this->assertSame('ROUTE_LOCKED', $res['code']);

        $res = LetterTransition::apply(['id' => 'u1', 'role' => 'SEKRETARIS'],
            $this->letter('DIDISPOSISIKAN', 'KEBIJAKAN'), 'DITERUSKAN_KE_PELAKSANA');
        $this->assertFalse($res['ok']);
        $this->assertSame('ROUTE_LOCKED', $res['code']);
    }

    /** Fix 1: Kasubag TIDAK BOLEH menetapkan rute final (hanya Sekretaris). */
    public function testKasubagCannotDecideFinalRoute(): void
    {
        $res = LetterTransition::apply(['id' => 'u1', 'role' => 'KEPALA_SUB_UMUM'],
            $this->letter('MENUNGGU_DISPOSISI'), 'DIDISPOSISIKAN', ['route' => 'LANGSUNG']);
        $this->assertFalse($res['ok']);
        $this->assertSame('ROLE_DENIED', $res['code']);
    }

    /** Fix 3: ke unit pelaksana wajib ada unit_tujuan. */
    public function testUnitTujuanRequired(): void
    {
        $res = LetterTransition::apply(['id' => 'u1', 'role' => 'SEKRETARIS'],
            $this->letter('DITERUSKAN_KE_SEKRETARIS_PANITERA', 'LANGSUNG'), 'DITERUSKAN_KE_PELAKSANA');
        $this->assertFalse($res['ok']);
        $this->assertSame('UNIT_TUJUAN_REQUIRED', $res['code']);

        $res = LetterTransition::apply(['id' => 'u1', 'role' => 'SEKRETARIS'],
            $this->letter('DITERUSKAN_KE_SEKRETARIS_PANITERA', 'LANGSUNG'), 'DITERUSKAN_KE_PELAKSANA',
            ['unit_tujuan' => 'UNIT_NGAWUR']);
        $this->assertFalse($res['ok']);
        $this->assertSame('UNIT_TUJUAN_INVALID', $res['code']);
    }

    /** Fix 4: lompatan langsung ke pegawai perorangan ditolak. */
    public function testDirectAssignDenied(): void
    {
        $res = LetterTransition::apply(['id' => 'u1', 'role' => 'SEKRETARIS'],
            $this->letter('DITERUSKAN_KE_SEKRETARIS_PANITERA', 'LANGSUNG'), 'DITERUSKAN_KE_PELAKSANA',
            ['unit_tujuan' => 'KASUBAG_UMUM', 'assignee_user_id' => 'pegawai-1']);
        $this->assertFalse($res['ok']);
        $this->assertSame('DIRECT_ASSIGN_DENIED', $res['code']);
    }

    /** Arahan pimpinan wajib (Q1: final) saat kembali ke Sekretaris. */
    public function testArahanRequired(): void
    {
        $res = LetterTransition::apply(['id' => 'u1', 'role' => 'PIMPINAN'],
            $this->letter('MENUNGGU_KEBIJAKAN_PIMPINAN'), 'DITERUSKAN_KE_SEKRETARIS_PANITERA');
        $this->assertFalse($res['ok']);
        $this->assertSame('ARAHAN_REQUIRED', $res['code']);
    }

    /** Fix 5 (Q2): DIARSIPKAN hanya ARSIPARIS/Admin. */
    public function testOnlyArsiparisCanArchive(): void
    {
        $res = LetterTransition::apply(['id' => 'u1', 'role' => 'STAFF'],
            $this->letter('MENUNGGU_PENGARSIPAN'), 'DIARSIPKAN');
        $this->assertFalse($res['ok']);
        $this->assertSame('ROLE_DENIED', $res['code']);
    }

    /**
     * Penarikan kembali: kebijakan role + alasan wajib juga berhenti sebelum DB,
     * jadi bisa dikunci di sini.
     */
    public function testReopenGuards(): void
    {
        $kasubag = ['id' => 'u-kasubag', 'role' => 'KEPALA_SUB_UMUM'];
        $alasan = 'Salah unit pelaksana, harusnya Subbag Kepegawaian';

        // Tahap yang tidak boleh ditarik (surat masih di meja pemutus/kepala).
        foreach (['MENUNGGU_DISPOSISI', 'DIDISPOSISIKAN', 'DIARSIPKAN', 'DITERIMA'] as $stage) {
            $res = LetterTransition::reopen($kasubag, $this->letter($stage), $alasan);
            $this->assertFalse($res['ok'], "$stage tidak boleh ditarik");
            $this->assertSame('REOPEN_DENIED', $res['code'], $stage);
        }

        // Role yang bukan pemegang rantai pelaksanaan.
        foreach (['SEKRETARIS', 'PANITERA', 'PIMPINAN', 'STAFF'] as $role) {
            $res = LetterTransition::reopen(['id' => 'u1', 'role' => $role],
                $this->letter('DALAM_TINDAK_LANJUT'), $alasan);
            $this->assertSame('REOPEN_DENIED', $res['code'], "role $role harus ditolak");
        }

        // Alasan wajib: diperiksa SETELAH wewenang, sebelum menyentuh DB.
        // K3: pemegang sah di DALAM_TINDAK_LANJUT = pegawai assignee.
        $res = LetterTransition::reopen(['id' => 'u-staff', 'role' => 'STAFF'],
            $this->letter('DALAM_TINDAK_LANJUT', null, 'u-staff'), '  ');
        $this->assertFalse($res['ok']);
        $this->assertSame('REOPEN_REASON', $res['code']);
    }

    /**
     * P8 (revisi kedua): semua key peta tahap WA harus tahap resmi
     * V2Workflow::STAGES. Typo key membuat lookup gagal DIAM-DIAM (penerima
     * tidak diberi tahu) — uji ini sengaja gagal bila ada key tak dikenali.
     */
    public function testWaStageMapsUseOfficialStages(): void
    {
        $ref = new ReflectionClass('WaStageNotifier');
        $kindMap = $ref->getConstant('KIND_BY_STAGE');
        $this->assertNotEmpty($kindMap, 'KIND_BY_STAGE harus terbaca via refleksi');
        foreach (array_keys($kindMap) as $stage) {
            $this->assertContains($stage, V2Workflow::STAGES, "KIND_BY_STAGE key '$stage' harus tahap resmi");
        }
        foreach (array_keys(V2Workflow::STAGE_OWNER_ROLES) as $stage) {
            $this->assertContains($stage, V2Workflow::STAGES, "STAGE_OWNER_ROLES key '$stage' harus tahap resmi");
        }
        foreach (V2Workflow::DISPOSITION_EVENT_STAGES as $stage) {
            $this->assertContains($stage, V2Workflow::STAGES, "DISPOSITION_EVENT_STAGES '$stage' harus tahap resmi");
        }
    }

    /** Pemetaan tahap -> jenis sesi WA (Fase 2). */
    public function testStageSessionKinds(): void
    {
        $this->assertSame('DEKISION', WaStageNotifier::stageSessionKind('MENUNGGU_DISPOSISI'));
        $this->assertSame('DEKISION', WaStageNotifier::stageSessionKind('DIDISPOSISIKAN'));
        $this->assertSame('ARCHIVE', WaStageNotifier::stageSessionKind('MENUNGGU_PENGARSIPAN'));
        $this->assertSame('ARCHIVE', WaStageNotifier::stageSessionKind('SELESAI_DITINDAKLANJUTI'));
        $this->assertSame('VERIFIKASI', WaStageNotifier::stageSessionKind('DITERUSKAN_KE_KASUBAG'));
        $this->assertSame('VERIFIKASI', WaStageNotifier::stageSessionKind('MENUNGGU_KEBIJAKAN_PIMPINAN'));
        // Tahap tak dikenal tidak boleh menghasilkan jenis sesi (jangan menebak).
        foreach ([null, '', 'NGAWUR'] as $kosong) {
            $this->assertNull(WaStageNotifier::stageSessionKind($kosong));
        }
        // Jenis sesi harus muat kolom wa_sessions.kind VARCHAR(20).
        foreach (WaStageNotifier::STAGE_KINDS as $kind) {
            $this->assertLessThanOrEqual(20, strlen($kind), $kind);
            $this->assertSame($kind, strtoupper($kind));
        }
    }
}
