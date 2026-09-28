<?php

/**
 * Trait pengaman uji: memastikan TIDAK ADA panggilan WhatsApp sungguhan selama
 * pengujian.
 *
 * Kenapa perlu: Whatsapp::$sender adalah properti statis. Uji yang selesai
 * memakai stub lalu menyetelnya kembali ke null (untuk "mengembalikan perilaku
 * produksi") meninggalkan null itu untuk kelas uji berikutnya, sehingga uji
 * berikutnya benar-benar menembak https://api.fonnte.com/send. Dipasang lewat
 * setUp() di tiap kelas uji yang menyentuh notifikasi.
 */
trait WaStub
{
    /** Pasang pengirim Fonnte tiruan (default: tidak melakukan apa pun). */
    protected function stubWhatsapp(?callable $sender = null): void
    {
        Whatsapp::$sender = $sender ?? function (string $token, string $target, string $message): void {
            // Sengaja kosong: uji tidak boleh mengirim WhatsApp nyata.
        };
    }
}
