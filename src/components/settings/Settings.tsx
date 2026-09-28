import { useEffect, useState } from "react";
import type React from "react";
import { useAuth } from "@/src/lib/AuthContext";
import { useSettings } from "@/src/lib/useSettings";
import { toast } from "sonner";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Loader2, Save, Building2, Settings2 } from "lucide-react";

// Aturan disposisi (P1/K3/K6/K7) — DEFAULT SEMENTARA, menunggu review pimpinan.
// Sumber nilai: tabel workflow_settings (migrasi 2026_09_29_sop_patch.sql),
// diubah lewat PUT /api/settings/workflow (ADMIN saja). Lihat
// docs/CATATAN_ASUMSI_REVISI.md bagian 10.
type WorkflowRules = {
  tolakReasonMin?: number;
  waArahanRahasiaBlocked?: boolean;
  lembar1Wajib?: boolean;
  waStageNumberReply?: boolean;
  source?: string;
};

export default function Settings() {
  const { token, user } = useAuth();
  const { settings, loading: settingsLoading, refetch } = useSettings();
  const [submitting, setSubmitting] = useState(false);

  const [rulesLoading, setRulesLoading] = useState(true);
  const [rulesSaving, setRulesSaving] = useState(false);
  const [rules, setRules] = useState<WorkflowRules>({
    tolakReasonMin: 10,
    waArahanRahasiaBlocked: true,
    lembar1Wajib: false,
    waStageNumberReply: false,
  });

  useEffect(() => {
    if (user?.role !== "ADMIN") return;
    fetch("/api/settings/workflow", { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => { if (data) setRules(data); })
      .catch(() => {})
      .finally(() => setRulesLoading(false));
  }, [token, user?.role]);

  const saveRules = async () => {
    setRulesSaving(true);
    try {
      const res = await fetch("/api/settings/workflow", {
        method: "PUT",
        headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
        body: JSON.stringify({
          tolakReasonMin: Number(rules.tolakReasonMin) || 10,
          waArahanRahasiaBlocked: !!rules.waArahanRahasiaBlocked,
          lembar1Wajib: !!rules.lembar1Wajib,
          waStageNumberReply: !!rules.waStageNumberReply,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.message || "Gagal menyimpan aturan disposisi");
      }
      setRules(await res.json());
      toast.success("Aturan disposisi tersimpan");
    } catch (err: any) {
      toast.error(err.message || "Gagal menyimpan aturan disposisi");
    } finally {
      setRulesSaving(false);
    }
  };

  const [formData, setFormData] = useState({
    name: "",
    shortName: "",
    address: "",
    phone: "",
    email: "",
    logoUrl: "",
  });

  useEffect(() => {
    if (settings) {
      setFormData({
        name: settings.name || "",
        shortName: settings.shortName || "",
        address: settings.address || "",
        phone: settings.phone || "",
        email: settings.email || "",
        logoUrl: settings.logoUrl || "",
      });
    }
  }, [settings]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      const res = await fetch("/api/settings", {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify(formData),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.message || "Gagal menyimpan pengaturan");
      }
      await refetch();
      toast.success("Pengaturan berhasil disimpan");
    } catch (err: any) {
      toast.error(err.message || "Gagal menyimpan pengaturan");
    } finally {
      setSubmitting(false);
    }
  };

  if (user?.role !== "ADMIN") {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-sm text-slate-500">Anda tidak memiliki akses ke halaman ini.</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">Pengaturan</h1>
        <p className="text-sm text-slate-500 mt-0.5">
          Konfigurasi informasi instansi dan identitas aplikasi.
        </p>
      </div>

      <Card className="border border-slate-200 shadow-sm rounded-lg">
        <CardHeader className="border-b border-slate-100 px-6 py-4">
          <CardTitle className="text-sm font-medium text-slate-700 flex items-center gap-2">
            <Building2 className="w-4 h-4 text-slate-500" />
            Informasi Instansi
          </CardTitle>
        </CardHeader>
        <CardContent className="p-6">
          {settingsLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="w-5 h-5 animate-spin text-slate-400" />
              <span className="ml-2 text-sm text-slate-500">Memuat pengaturan...</span>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-5 max-w-xl">
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Nama Instansi</Label>
                <Input
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="Pengadilan Agama Pasarwajo"
                  className="h-9 rounded-md"
                  required
                />
              </div>

              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Nama Singkat</Label>
                <Input
                  value={formData.shortName}
                  onChange={(e) => setFormData({ ...formData, shortName: e.target.value })}
                  placeholder="PA Pasarwajo"
                  className="h-9 rounded-md"
                  required
                />
              </div>

              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Alamat</Label>
                <Input
                  value={formData.address}
                  onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                  placeholder="Jl. Poros Pasarwajo, Kab. Buton, Sulawesi Tenggara"
                  className="h-9 rounded-md"
                  required
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Telepon</Label>
                  <Input
                    value={formData.phone}
                    onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                    placeholder="(0402) 123456"
                    className="h-9 rounded-md"
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Email</Label>
                  <Input
                    type="email"
                    value={formData.email}
                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    placeholder="pa-pasarwajo@mahkamahagung.go.id"
                    className="h-9 rounded-md"
                  />
                </div>
              </div>

              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">URL Logo</Label>
                <Input
                  value={formData.logoUrl}
                  onChange={(e) => setFormData({ ...formData, logoUrl: e.target.value })}
                  placeholder="https://example.com/logo.png"
                  className="h-9 rounded-md"
                />
                <p className="text-xs text-slate-400">Opsional. URL gambar logo instansi.</p>
              </div>

              <div className="pt-2">
                <Button type="submit" disabled={submitting} className="h-9 text-sm font-medium rounded-md">
                  {submitting ? (
                    <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                  ) : (
                    <Save className="w-4 h-4 mr-2" />
                  )}
                  Simpan Pengaturan
                </Button>
              </div>
            </form>
          )}
        </CardContent>
      </Card>

      {/* Aturan disposisi (P1/K3/K6/K7) — DEFAULT SEMENTARA, menunggu review
          pimpinan. Semua aturan ini BUKAN dari SOP/KMA, hanya default sementara;
          bisa diubah di sini tanpa menyentuh kode (tabel workflow_settings). */}
      <Card className="border border-slate-200 shadow-sm rounded-lg">
        <CardHeader className="border-b border-slate-100 px-6 py-4">
          <CardTitle className="text-sm font-medium text-slate-700 flex items-center gap-2">
            <Settings2 className="w-4 h-4 text-slate-500" />
            Aturan Disposisi (DEFAULT SEMENTARA — menunggu review pimpinan)
          </CardTitle>
        </CardHeader>
        <CardContent className="p-6">
          {rulesLoading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="w-5 h-5 animate-spin text-slate-400" />
              <span className="ml-2 text-sm text-slate-500">Memuat aturan…</span>
            </div>
          ) : (
            <div className="space-y-5 max-w-2xl">
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Minimal karakter alasan TOLAK/RECALL (K3)</Label>
                <Input
                  type="number"
                  min={1}
                  max={500}
                  value={Number(rules.tolakReasonMin) || 10}
                  onChange={(e) => setRules({ ...rules, tolakReasonMin: Number(e.target.value) })}
                  className="h-9 rounded-md max-w-32"
                />
                <p className="text-xs text-slate-400">Alasan wajib tercatat di riwayat Buku Kendali (audit KMA 131 BAB IV).</p>
              </div>

              <label className="flex items-start gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  className="mt-1"
                  checked={!!rules.waArahanRahasiaBlocked}
                  onChange={(e) => setRules({ ...rules, waArahanRahasiaBlocked: e.target.checked })}
                />
                <span>
                  Blokir perintah ARAHAN via WhatsApp untuk surat RAHASIA/SANGAT_RAHASIA (K6)
                  <span className="block text-xs text-slate-400">Balasan bot: "tindak lanjut hanya lewat aplikasi web"; isi arahan tidak masuk percakapan WA.</span>
                </span>
              </label>

              <label className="flex items-start gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  className="mt-1"
                  checked={!!rules.lembar1Wajib}
                  onChange={(e) => setRules({ ...rules, lembar1Wajib: e.target.checked })}
                />
                <span>
                  Wajibkan serah-terima lembar 1 disposisi sebelum ARSIP (K7)
                  <span className="block text-xs text-slate-400">Default MATI: tanpa lembar 1, arsip hanya menampilkan peringatan + catatan log.</span>
                </span>
              </label>

              <label className="flex items-start gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  className="mt-1"
                  checked={!!rules.waStageNumberReply}
                  onChange={(e) => setRules({ ...rules, waStageNumberReply: e.target.checked })}
                />
                <span>
                  Izinkan balasan ANGKA pada menu aksi tahap WhatsApp (P1)
                  <span className="block text-xs text-slate-400">Default MATI: angka tidak terikat agenda tertentu (berisiko salah surat saat &gt;1 tugas). Nyalakan hanya bila angka sudah diikat ke satu agenda + kedaluwarsa.</span>
                </span>
              </label>

              <div className="pt-2">
                <Button onClick={saveRules} disabled={rulesSaving} className="h-9 text-sm font-medium rounded-md">
                  {rulesSaving ? (
                    <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                  ) : (
                    <Save className="w-4 h-4 mr-2" />
                  )}
                  Simpan Aturan Disposisi
                </Button>
                {rules.source && (
                  <p className="mt-2 text-xs text-slate-400">
                    Sumber nilai saat ini: {rules.source === "DB" ? "workflow_settings (DB)" : "default kode (tabel belum ada)"}
                  </p>
                )}
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
