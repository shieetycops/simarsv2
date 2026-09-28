<?php
// wabot_keyword.php — perintah kata kunci WA revisi SOP/AS/04 (Fix 2).
// Di-require dari wabot.php setelah parseKeywordCommand() cocok.
// Variabel dispatcher: $settings, $ident, $message, $inboxId,
// $actor, $actorUserId, $replyTarget, $keyword.
//
// Aturan: satu kata kunci = satu arti. SELESAI tidak pernah mengembalikan
// surat; hanya TOLAK yang mengembalikan. Perintah tanpa agenda / agenda
// bukan milik pengirim -> balas error, TIDAK mengubah data.

$kw = strtoupper((string) ($keyword['keyword'] ?? ''));
$agendaRaw = trim((string) ($keyword['agenda'] ?? ''));
$rest = trim((string) ($keyword['rest'] ?? ''));

if (($keyword['error'] ?? null) === 'AGENDA_REQUIRED' || $agendaRaw === '') {
    wabotReply(Wabot::buildKeywordErrorText('AGENDA_REQUIRED'), $settings, $inboxId, $replyTarget);
    return;
}

$letter = Db::one('SELECT * FROM incoming_letters WHERE agenda_number = ?', [$agendaRaw]);
if (!$letter) {
    $letter = Db::one('SELECT * FROM incoming_letters WHERE agenda_number LIKE ?', ['%/' . $agendaRaw]);
}
if (!$letter) {
    wabotReply(Wabot::buildKeywordErrorText('AGENDA_NOT_FOUND', $agendaRaw), $settings, $inboxId, $replyTarget);
    return;
}
if (!V2Workflow::userCanAccessLetter($actor, (string) ($letter['securityLevel'] ?? 'BIASA'))) {
    wabotReply('Surat ini berlevel RAHASIA; tindak lanjut hanya lewat aplikasi web.', $settings, $inboxId, $replyTarget);
    return;
}

$role = (string) ($actor['role'] ?? '');
$stage = strtoupper(trim((string) ($letter['currentStage'] ?? '')));
$letterId = (string) ($letter['id'] ?? '');

$isOwner = in_array($role, V2Workflow::stageOwners($stage), true)
    || ((string) ($letter['assigneeUserId'] ?? '') !== '' && (string) ($letter['assigneeUserId']) === $actorUserId)
    || (in_array($role, ['PIMPINAN', 'WAKIL_KETUA'], true) && $stage === 'MENUNGGU_KEBIJAKAN_PIMPINAN')
    || (in_array($role, ['ARSIPARIS', 'ADMIN'], true) && in_array($stage, ['SELESAI_DITINDAKLANJUTI', 'MENUNGGU_PENGARSIPAN'], true));

// T9 (revisi kedua): identitas surat untuk balasan bot. Perihal surat
// RAHASIA/SANGAT_RAHASIA TIDAK boleh ikut payload WhatsApp (KMA 131 BAB V) —
// hanya agenda yang boleh disebut.
$waTask = [
    'subject'   => (string) ($letter['subject'] ?? '-'),
    'agenda'    => $agendaRaw,
    'sensitive' => V2Workflow::mustWithholdWaContent((string) ($letter['securityLevel'] ?? 'BIASA')),
];

