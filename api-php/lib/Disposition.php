<?php
// Logic disposisi non-CRUD (diuji): aturan hierarki + notifikasi per status.
class Disposition
{
    // Boleh disposisi bila pengirim ADMIN/PIMPINAN, atau penerima adalah bawahan
    // langsung pengirim (toUser.supervisorId === fromUser.id).
    public static function canDispose(string $fromRole, ?string $toSupervisorId, string $fromId): bool
    {
        return in_array($fromRole, ['ADMIN', 'PIMPINAN'], true) || $toSupervisorId === $fromId;
    }

    // Judul notifikasi untuk pengirim saat status berubah; null = tak ada notifikasi.
    // Ditulis sebagai if-chain, bukan match(), supaya tetap bisa di-parse PHP 7.4
    // (match baru ada di PHP 8). Perbandingan tetap ketat (===) agar semantiknya
    // identik dengan match().
    public static function statusNotificationTitle(string $status): ?string
    {
        if ($status === 'SELESAI') return 'Disposisi Selesai';
        if ($status === 'PROSES') return 'Disposisi Sedang Diproses';
        return null;
    }

    // Apakah status ini memicu notifikasi WhatsApp.
    public static function notifiesWhatsapp(string $status): bool
    {
        return $status === 'PROSES' || $status === 'SELESAI';
    }
}
