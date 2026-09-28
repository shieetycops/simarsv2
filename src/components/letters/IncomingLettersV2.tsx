import { useEffect, useState } from "react";
import type React from "react";
import { useAuth } from "@/src/lib/AuthContext";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { V2_ARCHIVE_PRIMARIES, V2_ATTACHMENT_ACCEPT, V2_ATTACHMENT_HINT, V2_DOCUMENT_TYPES, V2_SECURITY_LEVELS, V2_SOURCE_CHANNELS, V2_STAGE_LABELS, type V2Classification } from "@/src/lib/v2Workflow";

const selectClass = "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm";

export default function IncomingLettersV2() {
  const { token } = useAuth();
  const [agenda, setAgenda] = useState("");
  const [letters, setLetters] = useState<any[]>([]);
  const [classifications, setClassifications] = useState<V2Classification[]>([]);
  const [message, setMessage] = useState("");
  // Lampiran scan surat. Halaman v2 sebelumnya TIDAK punya input berkas,
  // padahal backend sudah menerima multipart field "file" (POST /api/incoming,
  // Upload::save). Tanpa ini fitur upload ikut hilang saat menu lama dipensiunkan.
  const [file, setFile] = useState<File | null>(null);
  const [form, setForm] = useState({ letterNumber: "", letterDate: new Date().toISOString().slice(0, 10), receivedDate: new Date().toISOString().slice(0, 10), sender: "", subject: "", classification: "DINAS", sourceChannel: "POS", letterCategory: "DINAS", documentType: "SURAT_DINAS", securityLevel: "BIASA", urgencyLevel: "NORMAL", archiveCode: "", description: "" });
  const set = (key: string, value: string) => setForm((old) => ({ ...old, [key]: value }));

  const load = async () => {
    const headers = { Authorization: `Bearer ${token}` };
    const [a, l, c] = await Promise.all([fetch("/api/incoming/next-agenda", { headers }), fetch("/api/incoming", { headers }), fetch("/api/control/classifications", { headers })]);
    if (a.ok) setAgenda((await a.json()).agendaNumber || "");
    if (l.ok) setLetters(await l.json());
    if (c.ok) setClassifications((await c.json()).classifications || []);
  };
  useEffect(() => { if (token) load(); }, [token]);

  // Dropdown kode arsip: master resmi dari API; bila kosong, tampilkan 13
  // kategori primer resmi sebagai pilihan minimum (SK 627/2023).
  const archiveOptions = classifications.length > 0
    ? classifications.map((c) => ({ code: c.code, label: `${c.code} — ${c.name}${c.validationStatus === "PENDING_VALIDATION" ? " (perlu validasi arsiparis)" : ""}` }))
    : Object.entries(V2_ARCHIVE_PRIMARIES).map(([code, name]) => ({ code, label: `${code} — ${name}` }));

  const submit = async (event: React.FormEvent) => {
    event.preventDefault(); setMessage("");
    const body = new FormData(); body.append("data", JSON.stringify({ ...form, agendaNumber: agenda }));
    if (file) body.append("file", file);
    const response = await fetch("/api/incoming", { method: "POST", headers: { Authorization: `Bearer ${token}` }, body });
    const data = await response.json();
    if (!response.ok) { setMessage(data.message || "Data surat belum dapat disimpan."); return; }
    setMessage("Surat berhasil diregistrasi sebagai DITERIMA. Lanjutkan verifikasi alamat dan kelengkapan.");
    setForm((old) => ({ ...old, letterNumber: "", sender: "", subject: "", description: "" })); setFile(null); await load();
  };

  return <div className="space-y-6">
    <div><p className="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">SIMARS v2 · Registrasi terkendali</p><h1 className="mt-2 text-2xl font-semibold text-slate-900">Penerimaan surat masuk</h1><p className="mt-1 text-sm text-slate-500">Registrasikan naskah, tetapkan keamanan dan kode arsip sebelum diarahkan.</p></div>
    <div className="grid gap-6 xl:grid-cols-[1.15fr_.85fr]">
      <Card><CardHeader><CardTitle className="text-base">Registrasi naskah dinas</CardTitle></CardHeader><CardContent><form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
        <div><Label>Nomor agenda</Label><Input className="mt-1 bg-slate-50" value={agenda} readOnly /></div><div><Label>Nomor surat *</Label><Input className="mt-1" required value={form.letterNumber} onChange={(e) => set("letterNumber", e.target.value)} /></div>
        <div><Label>Tanggal surat *</Label><Input className="mt-1" type="date" required value={form.letterDate} onChange={(e) => set("letterDate", e.target.value)} /></div><div><Label>Tanggal diterima *</Label><Input className="mt-1" type="date" required value={form.receivedDate} onChange={(e) => set("receivedDate", e.target.value)} /></div>
        <div><Label>Asal penerimaan *</Label><select className={selectClass + " mt-1"} value={form.sourceChannel} onChange={(e) => set("sourceChannel", e.target.value)}>{V2_SOURCE_CHANNELS.map((v) => <option key={v}>{v}</option>)}</select></div><div><Label>Jenis naskah *</Label><select className={selectClass + " mt-1"} value={form.documentType} onChange={(e) => set("documentType", e.target.value)}>{V2_DOCUMENT_TYPES.map((v) => <option key={v}>{v}</option>)}</select></div>
        <div><Label>Pengirim *</Label><Input className="mt-1" required value={form.sender} onChange={(e) => set("sender", e.target.value)} /></div><div><Label>Jenis surat *</Label><select className={selectClass + " mt-1"} value={form.letterCategory} onChange={(e) => set("letterCategory", e.target.value)}><option>DINAS</option><option>PRIBADI</option></select></div>
        <div className="sm:col-span-2"><Label>Perihal *</Label><Input className="mt-1" required value={form.subject} onChange={(e) => set("subject", e.target.value)} /></div>
        <div><Label>Level keamanan MA *</Label><select className={selectClass + " mt-1"} value={form.securityLevel} onChange={(e) => set("securityLevel", e.target.value)}>{V2_SECURITY_LEVELS.map((v) => <option key={v.value} value={v.value}>{v.label}</option>)}</select></div><div><Label>Kode klasifikasi arsip *</Label><select className={selectClass + " mt-1"} required value={form.archiveCode} onChange={(e) => set("archiveCode", e.target.value)}><option value="">Pilih kode</option>{archiveOptions.map((v) => <option key={v.code} value={v.code}>{v.label}</option>)}</select></div>
        <div className="sm:col-span-2"><Label className="text-sm font-medium text-slate-700">Unggah scan surat (opsional)</Label><Input type="file" className="mt-1 h-9 rounded-md text-sm" accept={V2_ATTACHMENT_ACCEPT} onChange={(e) => setFile(e.target.files?.[0] || null)} /><p className="mt-1 text-xs text-slate-500">{V2_ATTACHMENT_HINT}.</p>{file && <p className="mt-1 text-xs text-emerald-700">Lampiran siap diunggah: {file.name}</p>}</div>
        <div className="sm:col-span-2"><Label>Catatan penerimaan</Label><textarea className="mt-1 min-h-20 w-full rounded-md border border-slate-200 p-3 text-sm" value={form.description} onChange={(e) => set("description", e.target.value)} /></div>
        {message && <p className="sm:col-span-2 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{message}</p>}<div className="sm:col-span-2 flex justify-end"><Button type="submit">Simpan sebagai diterima</Button></div>
      </form></CardContent></Card>
      <Card><CardHeader><CardTitle className="text-base">Kontrol wajib sebelum disposisi</CardTitle></CardHeader><CardContent className="space-y-3 text-sm text-slate-600"><p>Setelah registrasi, petugas wajib melanjutkan:</p><ol className="list-decimal space-y-2 pl-5"><li>Verifikasi alamat tujuan dan catat hasilnya.</li><li>Pastikan surat Dinas/Pribadi sudah benar.</li><li>Periksa nomor, tanggal, perihal, lampiran, dan tanda tangan.</li><li>Tetapkan kode arsip resmi dan level keamanan.</li><li>Teruskan melalui Buku Kendali, bukan hanya chat.</li></ol><div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">Surat Rahasia dan Sangat Rahasia tidak dikirim ke grup WhatsApp pada v2.</div></CardContent></Card>
    </div>
    <Card><CardHeader className="flex flex-row items-center justify-between"><CardTitle className="text-base">Surat terbaru · alur v2</CardTitle><a className="text-sm font-medium text-emerald-700 underline-offset-4 hover:underline" href="/v2/buku-kendali">Buka Buku Kendali →</a></CardHeader><CardContent><div className="space-y-2">{letters.slice(0, 8).map((letter) => <div key={letter.id} className="flex flex-col gap-2 rounded-md border border-slate-100 p-3 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-sm font-medium">{letter.subject}</p><p className="text-xs text-slate-500">{letter.agendaNumber} · {letter.sender} · {letter.archiveCode || "kode belum diisi"}{letter.archiveCodeStatus === "PENDING_VALIDATION" ? " (perlu validasi)" : ""}</p></div><div className="flex items-center gap-2">{letter.filePath && <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-emerald-700">Lampiran</Badge>}<Badge variant="outline">{letter.securityLevel || "BIASA"}</Badge><Badge>{V2_STAGE_LABELS[letter.currentStage] || letter.currentStage || "Diterima"}</Badge></div></div>)}{letters.length === 0 && <p className="text-sm text-slate-400">Belum ada surat.</p>}</div></CardContent></Card>
  </div>;
}