switch ($kw) {
    case 'TERIMA':
        if (!in_array($role, ['ADMIN', 'KEPALA_SUB_UMUM', 'SEKRETARIS', 'PANITERA'], true)) {
            wabotReply(Wabot::buildKeywordErrorText('ROLE_DENIED'), $settings, $inboxId, $replyTarget);
            return;
        }
        $path = [];
        $cur = $stage;
        foreach (['DITERUSKAN_KE_KASUBAG', 'DITERUSKAN_KE_SEKRETARIS_PANITERA'] as $s) {
            if (V2Workflow::canTransition($cur, $s)) { $path[] = $s; $cur = $s; }
        }
        if (!$path) {
            wabotReply(Wabot::buildStageTaskFailed('Surat sudah lewat tahap verifikasi.'), $settings, $inboxId, $replyTarget);
            return;
        }
        $res = LetterTransition::applyPath($actor, $letter, $path, [
            'notes' => $rest !== '' ? $rest : 'Diterima via WhatsApp.',
            'source' => LetterTransition::SOURCE_WA,
        ]);
        wabotReply($res['ok']
            ? Wabot::buildStageTaskApplied($waTask, (string) $res['stage'], $res['route'] ?? null, false, 0)
            : Wabot::buildStageTaskFailed($res['message']), $settings, $inboxId, $replyTarget);
        return;

    case 'TOLAK':
        if ($rest === '') {
            wabotReply(Wabot::buildKeywordErrorText('TOLAK_NEEDS_REASON'), $settings, $inboxId, $replyTarget);
            return;
        }
        // K3 (DEFAULT SEMENTARA): satu pintu untuk TOLAK (pemegang mengembalikan
        // satu langkah), RECALL (pengirim menarik sebelum penerima bertindak),
        // dan koreksi ADMIN. Alasan wajib >= tolak_reason_min; semuanya tercatat
        // di log kendali (pelaku, alasan, waktu, tahap asal -> tahap tujuan).
        $res = LetterTransition::reopen($actor, $letter, $rest, ['source' => LetterTransition::SOURCE_WA]);
        if ($res['ok']) {
            logActivity($actorUserId, 'WABOT_KEYWORD_TOLAK', 'INCOMING_LETTER', $letterId,
                'TOLAK/RECALL ' . $agendaRaw . ' [' . (string) ($res['mode'] ?? '-') . ']');
        }
        wabotReply($res['ok']
            ? Wabot::buildStageTaskApplied($waTask, (string) $res['stage'], null, false, 0)
            : Wabot::buildStageTaskFailed($res['message']), $settings, $inboxId, $replyTarget);
        return;

    case 'KEBIJAKAN':
        if (!V2Workflow::canSetFinalRoute($role)) {
            wabotReply(Wabot::buildKeywordErrorText('ROLE_DENIED', $agendaRaw), $settings, $inboxId, $replyTarget);
            return;
        }
        if (!$isOwner) {
            wabotReply(Wabot::buildKeywordErrorText('AGENDA_NOT_YOURS', $agendaRaw), $settings, $inboxId, $replyTarget);
            return;
        }
        $dres = LetterTransition::decideRoute($actor, $letter, 'KEBIJAKAN', ['notes' => $rest]);
        if (!$dres['ok']) {
            wabotReply(Wabot::buildStageTaskFailed($dres['message']), $settings, $inboxId, $replyTarget);
            return;
        }
        $letter2 = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$letterId]);
        $cur = strtoupper(trim((string) ($letter2['currentStage'] ?? $stage)));
        foreach (['DIDISPOSISIKAN', 'DITERUSKAN_KE_SEKRETARIS_PANITERA', 'MENUNGGU_KEBIJAKAN_PIMPINAN'] as $s) {
            if ($cur !== $s && V2Workflow::canTransition($cur, $s)) {
                $r = LetterTransition::apply($actor, $letter2, $s, [
                    'notes' => 'Keputusan via WhatsApp: KEBIJAKAN.',
                    'route' => $s === 'DIDISPOSISIKAN' ? 'KEBIJAKAN' : null,
                    'source' => LetterTransition::SOURCE_WA,
                ]);
                if (!$r['ok']) {
                    wabotReply(Wabot::buildStageTaskFailed($r['message']), $settings, $inboxId, $replyTarget);
                    return;
                }
                $letter2 = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$letterId]);
                $cur = $s;
            }
        }
        wabotReply(Wabot::buildStageTaskApplied($waTask, $cur, 'KEBIJAKAN', false, 0),
            $settings, $inboxId, $replyTarget);
        return;

    case 'LANGSUNG':
        if (!V2Workflow::canSetFinalRoute($role)) {
            wabotReply(Wabot::buildKeywordErrorText('ROLE_DENIED', $agendaRaw), $settings, $inboxId, $replyTarget);
            return;
        }
        if (!$isOwner) {
            wabotReply(Wabot::buildKeywordErrorText('AGENDA_NOT_YOURS', $agendaRaw), $settings, $inboxId, $replyTarget);
            return;
        }
        if ($rest === '') {
            wabotReply(Wabot::buildKeywordErrorText('UNIT_REQUIRED'), $settings, $inboxId, $replyTarget);
            return;
        }
        // P4: kode unit via WA kehilangan '_' (penanda italic dibuang cleanText),
        // jadi token digabung ulang (mis. "KASUBAG UMUM" -> KASUBAG_UMUM). Sisa
        // token setelah unit = catatan. Unit tak dikenal -> UNIT_INVALID.
        $toks = preg_split('/\s+/', $rest);
        [$unit, $used] = Wabot::resolveUnitFromTokens($toks);
        if ($unit === null) {
            wabotReply(Wabot::buildKeywordErrorText('UNIT_INVALID'), $settings, $inboxId, $replyTarget);
            return;
        }
        $unitNotes = trim(implode(' ', array_slice($toks, $used)));
        [$uok, $uerr] = V2Workflow::validateUnitTujuan($unit);
        if (!$uok) {
            wabotReply(Wabot::buildKeywordErrorText('UNIT_INVALID'), $settings, $inboxId, $replyTarget);
            return;
        }
        $dres = LetterTransition::decideRoute($actor, $letter, 'LANGSUNG', ['unit_tujuan' => $unit, 'notes' => $unitNotes]);
        if (!$dres['ok']) {
            wabotReply(Wabot::buildStageTaskFailed($dres['message']), $settings, $inboxId, $replyTarget);
            return;
        }
        $letter2 = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$letterId]);
        $cur = strtoupper(trim((string) ($letter2['currentStage'] ?? $stage)));
        foreach (['DIDISPOSISIKAN', 'DITERUSKAN_KE_SEKRETARIS_PANITERA', 'DITERUSKAN_KE_PELAKSANA'] as $s) {
            if ($cur !== $s && V2Workflow::canTransition($cur, $s)) {
                $r = LetterTransition::apply($actor, $letter2, $s, [
                    'notes' => 'Keputusan via WhatsApp: LANGSUNG ke ' . $unit . '.',
                    'route' => $s === 'DIDISPOSISIKAN' ? 'LANGSUNG' : null,
                    'unit_tujuan' => $s === 'DITERUSKAN_KE_PELAKSANA' ? $unit : null,
                    'source' => LetterTransition::SOURCE_WA,
                ]);
                if (!$r['ok']) {
                    wabotReply(Wabot::buildStageTaskFailed($r['message']), $settings, $inboxId, $replyTarget);
                    return;
                }
                $letter2 = Db::one('SELECT * FROM incoming_letters WHERE id = ?', [$letterId]);
                $cur = $s;
            }
        }
        wabotReply(Wabot::buildStageTaskApplied($waTask, $cur, 'LANGSUNG', false, 0),
            $settings, $inboxId, $replyTarget);
        return;

    case 'ARAHAN':
        if (!in_array($role, ['PIMPINAN', 'WAKIL_KETUA', 'ADMIN'], true)) {
            wabotReply(Wabot::buildKeywordErrorText('ROLE_DENIED'), $settings, $inboxId, $replyTarget);
            return;
        }
        // K6 (DEFAULT SEMENTARA): perintah ARAHAN via WA untuk surat
        // RAHASIA/SANGAT_RAHASIA DITOLAK — isi arahan tidak boleh masuk
        // percakapan WA. Toggle: workflow_settings.wa_arahan_rahasia_blocked.
        if (V2Workflow::mustWithholdWaContent((string) ($letter['securityLevel'] ?? 'BIASA'))
            && WorkflowConfig::waArahanRahasiaBlocked()) {
            wabotReply(Wabot::buildKeywordErrorText('ARAHAN_RAHSIA'), $settings, $inboxId, $replyTarget);
            return;
        }
        if ($rest === '') {
            wabotReply(Wabot::buildKeywordErrorText('ARAHAN_REQUIRED'), $settings, $inboxId, $replyTarget);
            return;
        }
        // P4: unit tujuan hanya lewat penanda EKSPLISIT #KODE_UNIT (paling satu).
        // Tanpa penanda: arahan tetap tersimpan TANPA unit — Sekretaris wajib
        // memilih unit saat TERUSKAN; tidak ada penerusan diam-diam ke unit.
        // Penanda typo -> balasan error, data tidak diubah.
        [$unit, $arahan, $unitError] = Wabot::parseArahanUnit($rest);
        if ($unitError !== null) {
            wabotReply(Wabot::buildKeywordErrorText($unitError), $settings, $inboxId, $replyTarget);
            return;
        }
        [$aok, $aerr] = V2Workflow::validateArahan($arahan);
        if (!$aok) {
            wabotReply(Wabot::buildKeywordErrorText('ARAHAN_REQUIRED'), $settings, $inboxId, $replyTarget);
            return;
        }
        $r = LetterTransition::apply($actor, $letter, 'DITERUSKAN_KE_SEKRETARIS_PANITERA', [
            'notes' => 'Arahan pimpinan via WhatsApp: ' . $arahan,
            'arahan' => $arahan,
            'unit_tujuan' => $unit,
            'source' => LetterTransition::SOURCE_WA,
        ]);
        if ($r['ok']) {
            logActivity($actorUserId, 'WABOT_KEYWORD_ARAHAN', 'INCOMING_LETTER', $letterId,
                'ARAHAN ' . $agendaRaw . ($unit !== null ? " unit $unit" : ' tanpa penanda unit'));
        }
        $extra = $r['ok']
            ? ($unit !== null
                ? ' Unit tujuan terbaca: ' . $unit . '.'
                : ' Tanpa penanda #KODE_UNIT — Sekretaris memilih unit saat meneruskan (TERUSKAN).')
            : '';
        wabotReply($r['ok']
            ? Wabot::buildStageTaskApplied($waTask, (string) $r['stage'], $r['route'] ?? null, false, 0) . $extra
            : Wabot::buildStageTaskFailed($r['message']), $settings, $inboxId, $replyTarget);
        return;

    case 'TERUSKAN':
        if (!V2Workflow::canSetFinalRoute($role)) {
            wabotReply(Wabot::buildKeywordErrorText('ROLE_DENIED'), $settings, $inboxId, $replyTarget);
            return;
        }
        // P2 (revisi kedua): TERUSKAN hanya saat surat benar-benar di meja
        // Sekretaris/Panitera (arahan sudah turun) atau rute LANGSUNG belum
        // diteruskan dari DIDISPOSISIKAN — bukan di tahap awal/meja lain.
        if (!in_array($stage, V2Workflow::TERUSKAN_ALLOWED_STAGES, true)) {
            wabotReply(Wabot::buildStageTaskFailed(
                'TERUSKAN hanya saat surat berada di meja Sekretaris/Panitera setelah arahan turun (tahap saat ini: '
                . V2Workflow::stageLabel($stage) . '). Data tidak diubah.'), $settings, $inboxId, $replyTarget);
            return;
        }
        // P4: unit dari perintah WA kehilangan '_' (cleanText) -> gabung ulang
        // token; tanpa unit di perintah, pakai unit tersimpan di surat.
        if ($rest !== '') {
            $toks = preg_split('/\s+/', $rest);
            [$unit, $used] = Wabot::resolveUnitFromTokens($toks);
            $unitProvided = true;
        } else {
            $unit = strtoupper(trim((string) ($letter['unitTujuan'] ?? '')));
            $unitProvided = false;
        }
        [$uok, $uerr] = V2Workflow::validateUnitTujuan($unit);
        if (!$uok) {
            // P4: unit yang dikirim tetapi salah -> error UNIT_INVALID (bukan
            // dibuang/diambil diam-diam); unit kosong -> UNIT_REQUIRED.
            wabotReply(Wabot::buildKeywordErrorText($unitProvided ? 'UNIT_INVALID' : 'UNIT_REQUIRED'),
                $settings, $inboxId, $replyTarget);
            return;
        }
        $r = LetterTransition::apply($actor, $letter, 'DITERUSKAN_KE_PELAKSANA', [
            'notes' => 'Melaksanakan arahan via WhatsApp. Unit: ' . $unit . '.',
            'unit_tujuan' => $unit,
            'source' => LetterTransition::SOURCE_WA,
        ]);
        wabotReply($r['ok']
            ? Wabot::buildStageTaskApplied($waTask, (string) $r['stage'], $r['route'] ?? null, false, 0)
            : Wabot::buildStageTaskFailed($r['message']), $settings, $inboxId, $replyTarget);
        return;





    case 'TUNJUK':
        if (!V2Workflow::isUnitHead($role)) {
            wabotReply(Wabot::buildKeywordErrorText('ROLE_DENIED'), $settings, $inboxId, $replyTarget);
            return;
        }
        if ($stage !== 'DITERUSKAN_KE_PELAKSANA') {
            wabotReply(Wabot::buildStageTaskFailed('Penunjukan hanya pada tahap DITERUSKAN_KE_PELAKSANA.'),
                $settings, $inboxId, $replyTarget);
            return;
        }
        if ($rest === '') {
            wabotReply('Perintah TUNJUK butuh nama pegawai. Data tidak diubah.', $settings, $inboxId, $replyTarget);
            return;
        }
        $pegawai = Db::one(
            'SELECT id, name FROM users WHERE is_active = 1 AND (name LIKE ? OR username LIKE ?) ORDER BY name ASC LIMIT 1',
            ['%' . $rest . '%', '%' . $rest . '%']);
        if (!$pegawai) {
            wabotReply('Pegawai tidak ditemukan. Data tidak diubah.', $settings, $inboxId, $replyTarget);
            return;
        }
        Db::q('UPDATE incoming_letters SET assignee_user_id = ?, assignee_set_at = NOW() WHERE id = ?',
            [(string) $pegawai['id'], $letterId]);
        Db::q('INSERT INTO letter_control_logs (id, incoming_letter_id, actor_user_id, from_stage, to_stage, action, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [Db::generateId(), $letterId, $actorUserId, $stage, $stage, 'TUNJUK_PEGAWAI', 'Via WA: ' . ($pegawai['name'] ?? '')]);
        logActivity($actorUserId, 'TUNJUK_PEGAWAI', 'INCOMING_LETTER', $letterId, 'TUNJUK ' . $agendaRaw . ' via WA');
        $letter['currentStage'] = $stage;
        WaStageNotifier::afterTransition($letter, $stage, $stage, $actor,
            ['source' => LetterTransition::SOURCE_WA, 'notes' => 'Penunjukan via WA']);
        wabotReply('Pegawai ' . ($pegawai['name'] ?? '') . ' ditunjuk untuk agenda ' . $agendaRaw . '.',
            $settings, $inboxId, $replyTarget);
        return;


    case 'PROSES':
    case 'SELESAI':
        $assignee = (string) ($letter['assigneeUserId'] ?? '');
        if (($assignee === '' || $assignee !== $actorUserId) && !V2Workflow::isUnitHead($role)) {
            wabotReply(Wabot::buildKeywordErrorText('AGENDA_NOT_YOURS', $agendaRaw), $settings, $inboxId, $replyTarget);
            return;
        }
        $plan = V2Workflow::planAutoAdvance($stage, $kw);
        if (!$plan['applied']) {
            wabotReply(Wabot::buildStageTaskFailed(V2Workflow::autoAdvanceReasonText($plan['reason'])),
                $settings, $inboxId, $replyTarget);
            return;
        }
        $who = trim((string) ($actor['name'] ?? '')) !== '' ? (string) $actor['name'] : 'pelaksana';
        $res = LetterTransition::applyPath($actor, $letter, $plan['path'], [
            'notes' => 'Laporan ' . $kw . ' via WhatsApp oleh ' . $who . '.' . ($rest !== '' ? ' ' . $rest : ''),
            'action' => LetterTransition::ACTION_AUTO,
            'source' => LetterTransition::SOURCE_WA,
        ]);
        if ($res['ok']) {
            logActivity($actorUserId, 'WABOT_KEYWORD_' . $kw, 'INCOMING_LETTER', $letterId, $kw . ' ' . $agendaRaw . ' via WA');
        }
        wabotReply($res['ok']
            ? Wabot::buildStageTaskApplied($waTask, (string) $res['stage'], null, false, 0)
            : Wabot::buildStageTaskFailed($res['message']), $settings, $inboxId, $replyTarget);
        return;

    case 'ARSIP':
        $r = LetterTransition::apply($actor, $letter, 'DIARSIPKAN', [
            'notes' => 'Diarsipkan via WhatsApp.' . ($rest !== '' ? ' ' . $rest : ''),
            'source' => LetterTransition::SOURCE_WA,
        ]);
        if ($r['ok'] && !empty($r['warning'])) {
            // K7: arsip tanpa serah-terima lembar 1 -> peringatan tercatat.
            logActivity($actorUserId, 'ARSIP_TANPA_LEMBAR1', 'INCOMING_LETTER', $letterId,
                'Arsip via WA tanpa serah-terima lembar 1: ' . $agendaRaw);
        }
        wabotReply($r['ok']
            ? 'Agenda ' . $agendaRaw . ' diarsipkan.' . (!empty($r['warning']) ? "\n\n" . $r['warning'] : '')
            : Wabot::buildStageTaskFailed($r['message']), $settings, $inboxId, $replyTarget);
        return;

    default:
        wabotReply(Wabot::buildKeywordHelpText(), $settings, $inboxId, $replyTarget);
        return;
}
