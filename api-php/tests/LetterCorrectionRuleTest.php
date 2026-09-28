<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/V2Workflow.php';
require_once __DIR__ . '/../lib/helpers.php';

/**
 * Aturan "surat masih boleh dikoreksi/dihapus" (dipakai PUT & DELETE
 * /api/incoming/:id dan penentuan tombol Edit/Hapus di Buku Kendali).
 *
 * Dua syarat, DAN: tahap masih di bawah keputusan disposisi, serta surat belum
 * punya baris disposisi. Syarat kedua inilah yang mencegah orang menghapus
 * surat yang instruksinya sudah beredar walau tahapnya masih "awal".
 */
final class LetterCorrectionRuleTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE dispositions (id TEXT PRIMARY KEY, incoming_letter_id TEXT)');
        Db::$pdo = $db;
        return $db;
    }

    /** Sebelum keputusan disposisi & tanpa disposisi: boleh dikoreksi. */
    public function testAllowedBeforeDecisionWithoutDispositions(): void
    {
        $this->db();
        foreach (['DITERIMA', 'DISORTIR', 'TERREGISTRASI', 'MENUNGGU_DISPOSISI'] as $stage) {
            $this->assertTrue(letterAllowsCorrection($stage, 'L1'), "tahap $stage harus boleh dikoreksi");
        }
    }

    /** Ada disposisi = surat sudah beredar: terkunci walau tahapnya masih awal. */
    public function testDispositionLocksCorrectionEvenAtEarlyStage(): void
    {
        $db = $this->db();
        $db->prepare("INSERT INTO dispositions (id, incoming_letter_id) VALUES ('d1', 'L1')")->execute();
        $this->assertTrue(letterHasDispositions('L1'));
        $this->assertFalse(letterAllowsCorrection('DITERIMA', 'L1'),
            'surat berdisposisi tidak boleh diubah walau masih DITERIMA');
        $this->assertStringContainsString('disposisi', correctionBlockedReason('DITERIMA', 'L1'));
    }

    /** Alasan penolakan harus menyebut tahap yang benar-benar menghalangi. */
    public function testBlockedReasonPointsToTheRealCause(): void
    {
        $this->db();
        $this->assertStringContainsString('DIDISPOSISIKAN', correctionBlockedReason('DIDISPOSISIKAN', 'L2'));
        $this->assertStringContainsString('DIARSIPKAN', correctionBlockedReason('DIARSIPKAN', 'L2'));
    }

    /** Surat tanpa disposisi di tahap terkunci tetap ditolak (syarat tahap). */
    public function testStageLockAppliesWithoutDispositions(): void
    {
        $this->db();
        $this->assertFalse(letterAllowsCorrection('DITERUSKAN_KE_PELAKSANA', 'L3'));
    }
}
