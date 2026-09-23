import { useEffect, useState } from "react";
import { useAuth } from "@/src/lib/AuthContext";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import {
  V2_ADDRESS_STATUS_LABELS, V2_COMPLETENESS_LABELS, V2_SECURITY_BADGE_CLASS,
  V2_SECURITY_LEVELS, V2_STAGE_LABELS, V2_STAGES,
  type V2ControlDetail,
} from "@/src/lib/v2Workflow";

const selectClass = "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm";

const CHECK_FIELDS: { key: string; label: string }[] = [
  { key: "addressCorrect", label: "Alamat tujuan sesai" },
  { key: "numberPresent", label: "Nomor naskah ada" },
  { key: "datePresent", label: "Tanggal ada" },
  { key: "subjectPresent", label: "Perihal ada" },
  { key: "attachmentComplete", label: "Lampiran lengkap" },
  { key: "signaturePresent", label: "Tanda tangan/stempel ada" },
];

export default function ControlBook() {
  const { token, user } = useAuth();
  const [rows, setRows] = useState<any[]>([]);
  const [query, setQuery] = useState("");
  const [stageFilter, setStageFilter] = useState("");
  const [secFilter, setSecFilter] = useState("");
  const [detail, setDetail] = useState<V2ControlDetail | null>(null);
  const [notes, setNotes] = useState("");
  const [checks, setChecks] = useState<Record<string, boolean>>({});
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const headers = { Authorization: `Bearer ${token}`, "Content-Type": "application/json" };
  const persuratan = ["ADMIN", "SEKRETARIS", "PANITERA", "KEPALA_SUB_UMUM"].includes(user?.role || "");

  const load = async () => {
    const response = await fetch("/api/control", { headers: { Authorization: `Bearer ${token}` } });
    if (response.ok) setRows(await response.json());
  };
  const openDetail = async (id: string) => {
    setError(""); setMessage(""); setNotes(""); setChecks({});
    const response = await fetch(`/api/control/${id}`, { headers });
    if (!response.ok) { setError("Detail surat tidak dapat dibuka."); return; }
    setDetail(await response.json());
  };
  useEffect(() => { if (token) load(); }, [token]);

  const act = async (path: string, body: any, okMessage: string) => {
    setError(""); setMessage("");
    const response = await fetch(path, { method: "POST", headers, body: JSON.stringify(body) });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) { setError(data.message || "Aksi gagal."); return false; }
    setMessage(okMessage);
    await load();
    if (detail?.letter?.id) await openDetail(detail.letter.id);
    return true;
  };

  const transition = (to: string) =>
    act(`/api/control/${detail?.letter?.id}/transition`, { toStage: to, notes }, `Tahap diperbarui ke ${V2_STAGE_LABELS[to] || to}.`);
  const verifyAddress = (correct: boolean) =>
    act(`/api/control/${detail?.letter?.id}/address-verification`, { correct, notes }, correct ? "Alamat dicatat SESAI." : "Alamat dicatat TIDAK SESAI.");
  const submitChecks = async () => {
    const done = await act(`/api/control/${detail?.letter?.id}/completeness`, { checks, notes }, "Checklist kelengkapan tersimpan.");
    if (done) setChecks({});
  };

  const filtered = rows.filter((r) => {
    const q = query.trim().toLowerCase();
    if (q && ![r.agendaNumber, r.letterNumber, r.sender, r.subject, r.archiveCode].some((v: any) => String(v || "").toLowerCase().includes(q))) return false;
    if (stageFilter && r.currentStage !== stageFilter) return false;
    if (secFilter && r.securityLevel !== secFilter) return false;
    return true;
  });

  const letter = detail?.letter;
  return (
    <div className="space-y-6">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">SIMARS v2 · Buku Kendali</p>
        <h1 className="mt-2 text-2xl font-semibold text-slate-900">Buku Kendali Naskah Dinas</h1>
        <p className="mt-1 text-sm text-slate-500">Riwayat serah-terima dan perpindahan tahap sesuai KMA 131/2023 &amp; SK 627/2023. Setiap aksi tercatat otomatis.</p>
      </div>

      <Card>
        <CardHeader><CardTitle className="text-base">Daftar naskah</CardTitle></CardHeader>
        <CardContent className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-4">
            <Input placeholder="Cari agenda, nomor, pengirim, perihal…" value={query} onChange={(e) => setQuery(e.target.value)} />
            <select className={selectClass} value={stageFilter} onChange={(e) => setStageFilter(e.target.value)}>
              <option value="">Semua tahap</option>
              {V2_STAGES.map((s) => <option key={s} value={s}>{V2_STAGE_LABELS[s]}</option>)}
            </select>
            <select className={selectClass} value={secFilter} onChange={(e) => setSecFilter(e.target.value)}>
              <option value="">Semua keamanan</option>
              {V2_SECURITY_LEVELS.map((v) => <option key={v.value} value={v.value}>{v.label}</option>)}
            </select>
            <div className="flex items-center text-sm text-slate-500">{filtered.length} naskah</div>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                <th className="py-2 pr-3">Agenda</th><th className="py-2 pr-3">Nomor / Pengirim</th><th className="py-2 pr-3">Perihal</th>
                <th className="py-2 pr-3">Keamanan</th><th className="py-2 pr-3">Kode arsip</th><th className="py-2 pr-3">Alamat</th>
                <th className="py-2 pr-3">Kelengkapan</th><th className="py-2 pr-3">Tahap</th><th className="py-2"></th>
              </tr></thead>
              <tbody>
                {filtered.map((r) => (
                  <tr key={r.id} className="border-b border-slate-100 align-top">
                    <td className="py-2 pr-3 font-medium">{r.agendaNumber}</td>
                    <td className="py-2 pr-3"><div>{r.letterNumber}</div><div className="text-xs text-slate-500">{r.sender}</div></td>
                    <td className="py-2 pr-3 max-w-56">{r.subject}</td>
                    <td className="py-2 pr-3"><span className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-medium ${V2_SECURITY_BADGE_CLASS[r.securityLevel] || ""}`}>{r.securityLevel}</span></td>
                    <td className="py-2 pr-3">{r.archiveCode || "—"}{r.archiveCodeStatus === "PENDING_VALIDATION" && <span className="block text-xs text-amber-700" title="Menunggu validasi arsiparis">pending</span>}</td>
                    <td className="py-2 pr-3 text-xs">{V2_ADDRESS_STATUS_LABELS[r.addressStatus] || r.addressStatus}</td>
                    <td className="py-2 pr-3 text-xs">{V2_COMPLETENESS_LABELS[r.completenessStatus] || r.completenessStatus}</td>
                    <td className="py-2 pr-3"><Badge>{V2_STAGE_LABELS[r.currentStage] || r.currentStage}</Badge></td>
                    <td className="py-2"><Button size="sm" variant="outline" onClick={() => openDetail(r.id)}>Buka</Button></td>
                  </tr>
                ))}
                {filtered.length === 0 && <tr><td colSpan={9} className="py-6 text-center text-slate-400">Belum ada naskah yang cocok.</td></tr>}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {letter && (
        <Card>
          <CardHeader><CardTitle className="text-base">Detail kendali · {letter.agendaNumber}</CardTitle></CardHeader>
          <CardContent className="space-y-5">
            <div className="grid gap-2 text-sm sm:grid-cols-2">
              <p><span className="text-slate-500">Pengirim:</span> {letter.sender}</p>
              <p><span className="text-slate-500">Nomor:</span> {letter.letterNumber}</p>
              <p className="sm:col-span-2"><span className="text-slate-500">Perihal:</span> {letter.subject}</p>
              <p><span className="text-slate-500">Keamanan:</span> <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-medium ${V2_SECURITY_BADGE_CLASS[letter.securityLevel] || ""}`}>{letter.securityLevel}</span></p>
              <p><span className="text-slate-500">Tahap saat ini:</span> {V2_STAGE_LABELS[letter.currentStage] || letter.currentStage}</p>
              <p><span className="text-slate-500">Kode arsip:</span> {letter.archiveCode || "—"} {letter.archiveCodeStatus === "PENDING_VALIDATION" && <span className="text-xs text-amber-700">(menunggu validasi arsiparis)</span>}</p>
              <p><span className="text-slate-500">Kelengkapan:</span> {V2_COMPLETENESS_LABELS[letter.completenessStatus] || letter.completenessStatus}</p>
            </div>
            {(message || error) && <p className={`rounded-md px-3 py-2 text-sm ${error ? "bg-rose-50 text-rose-800" : "bg-emerald-50 text-emerald-800"}`}>{error || message}</p>}
            <div><Label>Catatan (opsional, ikut tercatat di log)</Label><textarea className="mt-1 min-h-16 w-full rounded-md border border-slate-200 p-3 text-sm" value={notes} onChange={(e) => setNotes(e.target.value)} /></div>

            {persuratan && (
              <div className="grid gap-4 lg:grid-cols-2">
                <div className="rounded-md border border-slate-200 p-3">
                  <p className="text-sm font-medium">Verifikasi alamat tujuan</p>
                  <p className="mt-1 text-xs text-slate-500">Status: {V2_ADDRESS_STATUS_LABELS[letter.addressStatus] || letter.addressStatus}</p>
                  <div className="mt-2 flex gap-2">
                    <Button size="sm" disabled={letter.addressStatus === "ALAMAT_SESAI"} onClick={() => verifyAddress(true)}>Alamat sesai</Button>
                    <Button size="sm" variant="destructive" disabled={letter.addressStatus === "ALAMAT_TIDAK_SESAI"} onClick={() => verifyAddress(false)}>Tidak sesai</Button>
                  </div>
                </div>
                <div className="rounded-md border border-slate-200 p-3">
                  <p className="text-sm font-medium">Checklist kelengkapan naskah</p>
                  <p className="mt-1 text-xs text-slate-500">Status: {V2_COMPLETENESS_LABELS[letter.completenessStatus] || letter.completenessStatus}</p>
                  <div className="mt-2 grid grid-cols-2 gap-1 text-sm">
                    {CHECK_FIELDS.map((f) => (
                      <label key={f.key} className="flex items-center gap-2">
                        <input type="checkbox" checked={!!checks[f.key]} onChange={(e) => setChecks((old) => ({ ...old, [f.key]: e.target.checked }))} />
                        {f.label}
                      </label>
                    ))}
                  </div>
                  <Button size="sm" className="mt-2" onClick={submitChecks}>Simpan checklist</Button>
                </div>
              </div>
            )}

            <div>
              <p className="text-sm font-medium">Transisi tahap yang diizinkan untuk role {user?.role}</p>
              {detail.allowedNextStages.length === 0 && <p className="mt-1 text-xs text-slate-500">Tidak ada transisi yang dapat dilakukan role ini pada tahap saat ini.</p>}
              <div className="mt-2 flex flex-wrap gap-2">
                {detail.allowedNextStages.map((s) => <Button key={s} size="sm" onClick={() => transition(s)}>{V2_STAGE_LABELS[s] || s}</Button>)}
              </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
              <div>
                <p className="text-sm font-medium">Riwayat Buku Kendali</p>
                <ol className="mt-2 space-y-2 border-l-2 border-slate-200 pl-4">
                  {detail.logs.map((l) => (
                    <li key={l.id} className="text-sm">
                      <p className="font-medium">{l.action}{l.toStage && l.toStage !== l.fromStage ? ` → ${V2_STAGE_LABELS[l.toStage] || l.toStage}` : ""}</p>
                      <p className="text-xs text-slate-500">{l.createdAt}{l.actorName ? ` · ${l.actorName} (${l.actorRole || "-"})` : ""}</p>
                      {l.notes && <p className="text-xs text-slate-600">{l.notes}</p>}
                    </li>
                  ))}
                  {detail.logs.length === 0 && <li className="text-sm text-slate-400">Belum ada catatan.</li>}
                </ol>
              </div>
              <div>
                <p className="text-sm font-medium">Riwayat pemeriksaan kelengkapan</p>
                <ol className="mt-2 space-y-2">
                  {detail.checks.map((c) => (
                    <li key={c.id} className="rounded-md border border-slate-100 p-2 text-sm">
                      <p className="text-xs text-slate-500">{c.createdAt} · {c.checkedByName || "-"}</p>
                      <p>{CHECK_FIELDS.filter((f) => (c as any)[f.key]).map((f) => f.label).join(", ") || "Tidak ada butir terpenuhi"}</p>
                      {c.notes && <p className="text-xs text-slate-600">{c.notes}</p>}
                    </li>
                  ))}
                  {detail.checks.length === 0 && <li className="text-sm text-slate-400">Belum pernah diperiksa.</li>}
                </ol>
              </div>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
