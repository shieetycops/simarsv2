import { useEffect, useState } from "react";
import type React from "react";
import { useAuth } from "@/src/lib/AuthContext";
import { toast } from "sonner";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { Loader2, Save, MessageCircle, Send, Bot } from "lucide-react";

export default function WhatsAppSettings() {
  const { token } = useAuth();
  const [fonnteToken, setFonnteToken] = useState("");
  const [groupTarget, setGroupTarget] = useState("");
  const [waGroupMarker, setWaGroupMarker] = useState("");
  const [appUrl, setAppUrl] = useState("");
  const [isEnabled, setIsEnabled] = useState(true);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);

  useEffect(() => {
    fetch("/api/whatsapp/settings", { headers: { Authorization: `Bearer ${token}` } })
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => {
        if (data) {
          setFonnteToken(data.fonnteToken || "");
          setGroupTarget(data.groupTarget || "");
          setWaGroupMarker(data.waGroupMarker || "");
          setAppUrl(data.appUrl || "");
          setIsEnabled(Boolean(data.isEnabled));
        }
      })
      .catch(() => {
        // silent
      })
      .finally(() => setLoading(false));
  }, [token]);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      const res = await fetch("/api/whatsapp/settings", {
        method: "PUT",
        headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
        body: JSON.stringify({
          fonnteToken: fonnteToken || null,
          groupTarget: groupTarget || null,
          waGroupMarker: waGroupMarker || null,
          appUrl: appUrl || null,
          isEnabled,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.message || "Gagal menyimpan pengaturan");
      }
      toast.success("Pengaturan WhatsApp berhasil disimpan");
    } catch (err: any) {
      toast.error(err.message || "Gagal menyimpan pengaturan");
    } finally {
      setSaving(false);
    }
  };

  const handleTest = async () => {
    setTesting(true);
    try {
      const res = await fetch("/api/whatsapp/test", {
        method: "POST",
        headers: { Authorization: `Bearer ${token}` },
      });
      const data = await res.json().catch(() => ({ success: false }));
      if (data.success) {
        toast.success("Pesan tes terkirim. Periksa grup WhatsApp Anda.");
      } else {
        toast.error(data.message || "Gagal mengirim pesan tes");
      }
    } catch {
      toast.error("Gagal mengirim pesan tes");
    } finally {
      setTesting(false);
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">Pengaturan Notifikasi WhatsApp</h1>
        <p className="text-sm text-slate-500 mt-0.5">
          Notifikasi disposisi otomatis ke grup WhatsApp melalui Fonnte.
        </p>
      </div>

      <Card className="border border-slate-200 shadow-sm rounded-lg">
        <CardHeader className="border-b border-slate-100 px-6 py-4">
          <CardTitle className="text-sm font-medium text-slate-700 flex items-center gap-2">
            <MessageCircle className="w-4 h-4 text-slate-500" />
            Konfigurasi Fonnte
          </CardTitle>
        </CardHeader>
        <CardContent className="p-6">
          {loading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="w-5 h-5 animate-spin text-slate-400" />
            </div>
          ) : (
            <form onSubmit={handleSave} className="space-y-5 max-w-xl">
              <div className="space-y-1.5">
                <Label htmlFor="fonnte-token" className="text-sm font-medium text-slate-700">
                  Token Device Fonnte
                </Label>
                <Input
                  id="fonnte-token"
                  type="password"
                  value={fonnteToken}
                  onChange={(e) => setFonnteToken(e.target.value)}
                  placeholder="Token dari dashboard Fonnte"
                  className="h-9 rounded-md"
                  autoComplete="off"
                />
                <p className="text-xs text-slate-400">
                  Hubungkan nomor WhatsApp di dashboard Fonnte (fonnte.com), lalu salin token device-nya ke sini.
                </p>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="group-target" className="text-sm font-medium text-slate-700">
                  Grup Target (ID Grup WhatsApp)
                </Label>
                <Input
                  id="group-target"
                  value={groupTarget}
                  onChange={(e) => setGroupTarget(e.target.value)}
                  placeholder="Contoh: 120363xxxxxxxxxx@g.us"
                  className="h-9 rounded-md"
                  autoComplete="off"
                />
                <p className="text-xs text-slate-400">
                  ID grup bisa dilihat di dashboard Fonnte pada menu Device &rarr; Group. Nomor WA yang terhubung ke
                  Fonnte harus menjadi anggota grup tersebut.
                </p>
              </div>


              <div className="space-y-1.5">
                <Label htmlFor="wa-marker" className="text-sm font-medium text-slate-700">
                  Kata Kunci Bot Grup (opsional)
                </Label>
                <Input
                  id="wa-marker"
                  value={waGroupMarker}
                  onChange={(e) => setWaGroupMarker(e.target.value)}
                  placeholder="Contoh: SIMARS"
                  className="h-9 rounded-md"
                  autoComplete="off"
                />
                <p className="text-xs text-slate-400">
                  Jika diisi, bot disposisi hanya memproses pesan grup yang mengandung kata kunci ini
                  (mis. &quot;SIMARS DISPOSISI 1 SELESAI&quot;). Kosongkan bila grup khusus untuk disposisi.
                </p>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="wa-app-url" className="text-sm font-medium text-slate-700">
                  URL Aplikasi (opsional)
                </Label>
                <Input
                  id="wa-app-url"
                  value={appUrl}
                  onChange={(e) => setAppUrl(e.target.value)}
                  placeholder="Contoh: https://simars.example.gov.id"
                  className="h-9 rounded-md"
                  autoComplete="off"
                />
                <p className="text-xs text-slate-400">
                  URL publik aplikasi SIMARS. Dipakai untuk link lampiran view-only surat (mis.
                  https://simars.example.gov.id/surats/&lt;id&gt;) yang dikirim WhatsApp ke PIMPINAN &amp; pegawai.
                  Kosongkan bila aplikasi sudah bisa diakses otomatis dari IP/domain pengakses WA.
                </p>
              </div>

              <div className="flex items-center gap-2.5">
                <Checkbox checked={isEnabled} onCheckedChange={(v) => setIsEnabled(Boolean(v))} id="wa-enabled" />
                <Label htmlFor="wa-enabled" className="text-sm font-medium text-slate-700 cursor-pointer">
                  Aktifkan notifikasi WhatsApp
                </Label>
              </div>

              <div className="pt-2 flex items-center gap-3">
                <Button type="submit" disabled={saving} className="h-9 text-sm font-medium rounded-md">
                  {saving ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : <Save className="w-4 h-4 mr-2" />}
                  Simpan Pengaturan
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  disabled={testing}
                  onClick={handleTest}
                  className="h-9 text-sm font-medium rounded-md"
                >
                  {testing ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : <Send className="w-4 h-4 mr-2" />}
                  Kirim Pesan Tes
                </Button>
              </div>
            </form>
          )}
        </CardContent>
      </Card>

      <Card className="border border-slate-200 shadow-sm rounded-lg">
        <CardHeader className="border-b border-slate-100 px-6 py-4">
          <CardTitle className="text-sm font-medium text-slate-700 flex items-center gap-2">
            <Bot className="w-4 h-4 text-slate-500" />
            Bot Disposisi WhatsApp
          </CardTitle>
        </CardHeader>
        <CardContent className="p-6">
          <div className="text-sm text-slate-600 space-y-3">
            <p>
              Semua balasan bot dikirim ke <strong>chat pribadi</strong> pengirim, meskipun perintah
              diketik di grup. Di grup, bot hanya memproses pesan pada <em>Grup Target</em> di atas
              (tambah <em>Kata Kunci Bot Grup</em> bila grup dipakai untuk banyak hal). Nomor
              WhatsApp setiap pengguna diisi admin di menu <strong>Manajemen Pengguna</strong>;
              pesan dari nomor yang tidak terdaftar diabaikan.
            </p>
            <p className="font-medium text-slate-700">
              Pimpinan (PIMPINAN &amp; ADMIN) &mdash; membuat disposisi dari surat masuk
            </p>
            <ul className="list-disc pl-4 space-y-1">
 <li>
 Saat surat masuk baru tiba, bot mengirim chat pribadi berisi info surat +
 daftar pegawai bernomor &mdash; cukup balas{" "}
 <code className="rounded bg-slate-100 px-1 py-0.5">&lt;nomor&gt; &lt;instruksi&gt;</code>{" "}
 langsung, tanpa perintah DISPOSISI (menu memuat pula tautan surat view-only)
 </li>
              <li>
                <code className="rounded bg-slate-100 px-1 py-0.5">DISPOSISI &lt;nomor agenda&gt;</code>{" "}
                &mdash; mis. <code className="rounded bg-slate-100 px-1 py-0.5">DISPOSISI 12</code>;
                buka/ganti menu surat lain: bot membalas daftar pegawai bernomor yang boleh diberi disposisi
              </li>
              <li>
                <code className="rounded bg-slate-100 px-1 py-0.5">DISPOSISI &lt;kata kunci&gt;</code>{" "}
                &mdash; cari dari perihal surat, mis.{" "}
                <code className="rounded bg-slate-100 px-1 py-0.5">DISPOSISI UNDANGAN</code>. Bila
                lebih dari satu surat cocok, bot meminta nomor agenda
              </li>
              <li>
                Balas <code className="rounded bg-slate-100 px-1 py-0.5">&lt;nomor pegawai&gt;</code>{" "}
                untuk memakai instruksi bawaan, atau{" "}
                <code className="rounded bg-slate-100 px-1 py-0.5">&lt;nomor&gt; &lt;instruksi&gt;</code>{" "}
                untuk mengirim dengan instruksi sendiri, mis.{" "}
                <code className="rounded bg-slate-100 px-1 py-0.5">2 Harap hadir rapat</code>
              </li>
              <li>
                Satu sesi mengirim satu disposisi lalu tertutup otomatis; berlaku{" "}
                <strong>60 menit</strong>. <code className="rounded bg-slate-100 px-1 py-0.5">MENU</code>{" "}
                menampilkan ulang daftar, <code className="rounded bg-slate-100 px-1 py-0.5">BATAL</code>{" "}
                membatalkan sesi
              </li>
            </ul>
            <p className="font-medium text-slate-700">Semua pegawai &mdash; melaporkan status tugas</p>
            <ul className="list-disc pl-4 space-y-1">
              <li>
                Saat disposisi diteruskan, bot mengirim menu di chat pribadi: balas{" "}
                <code className="rounded bg-slate-100 px-1 py-0.5">1</code> untuk PROSES (sedang
                dikerjakan) atau{" "}
                <code className="rounded bg-slate-100 px-1 py-0.5">2 [catatan]</code> untuk SELESAI,
                mis. <code className="rounded bg-slate-100 px-1 py-0.5">2 Surat telah diarsipkan</code>
              </li>
              <li>
                Catatan pada balasan SELESAI ditambahkan ke catatan disposisi dan pemberi tugas
                dikabari. <code className="rounded bg-slate-100 px-1 py-0.5">MENU</code> menampilkan
                ulang, <code className="rounded bg-slate-100 px-1 py-0.5">BATAL</code> menutup sesi
                tanpa mengubah status
              </li>
              <li>
                Sesi laporan berlaku <strong>7 hari</strong>, diperpanjang setiap balasan, ditutup
                otomatis setelah SELESAI, dan terbuka kembali selama tugasnya belum selesai
              </li>
                <li>
                 Notifikasi <strong>DISPOSISI PROSES / DISPOSISI SELESAI</strong> dari pegawai
                 diumumkan ke grup sekaligus diteruskan ke <strong>chat pribadi tiap
                 PIMPINAN</strong> aktif, agar pimpinan tahu suratnya sudah diproses /
                 selesai atau belum tanpa membuka grup
                </li>
            </ul>
            <p className="font-medium text-slate-700">
              Perintah cepat (khusus PIMPINAN &amp; ADMIN) &mdash; tanpa membuka sesi
            </p>
            <ul className="list-disc pl-4 space-y-1">
              <li>
                <code className="rounded bg-slate-100 px-1 py-0.5">DISPOSISI</code> — daftar disposisi
                Anda yang belum selesai (dibalas bernomor, terbaru di atas)
              </li>
              <li>
                <code className="rounded bg-slate-100 px-1 py-0.5">DISPOSISI &lt;nomor&gt; PROSES</code> —
                tandai sedang dikerjakan
              </li>
              <li>
                <code className="rounded bg-slate-100 px-1 py-0.5">DISPOSISI &lt;nomor&gt; SELESAI [catatan]</code> —
                tandai selesai, mis. &quot;DISPOSISI 1 SELESAI sudah diarsipkan&quot;
              </li>
            </ul>
            <p>
              Aktifkan di dashboard Fonnte: menu <strong>Device &rarr; Edit</strong>, isi{" "}
              <em>Webhook URL</em> dengan{" "}
              <code className="rounded bg-slate-100 px-1 py-0.5">https://&lt;domain-anda&gt;/api/wabot</code>{" "}
              dan nyalakan <em>Auto Read</em> (webhook tidak berjalan tanpa itu).
            </p>
          </div>
        </CardContent>
      </Card>

      <Card className="border border-slate-200 shadow-sm rounded-lg">
        <CardHeader className="border-b border-slate-100 px-6 py-4">
          <CardTitle className="text-sm font-medium text-slate-700">Catatan</CardTitle>
        </CardHeader>
        <CardContent className="p-6">
          <ul className="text-sm text-slate-600 space-y-2 list-disc pl-4">
            <li>
              Koneksi perangkat WhatsApp (scan QR) dilakukan di dashboard Fonnte, bukan di aplikasi ini. Jika notifikasi
              berhenti terkirim, periksa status device di dashboard Fonnte.
            </li>
            <li>
              Notifikasi disposisi menampilkan nama pengguna penerima sesuai data pengguna yang dipilih saat membuat
              disposisi.
            </li>
            <li>Gunakan tombol "Kirim Pesan Tes" setelah menyimpan untuk memastikan konfigurasi benar.</li>
          </ul>
        </CardContent>
      </Card>
    </div>
  );
}
