<?php
// DispositionBridge — jembatan laporan tindak lanjut (tabel dispositions) ke
// tahap surat (incoming_letters.current_stage). Fase 1, keputusan #1.
//
// Latar: sebelum ini laporan pelaksana (web PATCH /dispositions/:id/status dan
// balasan WhatsApp pegawai) hanya mengubah baris disposisi. Tahap surat tetap
// DITERUSKAN_KE_PELAKSANA selamanya, sehingga Buku Kendali selalu tampak macet
// dan Kasubag/Sekretaris tidak pernah tahu pekerjaannya sudah jalan/selesai.
//
// Kelas ini SENGAJA tipis: seluruh aturan tahap ada di V2Workflow (murni, diuji
// unit) dan seluruh penulisan tahap ada di LetterTransition (satu pintu, lengkap
// dengan jejak audit). Bridge hanya menjawab "laporan ini menggerakkan tahap ke
// mana, dan siapa yang melaporkan".
class DispositionBridge
{
    /**
     * Terapkan laporan status disposisi ke tahap surat surat terkait.
     *
     * Tidak pernah melempar untuk kasus "tidak relevan" (mis. status PENDING,
     * surat belum sampai rantai pelaksana) — pemanggil cukup mencatat alasannya.
     * Error database tetap dilempar oleh LetterTransition.
     *
     * $actor = baris user pelapor (id, name, role); null = ambil dari penerima disposisi.
     *
     * @return array{applied:bool,reason:string,message:string,from:?string,stage:?string,path:string[]}
     */
    public static function applyStatus(string $dispositionId, string $status, ?array $actor = null): array
    {
        $empty = ['applied' => false, 'reason' => '', 'message' => '', 'from' => null, 'stage' => null, 'path' => []];
        $disp = Db::one("SELECT d.id, d.to_user_id, d.incoming_letter_id,
                il.current_stage AS letter_stage, il.disposition_route AS letter_route
            FROM dispositions d
            JOIN incoming_letters il ON il.id = d.incoming_letter_id
            WHERE d.id = ?", [$dispositionId]);
        if (!$disp) {
            return array_merge($empty, ['reason' => 'DISPOSISI_TIDAK_DITEMUKAN',
                'message' => 'Disposisi tidak ditemukan.']);
        }

        $letter = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [(string) $disp['incomingLetterId']]);
        if (!$letter) {
            return array_merge($empty, ['reason' => 'SURAT_TIDAK_DITEMUKAN',
                'message' => 'Surat terkait disposisi ini tidak ditemukan.']);
        }
        // Baris surat dipakai UTUH (bukan hanya id/tahap): notifikasi & menu
        // WhatsApp memerlukan perihal, nomor agenda, level keamanan, dan pelaksana.
        // Tahap diambil dari hasil join supaya nilai yang dipakai = yang terbaca
        // saat keputusan "bergerak atau tidak" diambil.
        $letter['currentStage'] = (string) ($disp['letterStage'] ?? $letter['currentStage'] ?? '');
        $plan = V2Workflow::planAutoAdvance($letter['currentStage'], $status);
        if (!$plan['applied']) {
            return array_merge($empty, [
                'reason' => $plan['reason'],
                'message' => V2Workflow::autoAdvanceReasonText($plan['reason']),
                'from' => $letter['currentStage'],
                'stage' => $plan['target'],
            ]);
        }

        if ($actor === null) {
            $actor = Db::one("SELECT id, name, role FROM users WHERE id = ?", [(string) $disp['toUserId']]);
        }
        if (!$actor) {
            return array_merge($empty, ['reason' => 'AKTOR_TIDAK_DITEMUKAN',
                'message' => 'Pelapor disposisi tidak ditemukan.', 'from' => $letter['currentStage']]);
        }

        $who = trim((string) ($actor['name'] ?? '')) !== '' ? (string) $actor['name'] : 'pelaksana';
        $res = LetterTransition::applyPath($actor, $letter, $plan['path'], [
            'notes'  => "Otomatis dari laporan {$status} oleh {$who} [disposisi {$dispositionId}].",
            'action' => LetterTransition::ACTION_AUTO,
            'source' => LetterTransition::SOURCE_AUTO,
        ]);
        if (!$res['ok']) {
            return array_merge($empty, [
                'reason' => $res['code'],
                'message' => $res['message'],
                'from' => $letter['currentStage'],
                'stage' => $plan['target'],
                'path' => $res['steps'] ?? [],
            ]);
        }

        return [
            'applied' => true,
            'reason' => 'DITERAPKAN',
            'message' => $res['message'],
            'from' => $letter['currentStage'],
            'stage' => $res['stage'],
            'path' => $res['steps'] ?? $plan['path'],
        ];
    }

    /**
     * Catat pelaksana surat (kolom incoming_letters.assignee_user_id) saat
     * disposisi dibuat / surat diteruskan ke pelaksana. Dipakai Buku Kendali
     * untuk menampilkan kolom "Pelaksana" dan WaStageNotifier untuk tahu siapa
     * yang harus dihubungi ketika surat sampai di rantai pelaksanaan.
     */
    public static function assignLetter(string $letterId, string $toUserId): void
    {
        if ($letterId === '' || $toUserId === '') return;
        Db::q('UPDATE incoming_letters SET assignee_user_id = ?, assignee_set_at = NOW() WHERE id = ?',
            [$toUserId, $letterId]);
    }
}
