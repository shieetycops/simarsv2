import { useEffect, useRef, useState } from "react";
import type React from "react";
import { useAuth } from "@/src/lib/AuthContext";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from "@/components/ui/dialog";
import { FileText, Loader2, Pencil, Trash2, Download, Undo2 } from "lucide-react";
import {
  V2_ADDRESS_STATUS_LABELS, V2_ATTACHMENT_ACCEPT, V2_ATTACHMENT_HINT,
  V2_COMPLETENESS_LABELS, V2_DISPOSITION_ROUTE_LABELS, V2_DISPOSITION_ROUTE_TARGETS,
  V2_DOCUMENT_TYPES, V2_LETTER_CATEGORIES, V2_REOPEN_REASON_MIN, V2_REOPEN_TARGET_STAGE,
  V2_UNIT_TUJUAN_LABELS, V2_UNIT_TUJUAN_LIST,
  V2_ROLE_LABELS, V2_SECURITY_BADGE_CLASS, V2_SECURITY_LEVELS,
  V2_SOURCE_CHANNELS, V2_STAGE_LABELS, V2_STAGES, V2_URGENCY_LEVELS,
  v2StageOwnerText,
  type V2AllowedTransition, type V2ControlDetail,
} from "@/src/lib/v2Workflow";

const selectClass = "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm";

const CHECK_FIELDS: { key: string; label: string }[] = [
  { key: "addressCorrect", label: "Alamat tujuan sesuai" },
  { key: "numberPresent", label: "Nomor naskah ada" },
  { key: "datePresent", label: "Tanggal ada" },
  { key: "subjectPresent", label: "Perihal ada" },
  { key: "attachmentComplete", label: "Lampiran lengkap" },
  { key: "signaturePresent", label: "Tanda tangan/stempel ada" },
];

// ---- Ekspor CSV daftar Buku Kendali -----------------------------------------
// Menu "Surat Masuk" lama punya item Export Excel/Cetak PDF yang ternyata tidak
// terhubung ke apa pun (DropdownMenuItem tanpa onClick), jadi ekspor ini dibuat
// baru: mengikuti filter/pencarian yang sedang aktif, memakai BOM UTF-8 agar
// Excel membaca huruf beraksen dengan benar, dan pemisah ";" yang dikenali
// Excel ber-locale Indonesia.
const CSV_COLUMNS: { header: string; value: (r: any) => string }[] = [
  { header: "Agenda", value: (r) => r.agendaNumber || "" },
  { header: "Nomor surat", value: (r) => r.letterNumber || "" },
  { header: "Tanggal surat", value: (r) => String(r.letterDate || "").slice(0, 10) },
  { header: "Tanggal terima", value: (r) => String(r.receivedDate || "").slice(0, 10) },
  { header: "Pengirim", value: (r) => r.sender || "" },
  { header: "Perihal", value: (r) => r.subject || "" },
  { header: "Jenis surat", value: (r) => r.letterCategory || "" },
  { header: "Jenis naskah", value: (r) => r.documentType || "" },
  { header: "Sumber", value: (r) => r.sourceChannel || "" },
  { header: "Keamanan", value: (r) => r.securityLevel || "" },
  { header: "Urgensi", value: (r) => r.urgencyLevel || "" },
  { header: "Kode arsip", value: (r) => r.archiveCode || "" },
  { header: "Status kode arsip", value: (r) => r.archiveCodeStatus || "" },
  { header: "Alamat", value: (r) => V2_ADDRESS_STATUS_LABELS[r.addressStatus] || r.addressStatus || "" },
  { header: "Kelengkapan", value: (r) => V2_COMPLETENESS_LABELS[r.completenessStatus] || r.completenessStatus || "" },
  { header: "Tahap", value: (r) => V2_STAGE_LABELS[r.currentStage] || r.currentStage || "" },
  { header: "Pelaksana", value: (r) => r.assigneeName || "" },
  { header: "Rute keputusan", value: (r) => (r.dispositionRoute ? V2_DISPOSITION_ROUTE_LABELS[r.dispositionRoute] || r.dispositionRoute : "") },
  { header: "Lampiran", value: (r) => (r.filePath ? "Ada" : "Tidak ada") },
];

const csvCell = (value: any) => `"${String(value ?? "").replace(/"/g, '""')}"`;

export function buildControlBookCsv(rows: any[]): string {
  const lines = [CSV_COLUMNS.map((c) => csvCell(c.header)).join(";")];
  rows.forEach((r) => lines.push(CSV_COLUMNS.map((c) => csvCell(c.value(r))).join(";")));
  return lines.join("\r\n");
}

export default function ControlBook() {
  const { token, user } = useAuth();
  const [rows, setRows] = useState<any[]>([]);
  const [query, setQuery] = useState("");
  const [stageFilter, setStageFilter] = useState("");
  const [secFilter, setSecFilter] = useState("");
  const [detail, setDetail] = useState<V2ControlDetail | null>(null);
  const [notes, setNotes] = useState("");
  const [decisionRoute, setDecisionRoute] = useState("");
  // --- Revisi SOP/AS/04 langkah 11-18: rekomendasi Kasubag (langkah 12),
  // unit tujuan pelaksana (Fix 3), arahan pimpinan (Q1: final), dan
  // penunjukan pegawai oleh kepala unit (Fix 4). Semua tetap divalidasi
  // server; UI hanya mencegah pengiriman yang pasti ditolak 422. ---
  const [rekomRoute, setRekomRoute] = useState("");
  const [rekomNotes, setRekomNotes] = useState("");
  const [unitTujuan, setUnitTujuan] = useState("");
  const [arahan, setArahan] = useState("");
  // K5/P4 (revisi kedua): unit tujuan OPSIONAL pada arahan pimpinan — tanpa
  // unit, arahan tetap tersimpan dan Sekretaris memilih unit saat TERUSKAN
  // (sama seperti penanda #KODE_UNIT di WhatsApp).
  const [arahanUnit, setArahanUnit] = useState("");
  const [pegawaiList, setPegawaiList] = useState<{ id: string; name: string; role: string }[]>([]);
  const [pegawaiId, setPegawaiId] = useState("");
  // K7 (revisi kedua): serah-terima lembar 1 disposisi — penyerah & penerima.
  const [lembarPenyerah, setLembarPenyerah] = useState("");
  const [lembarPenerima, setLembarPenerima] = useState("");
  // K7: peringatan "arsip tanpa lembar 1" dari server (field `warning`).
  const [warning, setWarning] = useState("");
  const [checks, setChecks] = useState<Record<string, boolean>>({});
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  // --- Koreksi data surat (porting fitur Edit/Hapus menu "Surat Masuk" lama) ---
  // Wajib ada di Buku Kendali karena menu lama akan diarahkan ke sini: tanpa
  // ini, petugas kehilangan satu-satunya jalan memperbaiki salah entri.
  const [editOpen, setEditOpen] = useState(false);
  const [editForm, setEditForm] = useState<any>(null);
  const [editFile, setEditFile] = useState<File | null>(null);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  // --- Fase 1: penarikan kembali surat ke meja Kasubag (rollback) ---
  // Alasan WAJIB (server menolak < V2_REOPEN_REASON_MIN karakter) karena gerak
  // mundur tahap harus bisa diaudit: tercatat sebagai action STAGE_REOPEN di
  // riwayat Buku Kendali.
  const [reopenOpen, setReopenOpen] = useState(false);
  const [reopenReason, setReopenReason] = useState("");

  // Umpan balik tombol "Buka": hasilnya dirender sebagai kartu di BAWAH tabel,
  // sehingga tanpa indikator + gulir otomatis tombolnya seperti tidak bereaksi
  // (hasil uji klik nyata: kartu muncul di y=1137 padahal tinggi layar 805).
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [selectedId, setSelectedId] = useState("");
  const detailRef = useRef<HTMLDivElement | null>(null);
  // Menyimpan id terakhir yang sudah digulirkan supaya aksi di dalam kartu
  // (transisi/verifikasi/checklist) tidak mengembalikan posisi gulir ke atas.
  const scrolledLetterRef = useRef("");

  // --- Filter tanggal terima + jenis surat + paginasi (juga dari menu lama) ---
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [page, setPage] = useState(1);
  const pageSize = 10;

  const headers = { Authorization: `Bearer ${token}`, "Content-Type": "application/json" };
  const persuratan = ["ADMIN", "SEKRETARIS", "PANITERA", "KEPALA_SUB_UMUM"].includes(user?.role || "");

  const load = async () => {
    const response = await fetch("/api/control", { headers: { Authorization: `Bearer ${token}` } });
    if (response.ok) setRows(await response.json());
  };
  const openDetail = async (id: string) => {
    setError(""); setMessage(""); setNotes(""); setChecks({}); setDecisionRoute("");
    setRekomRoute(""); setRekomNotes(""); setUnitTujuan(""); setArahan(""); setArahanUnit("");
    setPegawaiId(""); setLembarPenyerah(""); setLembarPenerima(""); setWarning("");
    setSelectedId(id);
    setLoadingDetail(true);
    try {
      const response = await fetch(`/api/control/${id}`, { headers });
      if (!response.ok) {
        // Kartu detail tidak akan dirender, jadi detail lama dibuang agar
        // pengguna tidak membaca data surat yang salah baris.
        setDetail(null);
        setError(response.status === 403
          ? "Anda tidak berwenang membuka surat dengan level keamanan ini."
          : "Detail surat tidak dapat dibuka.");
        return;
      }
      setDetail(await response.json());
    } catch (err) {
      console.error(err);
      setDetail(null);
      setError("Detail surat tidak dapat dibuka (koneksi ke server gagal).");
    } finally {
      setLoadingDetail(false);
    }
  };
  useEffect(() => { if (token) load(); }, [token]);

  const act = async (path: string, body: any, okMessage: string) => {
    setError(""); setMessage(""); setWarning("");
    const response = await fetch(path, { method: "POST", headers, body: JSON.stringify(body) });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) { setError(data.message || "Aksi gagal."); return false; }
    // K7: server menyertakan `warning` (mis. arsip tanpa serah-terima lembar 1).
    setWarning(String(data.warning || ""));
    setMessage(okMessage);
    await load();
    if (detail?.letter?.id) await openDetail(detail.letter.id);
    return true;
  };

  // requiresRoute: tombol "Didisposisikan" dari tahap MENUNGGU_DISPOSISI wajib
  // menyertakan keputusan Sekretaris/Panitera (SOP/AS/04 langkah 13); server
  // menolak 422 bila tidak ada, jadi UI tidak boleh mengirim tanpa rute.
  const transition = async (to: string, route?: string) => {
    const body: Record<string, any> = { toStage: to, notes };
    // Fix 3: unit tujuan WAJIB untuk masuk unit pelaksana. Hanya dikirim bila
    // dipilih — bila kosong, server memakai unit tersimpan atau menolak 422
    // (UNIT_TUJUAN_REQUIRED) dengan field unit_tujuan untuk ditandai UI.
    if (to === "DITERUSKAN_KE_PELAKSANA" && unitTujuan) body.unit_tujuan = unitTujuan;
    // Q1 locked: pimpinan mengisi ARAHAN saat mengembalikan surat ke
    // Sekretaris/Panitera (SOP/AS/04 langkah 14); tanpa itu server 422.
    // K5/P4 (revisi kedua): unit tujuan pada arahan OPSIONAL — tanpa unit,
    // arahan tetap tersimpan dan Sekretaris memilih unit saat TERUSKAN.
    if (detail?.letter?.currentStage === "MENUNGGU_KEBIJAKAN_PIMPINAN"
      && to === "DITERUSKAN_KE_SEKRETARIS_PANITERA" && arahan.trim()) {
      body.arahan = arahan.trim();
      if (arahanUnit) body.unit_tujuan = arahanUnit;
    }
    if (route) body.dispositionRoute = route;
    const done = await act(
      `/api/control/${detail?.letter?.id}/transition`,
      body,
      route
        ? `Keputusan dicatat: ${V2_DISPOSITION_ROUTE_LABELS[route] || route} -> ${V2_STAGE_LABELS[to] || to}.`
        : `Tahap diperbarui ke ${V2_STAGE_LABELS[to] || to}.`,
    );
    if (done) { setDecisionRoute(""); setUnitTujuan(""); setArahan(""); setArahanUnit(""); }
    return done;
  };
  const verifyAddress = (correct: boolean) =>
    act(`/api/control/${detail?.letter?.id}/address-verification`, { correct, notes }, correct ? "Alamat dicatat SESUAI." : "Alamat dicatat TIDAK SESUAI.");
  const submitChecks = async () => {
    const done = await act(`/api/control/${detail?.letter?.id}/completeness`, { checks, notes }, "Checklist kelengkapan tersimpan.");
    if (done) setChecks({});
  };

  // Revisi SOP/AS/04 langkah 12: REKOMENDASI rute oleh Kasubag Umum.
  // Rekomendasi bukan keputusan final — endpoint terpisah dari transisi
  // supaya jejaknya (action REKOMENDASI_ROUTE) tidak tertukar dengan
  // keputusan Sekretaris/Panitera (langkah 13).
  const submitRekomendasi = async () => {
    const done = await act(
      `/api/control/${detail?.letter?.id}/rekomendasi`,
      { rekomendasiRoute: rekomRoute, notes: rekomNotes },
      `Rekomendasi tersimpan: ${V2_DISPOSITION_ROUTE_LABELS[rekomRoute] || rekomRoute}. Keputusan final tetap di Sekretaris/Panitera.`,
    );
    if (done) { setRekomRoute(""); setRekomNotes(""); }
  };

  // Fix 4: penunjukan pegawai HANYA oleh kepala unit via TUNJUK di tahap
  // DITERUSKAN_KE_PELAKSANA (SOP/AS/04 langkah 17). Kandidat diambil dari
  // /api/users/list (aktif, semua role) hanya saat panelnya relevan.
  const canTunjuk = Boolean(detail?.isUnitHead && detail?.letter?.currentStage === "DITERUSKAN_KE_PELAKSANA");
  // K7: panel serah-terima lembar 1 juga butuh daftar pegawai (penyerah/penerima).
  const canRecordLembar1 = Boolean(detail?.canRecordLembar1 && !detail?.lembar1?.diserahkanOleh);
  useEffect(() => {
    if ((!canTunjuk && !canRecordLembar1) || pegawaiList.length > 0) return;
    fetch("/api/users/list", { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => (r.ok ? r.json() : []))
      .then((list) => setPegawaiList(Array.isArray(list) ? list : []))
      .catch(() => setPegawaiList([]));
  }, [canTunjuk, canRecordLembar1, token, pegawaiList.length]);
  const submitTunjuk = async () => {
    const done = await act(
      `/api/control/${detail?.letter?.id}/tunjuk`,
      { pegawai_id: pegawaiId },
      "Pegawai ditunjuk sebagai pelaksana surat.",
    );
    if (done) setPegawaiId("");
  };

  // K7 (DEFAULT SEMENTARA - menunggu review pimpinan): catat serah-terima
  // LEMBAR 1 disposisi (SOP/AS/04 langkah 17) — penyerah, penerima, waktu.
  // Dicatat SEKALI (tidak bisa ditimpa); arsip tanpa lembar 1 menghasilkan
  // peringatan (tidak memblokir kecuali toggle "wajib" di Pengaturan aktif).
  const submitLembar1 = async () => {
    const done = await act(
      `/api/control/${detail?.letter?.id}/lembar1`,
      { penyerah_id: lembarPenyerah || undefined, penerima_id: lembarPenerima },
      "Serah-terima lembar 1 disposisi tercatat.",
    );
    if (done) { setLembarPenyerah(""); setLembarPenerima(""); }
  };

  // K3 (DEFAULT SEMENTARA - menunggu review pimpinan): TOLAK satu langkah oleh
  // pemegang surat / RECALL oleh pengirim sebelum penerima bertindak / koreksi
  // ADMIN. Rencananya (rejectMode/rejectTarget) dihitung server; UI hanya
  // merender apa yang diizinkan server.
  const submitReopen = async () => {
    const targetLabel = detail?.rejectTargetLabel
      || V2_STAGE_LABELS[V2_REOPEN_TARGET_STAGE] || V2_REOPEN_TARGET_STAGE;
    const verb = detail?.rejectMode === "TOLAK"
      ? "ditolak & dikembalikan satu langkah"
      : (detail?.rejectMode === "RECALL" ? "ditarik kembali oleh pengirimnya" : "dikoreksi ke");
    const done = await act(`/api/control/${detail?.letter?.id}/reopen`, { reason: reopenReason },
      `Surat ${verb} ke ${targetLabel}.`);
    if (done) { setReopenOpen(false); setReopenReason(""); }
  };

  const setEditField = (key: string, value: string) => setEditForm((old: any) => ({ ...old, [key]: value }));

  // Dialog koreksi diisi dari data terbaru server (bukan dari baris tabel),
  // supaya nilai yang tampil sama dengan yang akan ditimpa.
  const openEdit = () => {
    const l = detail?.letter;
    if (!l) return;
    setEditFile(null); setError(""); setMessage("");
    setEditForm({
      id: l.id,
      agendaNumber: l.agendaNumber || "",
      letterNumber: l.letterNumber || "",
      letterDate: String(l.letterDate || "").slice(0, 10),
      receivedDate: String(l.receivedDate || "").slice(0, 10),
      sender: l.sender || "",
      subject: l.subject || "",
      letterCategory: l.letterCategory || "DINAS",
      documentType: l.documentType || "SURAT_DINAS",
      sourceChannel: l.sourceChannel || "LAINNYA",
      securityLevel: l.securityLevel || "BIASA",
      urgencyLevel: l.urgencyLevel || "NORMAL",
      archiveCode: l.archiveCode || "",
      description: l.description || "",
      filePath: l.filePath || "",
    });
    setEditOpen(true);
  };

  // Koreksi data: PUT /api/incoming/:id. Batas tahap & role ditentukan server,
  // jadi pesan 403/422/400 dari server ditampilkan apa adanya.
  const saveEdit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!editForm?.id) return;
    setBusy(true); setError(""); setMessage("");
    try {
      const payload: any = { ...editForm };
      delete payload.id;
      delete payload.filePath;
      // Kolom lama "classification" dijaga sinkron dengan "letterCategory" v2
      // supaya laporan lama yang membaca classification tidak menampilkan nilai
      // lama setelah petugas mengoreksi jenis surat.
      payload.classification = payload.letterCategory;
      // PHP hanya memparsing body multipart pada request POST, jadi unggahan
      // lampiran dikirim sebagai POST + _method=PUT (jalur yang diterima
      // handlers/incoming.php). Tanpa ini, berkas pengganti tidak pernah masuk.
      let response: Response;
      if (editFile) {
        const body = new FormData();
        body.append("_method", "PUT");
        body.append("data", JSON.stringify(payload));
        body.append("file", editFile);
        response = await fetch(`/api/incoming/${editForm.id}`, {
          method: "POST",
          headers: { Authorization: `Bearer ${token}` },
          body,
        });
      } else {
        response = await fetch(`/api/incoming/${editForm.id}`, {
          method: "PUT",
          headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        });
      }
      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        const fieldErrors = data.errors
          ? Object.keys(data.errors).map((k) => (data.errors[k] || []).join(" ")).join(" ")
          : "";
        setError(`${data.message || "Data surat gagal diperbarui."} ${fieldErrors}`.trim());
        return;
      }
      setEditOpen(false); setEditFile(null);
      setMessage("Data surat berhasil diperbarui. Perubahan tercatat di audit log.");
      await load();
      await openDetail(editForm.id);
    } catch (err) {
      console.error(err);
      setError("Data surat gagal diperbarui.");
    } finally {
      setBusy(false);
    }
  };

  const confirmDelete = async () => {
    const id = detail?.letter?.id;
    if (!id) return;
    setBusy(true); setError(""); setMessage("");
    try {
      const response = await fetch(`/api/incoming/${id}`, {
        method: "DELETE",
        headers: { Authorization: `Bearer ${token}` },
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) { setError(data.message || "Surat gagal dihapus."); return; }
      setDeleteOpen(false);
      setDetail(null);
      setSelectedId("");
      setMessage(data.message || "Surat berhasil dihapus.");
      await load();
    } catch (err) {
      console.error(err);
      setError("Surat gagal dihapus.");
    } finally {
      setBusy(false);
    }
  };

  // Ekspor mengikuti filter/pencarian yang sedang aktif, bukan seluruh tabel.
  const exportCsv = () => {
    const blob = new Blob(["\uFEFF" + buildControlBookCsv(filtered)], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `buku-kendali-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  };

  const filtered = rows.filter((r) => {
    const q = query.trim().toLowerCase();
    if (q && ![r.agendaNumber, r.letterNumber, r.sender, r.subject, r.archiveCode].some((v: any) => String(v || "").toLowerCase().includes(q))) return false;
    if (stageFilter && r.currentStage !== stageFilter) return false;
    if (secFilter && r.securityLevel !== secFilter) return false;
    if (categoryFilter && (r.letterCategory || "") !== categoryFilter) return false;
    // Rentang tanggal terima dibandingkan pada bagian tanggalnya saja supaya
    // batasnya inklusif (surat yang diterima tepat pada tanggal "sampai" ikut).
    const received = String(r.receivedDate || "").slice(0, 10);
    if (dateFrom && (!received || received < dateFrom)) return false;
    if (dateTo && (!received || received > dateTo)) return false;
    return true;
  });

  // Paginasi (porting dari menu lama: 10 baris per halaman).
  const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
  const safePage = Math.min(page, totalPages);
  const pageRows = filtered.slice((safePage - 1) * pageSize, safePage * pageSize);
  const hasFilter = Boolean(query || stageFilter || secFilter || categoryFilter || dateFrom || dateTo);
  const clearFilters = () => {
    setQuery(""); setStageFilter(""); setSecFilter(""); setCategoryFilter(""); setDateFrom(""); setDateTo("");
  };
  // Setiap perubahan filter mengembalikan tampilan ke halaman pertama; tanpa ini
  // pengguna bisa "hilang" di halaman 3 dari daftar hasil yang hanya 1 halaman.
  useEffect(() => { setPage(1); }, [query, stageFilter, secFilter, categoryFilter, dateFrom, dateTo]);

  // Kartu detail dirender di bawah tabel: begitu datanya siap, gulirkan layar ke
  // kartu itu supaya klik "Buka" terlihat hasilnya. Digulirkan sekali per surat
  // agar aksi di dalam kartu tidak melompatkan posisi gulir.
  useEffect(() => {
    const id = detail?.letter?.id;
    if (!id || scrolledLetterRef.current === id) return;
    scrolledLetterRef.current = id;
    detailRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
  }, [detail?.letter?.id]);

  const letter = detail?.letter;
  const transitions: V2AllowedTransition[] = detail?.allowedTransitions ?? [];
  const decisionRoutes = detail?.dispositionRoutes ?? [];
  // Tombol yang butuh rute keputusan dirender terpisah agar Sekretaris/Panitera
  // tidak bisa menekan "Didisposisikan" tanpa memilih rute (server menolak 422).
  const decisionTransitions = transitions.filter((t) => t.requiresRoute);
  const plainTransitions = transitions.filter((t) => !t.requiresRoute);
  return (
    <div className="space-y-6">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">SIMARS v2 · Buku Kendali</p>
        <h1 className="mt-2 text-2xl font-semibold text-slate-900">Buku Kendali Naskah Dinas</h1>
        <p className="mt-1 text-sm text-slate-500">Riwayat serah-terima dan perpindahan tahap sesuai KMA 131/2023 &amp; SK 627/2023. Setiap aksi tercatat otomatis.</p>
      </div>

      {/* Status tingkat halaman: kartu Detail ada di bawah tabel, jadi kesalahan
          saat "Buka" (mis. 403 surat rahasia) dulu tidak tampil di mana pun. */}
      {loadingDetail && (
        <p className="flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
          <Loader2 className="mr-2 h-4 w-4 animate-spin" />Membuka detail surat…
        </p>
      )}
      {error && !letter && <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">{error}</p>}

      <Card>
        <CardHeader><CardTitle className="text-base">Daftar naskah</CardTitle></CardHeader>
        <CardContent className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
            <Input placeholder="Cari agenda, nomor, pengirim, perihal…" value={query} onChange={(e) => setQuery(e.target.value)} />
            <select className={selectClass} value={stageFilter} onChange={(e) => setStageFilter(e.target.value)}>
              <option value="">Semua tahap</option>
              {V2_STAGES.map((s) => <option key={s} value={s}>{V2_STAGE_LABELS[s]}</option>)}
            </select>
            <select className={selectClass} value={secFilter} onChange={(e) => setSecFilter(e.target.value)}>
              <option value="">Semua keamanan</option>
              {V2_SECURITY_LEVELS.map((v) => <option key={v.value} value={v.value}>{v.label}</option>)}
            </select>
            <select className={selectClass} value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)}>
              <option value="">Semua jenis surat</option>
              {V2_LETTER_CATEGORIES.map((c) => <option key={c} value={c}>{c === "DINAS" ? "Dinas" : "Pribadi"}</option>)}
            </select>
            <div>
              <Label className="text-xs text-slate-500">Diterima dari</Label>
              <Input type="date" className="mt-1" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
            </div>
            <div>
              <Label className="text-xs text-slate-500">Sampai</Label>
              <Input type="date" className="mt-1" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
            </div>
          </div>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm text-slate-500">
              {filtered.length} naskah{filtered.length !== rows.length ? ` (dari ${rows.length} total)` : ""}
            </p>
            <div className="flex flex-wrap gap-2">
              {hasFilter && <Button size="sm" variant="ghost" onClick={clearFilters}>Bersihkan filter</Button>}
              <Button size="sm" variant="outline" disabled={filtered.length === 0} onClick={exportCsv}>
                <Download className="mr-1.5 h-4 w-4" />Ekspor CSV
              </Button>
            </div>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                <th className="py-2 pr-3">Agenda</th><th className="py-2 pr-3">Nomor / Pengirim</th><th className="py-2 pr-3">Perihal</th>
                <th className="py-2 pr-3">Keamanan</th><th className="py-2 pr-3">Kode arsip</th><th className="py-2 pr-3">Alamat</th>
                <th className="py-2 pr-3">Kelengkapan</th><th className="py-2 pr-3">Tahap</th>
                <th className="py-2 pr-3">Pelaksana</th><th className="py-2"></th>
              </tr></thead>
              <tbody>
                {pageRows.map((r) => (
                  <tr key={r.id} className={`border-b border-slate-100 align-top ${selectedId === r.id ? "bg-emerald-50/60" : ""}`}>
                    <td className="py-2 pr-3 font-medium">{r.agendaNumber}</td>
                    <td className="py-2 pr-3"><div>{r.letterNumber}</div><div className="text-xs text-slate-500">{r.sender}</div></td>
                    <td className="py-2 pr-3 max-w-56">
                      {r.subject}
                      {r.filePath && <span className="ml-1 inline-flex align-middle text-emerald-700" title="Ada lampiran scan"><FileText className="inline h-3.5 w-3.5" /></span>}
                    </td>
                    <td className="py-2 pr-3"><span className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-medium ${V2_SECURITY_BADGE_CLASS[r.securityLevel] || ""}`}>{r.securityLevel}</span></td>
                    <td className="py-2 pr-3">{r.archiveCode || "—"}{r.archiveCodeStatus === "PENDING_VALIDATION" && <span className="block text-xs text-amber-700" title="Menunggu validasi arsiparis">pending</span>}</td>
                    <td className="py-2 pr-3 text-xs">{V2_ADDRESS_STATUS_LABELS[r.addressStatus] || r.addressStatus}</td>
                    <td className="py-2 pr-3 text-xs">{V2_COMPLETENESS_LABELS[r.completenessStatus] || r.completenessStatus}</td>
                    <td className="py-2 pr-3"><Badge>{V2_STAGE_LABELS[r.currentStage] || r.currentStage}</Badge></td>
                    <td className="py-2 pr-3 text-xs">{r.assigneeName || <span className="text-slate-400">belum ditunjuk</span>}</td>
                    <td className="py-2">
                      <Button size="sm" variant="outline" disabled={loadingDetail && selectedId === r.id} onClick={() => openDetail(r.id)}>
                        {loadingDetail && selectedId === r.id
                          ? <><Loader2 className="mr-1.5 h-4 w-4 animate-spin" />Membuka…</>
                          : "Buka"}
                      </Button>
                    </td>
                  </tr>
                ))}
                {pageRows.length === 0 && <tr><td colSpan={10} className="py-6 text-center text-slate-400">Belum ada naskah yang cocok.</td></tr>}
              </tbody>
            </table>
          </div>
          <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
            <p className="text-slate-500">Halaman {safePage} dari {totalPages}</p>
            <div className="flex gap-2">
              <Button size="sm" variant="outline" disabled={safePage <= 1} onClick={() => setPage(safePage - 1)}>Sebelumnya</Button>
              <Button size="sm" variant="outline" disabled={safePage >= totalPages} onClick={() => setPage(safePage + 1)}>Berikutnya</Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {letter && (
        <Card ref={detailRef} className="scroll-mt-4">
          <CardHeader><CardTitle className="text-base">Detail kendali · {letter.agendaNumber}</CardTitle></CardHeader>
          <CardContent className="space-y-5">
            <div className="grid gap-2 text-sm sm:grid-cols-2">
              <p><span className="text-slate-500">Pengirim:</span> {letter.sender}</p>
              <p><span className="text-slate-500">Nomor:</span> {letter.letterNumber}</p>
              <p className="sm:col-span-2"><span className="text-slate-500">Perihal:</span> {letter.subject}</p>
              <p><span className="text-slate-500">Keamanan:</span> <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-medium ${V2_SECURITY_BADGE_CLASS[letter.securityLevel] || ""}`}>{letter.securityLevel}</span></p>
              <p><span className="text-slate-500">Tahap saat ini:</span> {V2_STAGE_LABELS[letter.currentStage] || letter.currentStage}</p>
              {/* Fase 0/1: pelaksana surat + meja yang sedang menunggu. Tanpa ini
                  pengguna hanya melihat tahap tanpa tahu siapa yang harus bergerak. */}
              <p>
                <span className="text-slate-500">Pelaksana:</span>{" "}
                {detail.assignee?.name
                  ? <span className="font-medium">{detail.assignee.name}{detail.assignee.role ? ` (${V2_ROLE_LABELS[detail.assignee.role] || detail.assignee.role})` : ""}</span>
                  : <span className="text-slate-400">belum ditunjuk</span>}
              </p>
              <p>
                <span className="text-slate-500">Menunggu di meja:</span>{" "}
                {v2StageOwnerText(letter.currentStage) || <span className="text-slate-400">pelaksana surat</span>}
              </p>
              <p>
                <span className="text-slate-500">Rute keputusan:</span>{" "}
                {letter.dispositionRoute
                  ? <span className="font-medium">{V2_DISPOSITION_ROUTE_LABELS[letter.dispositionRoute] || letter.dispositionRoute}</span>
                  : <span className="text-slate-400">belum ditetapkan (diputuskan Sekretaris/Panitera)</span>}
              </p>
              <p>
                <span className="text-slate-500">Rekomendasi Kasubag:</span>{" "}
                {detail.rekomendasiRoute
                  ? <span className="font-medium">{V2_DISPOSITION_ROUTE_LABELS[detail.rekomendasiRoute] || detail.rekomendasiRoute}</span>
                  : <span className="text-slate-400">belum ada</span>}
              </p>
              <p>
                <span className="text-slate-500">Unit tujuan:</span>{" "}
                {detail.unitTujuan
                  ? <span className="font-medium">{V2_UNIT_TUJUAN_LABELS[detail.unitTujuan] || detail.unitTujuan}</span>
                  : <span className="text-slate-400">—</span>}
              </p>
              <p className="sm:col-span-2">
                <span className="text-slate-500">Arahan pimpinan:</span>{" "}
                {detail.arahanPimpinan || <span className="text-slate-400">—</span>}
              </p>
              <p><span className="text-slate-500">Kode arsip:</span> {letter.archiveCode || "—"} {letter.archiveCodeStatus === "PENDING_VALIDATION" && <span className="text-xs text-amber-700">(menunggu validasi arsiparis)</span>}</p>
              <p><span className="text-slate-500">Kelengkapan:</span> {V2_COMPLETENESS_LABELS[letter.completenessStatus] || letter.completenessStatus}</p>
              <p><span className="text-slate-500">Tanggal surat:</span> {String(letter.letterDate || "").slice(0, 10) || "-"}</p>
              <p><span className="text-slate-500">Diterima:</span> {String(letter.receivedDate || "").slice(0, 10) || "-"}</p>
              <p><span className="text-slate-500">Jenis surat / naskah:</span> {letter.letterCategory || "-"} / {letter.documentType || "-"}</p>
              <p><span className="text-slate-500">Urgensi:</span> {letter.urgencyLevel || "NORMAL"} · <span className="text-slate-500">Sumber:</span> {letter.sourceChannel || "-"}</p>
            </div>

            {/* Koreksi & hapus: kewenangannya dihitung server (canEdit/canDelete).
                Sebelum ini, satu-satunya jalan memperbaiki salah entri ada di menu
                "Surat Masuk" lama; tanpa tombol ini fitur itu hilang. */}
            <div className="flex flex-wrap items-center gap-2">
              {letter.filePath && (
                <a className="inline-flex h-9 items-center rounded-md border border-slate-200 px-3 text-sm font-medium text-emerald-700 hover:bg-slate-50"
                  href={letter.filePath} target="_blank" rel="noreferrer">
                  <FileText className="mr-1.5 h-4 w-4" />Lihat lampiran scan
                </a>
              )}
              {detail.canEdit && (
                <Button size="sm" variant="outline" onClick={openEdit}><Pencil className="mr-1.5 h-4 w-4" />Koreksi data</Button>
              )}
              {detail.canDelete && (
                <Button size="sm" variant="destructive" onClick={() => setDeleteOpen(true)}><Trash2 className="mr-1.5 h-4 w-4" />Hapus surat</Button>
              )}
              {/* Fase 1 (keputusan #1): hak rollback Kasubag Umum. Tombol muncul
                  hanya bila server mengizinkan (canReopen) pada tahap ini. */}
              {detail.canReopen && (
                <Button size="sm" variant="outline"
                  onClick={() => { setReopenReason(""); setReopenOpen(true); }}>
                  <Undo2 className="mr-1.5 h-4 w-4" />{detail.rejectLabel || detail.reopenLabel || "Tarik kembali"}
                </Button>
              )}
              {detail.canCorrectStage === false && (
                <p className="text-xs text-slate-400">
                  Data surat tidak dapat diubah/dihapus lagi karena sudah melewati tahap koreksi
                  {detail.correctableStages ? ` (${detail.correctableStages.join(", ")})` : ""}.
                </p>
              )}
              {detail.hasDispositions && (
                <p className="text-xs text-slate-400">
                  Surat ini sudah memiliki disposisi, jadi datanya tidak dapat diubah/dihapus lagi.
                </p>
              )}
            </div>
            {(message || error) && <p className={`rounded-md px-3 py-2 text-sm ${error ? "bg-rose-50 text-rose-800" : "bg-emerald-50 text-emerald-800"}`}>{error || message}</p>}
            {/* K7: peringatan non-blocking dari server (mis. arsip tanpa lembar 1). */}
            {warning && <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">{warning}</p>}
            <div><Label>Catatan (opsional, ikut tercatat di log)</Label><textarea className="mt-1 min-h-16 w-full rounded-md border border-slate-200 p-3 text-sm" value={notes} onChange={(e) => setNotes(e.target.value)} /></div>

            {persuratan && (
              <div className="grid gap-4 lg:grid-cols-2">
                <div className="rounded-md border border-slate-200 p-3">
                  <p className="text-sm font-medium">Verifikasi alamat tujuan</p>
                  <p className="mt-1 text-xs text-slate-500">Status: {V2_ADDRESS_STATUS_LABELS[letter.addressStatus] || letter.addressStatus}</p>
                  <div className="mt-2 flex gap-2">
                    <Button size="sm" disabled={letter.addressStatus === "ALAMAT_SESUAI"} onClick={() => verifyAddress(true)}>Alamat sesuai</Button>
                    <Button size="sm" variant="destructive" disabled={letter.addressStatus === "ALAMAT_TIDAK_SESUAI"} onClick={() => verifyAddress(false)}>Tidak sesuai</Button>
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

            <div className="space-y-3 rounded-md border border-slate-200 p-3">
              <p className="text-sm font-medium">Tindakan sesuai kewenangan role {user?.role}</p>

              {/* Revisi SOP/AS/04 langkah 12: rekomendasi Kasubag Umum. Panel
                  muncul hanya bila server mengizinkan (canGiveRekomendasi). */}
              {detail.canGiveRekomendasi && (
                <div className="rounded-md border border-sky-200 bg-sky-50 p-3">
                  <p className="text-sm font-medium text-sky-900">Rekomendasi rute — SOP/AS/04 langkah 12 (Kasubag)</p>
                  <p className="mt-1 text-xs text-sky-800">
                    Rekomendasi bukan keputusan final; keputusan final tetap dipegang Sekretaris/Panitera (langkah 13).
                    {detail.rekomendasiRoute && <> Rekomendasi tersimpan: <span className="font-medium">{V2_DISPOSITION_ROUTE_LABELS[detail.rekomendasiRoute] || detail.rekomendasiRoute}</span>.</>}
                  </p>
                  <div className="mt-2 flex flex-wrap gap-3">
                    {decisionRoutes.map((r) => (
                      <label key={r} className="flex items-center gap-1.5 text-sm">
                        <input type="radio" name="rekom-route" checked={rekomRoute === r} onChange={() => setRekomRoute(r)} />
                        {V2_DISPOSITION_ROUTE_LABELS[r] || r}
                      </label>
                    ))}
                  </div>
                  <textarea
                    className="mt-2 min-h-16 w-full rounded-md border border-slate-200 p-3 text-sm"
                    placeholder="Alasan rekomendasi (wajib, minimal 10 karakter)…"
                    value={rekomNotes}
                    onChange={(e) => setRekomNotes(e.target.value)}
                  />
                  <Button size="sm" className="mt-2" disabled={!rekomRoute || rekomNotes.trim().length < 10} onClick={submitRekomendasi}>
                    Kirim rekomendasi
                  </Button>
                </div>
              )}

              {decisionTransitions.length > 0 && (
                <div className="rounded-md border border-amber-200 bg-amber-50 p-3">
                  <p className="text-sm font-medium text-amber-900">Keputusan disposisi - SOP/AS/04 langkah 13</p>
                  <p className="mt-1 text-xs text-amber-800">
                    Tentukan apakah surat ini perlu kebijakan Ketua/Wakil Ketua atau langsung diteruskan ke unit pelaksana.
                    Pilihan ini tersimpan sebagai rute keputusan dan mengunci cabang tahap berikutnya.
                  </p>
                  <div className="mt-2 space-y-1">
                    {decisionRoutes.map((r) => (
                      <label key={r} className="flex items-start gap-2 text-sm text-amber-900">
                        <input
                          type="radio"
                          name="dispositionRoute"
                          className="mt-1"
                          value={r}
                          checked={decisionRoute === r}
                          onChange={() => setDecisionRoute(r)}
                        />
                        <span>
                          {V2_DISPOSITION_ROUTE_LABELS[r] || r}
                          <span className="block text-xs text-amber-700">tahap berikutnya: {V2_STAGE_LABELS[V2_DISPOSITION_ROUTE_TARGETS[r]] || V2_DISPOSITION_ROUTE_TARGETS[r]}</span>
                        </span>
                      </label>
                    ))}
                  </div>
                  <div className="mt-3 flex flex-wrap gap-2">
                    {decisionTransitions.map((t) => (
                      <Button key={t.toStage} size="sm" disabled={!decisionRoute} onClick={() => transition(t.toStage, decisionRoute)}>
                        {V2_STAGE_LABELS[t.toStage] || t.label}
                      </Button>
                    ))}
                  </div>
                  {!decisionRoute && <p className="mt-2 text-xs text-amber-800">Pilih salah satu rute di atas untuk mengaktifkan tombol.</p>}
                </div>
              )}

              {/* Fix 3: unit tujuan WAJIB sebelum surat masuk unit pelaksana.
                  Tombol "Teruskan ke pelaksana" baru aktif setelah unit dipilih
                  (atau unit sudah tersimpan dari keputusan/arahan sebelumnya). */}
              {transitions.some((t) => t.toStage === "DITERUSKAN_KE_PELAKSANA") && (
                <div className="rounded-md border border-amber-200 bg-amber-50 p-3">
                  <p className="text-sm font-medium text-amber-900">Unit pelaksana tujuan — wajib (SOP/AS/04 langkah 15-16)</p>
                  <select
                    className={selectClass + " mt-2"}
                    value={unitTujuan || detail.unitTujuan || ""}
                    onChange={(e) => setUnitTujuan(e.target.value)}
                  >
                    <option value="">Pilih unit Kasubag/Panmud…</option>
                    {(detail.unitTujuanList?.length ? detail.unitTujuanList : [...V2_UNIT_TUJUAN_LIST]).map((u) => (
                      <option key={u} value={u}>{V2_UNIT_TUJUAN_LABELS[u] || u}</option>
                    ))}
                  </select>
                  <p className="mt-1 text-xs text-amber-800">
                    Surat diteruskan ke UNIT, bukan pegawai perorangan; pegawai ditunjuk oleh kepala unit setelahnya (TUNJUK, langkah 17).
                  </p>
                </div>
              )}

              {/* Q1 locked (SOP/AS/04 langkah 14): arahan pimpinan bersifat final.
                  Transisi kembali ke Sekretaris/Panitera baru aktif setelah arahan
                  diisi (server menolak 422 ARAHAN_REQUIRED tanpa ini).
                  K5/P4 (revisi kedua): unit tujuan OPSIONAL — aturan sama dengan
                  versi WA (#KODE_UNIT); tanpa unit, Sekretaris memilih saat TERUSKAN. */}
              {letter.currentStage === "MENUNGGU_KEBIJAKAN_PIMPINAN" && (
                <div className="rounded-md border border-violet-200 bg-violet-50 p-3">
                  <p className="text-sm font-medium text-violet-900">Arahan pimpinan — SOP/AS/04 langkah 14 (final)</p>
                  <p className="mt-1 text-xs text-violet-800">
                    Isi arahan wajib, minimal 10 karakter. Setelah dikirim, surat kembali ke Sekretaris/Panitera untuk
                    dilaksanakan — tidak ada opsi &quot;kembali revisi setelah arahan&quot;.
                  </p>
                  <textarea
                    className="mt-2 min-h-20 w-full rounded-md border border-slate-200 p-3 text-sm"
                    placeholder="Inti arahan/keputusan pimpinan…"
                    value={arahan}
                    onChange={(e) => setArahan(e.target.value)}
                  />
                  <select
                    className={selectClass + " mt-2"}
                    value={arahanUnit || ""}
                    onChange={(e) => setArahanUnit(e.target.value)}
                  >
                    <option value="">Unit tujuan: pilih bila sudah jelas (opsional)…</option>
                    {(detail.unitTujuanList?.length ? detail.unitTujuanList : [...V2_UNIT_TUJUAN_LIST]).map((u) => (
                      <option key={u} value={u}>{V2_UNIT_TUJUAN_LABELS[u] || u}</option>
                    ))}
                  </select>
                  <p className="mt-1 text-xs text-violet-800">
                    Tanpa unit, arahan tetap tersimpan; Sekretaris memilih unit saat meneruskan (TERUSKAN).
                  </p>
                </div>
              )}

              {/* K7 (DEFAULT SEMENTARA - menunggu review pimpinan): serah-terima
                  LEMBAR 1 disposisi (SOP/AS/04 langkah 17) — dicatat (penyerah,
                  penerima, waktu), TIDAK wajib sebelum ARSIP kecuali toggle
                  "wajib" di Pengaturan dinyalakan. Arsiparis mengarsipkan tanpa
                  lembar 1 -> server mengirim peringatan (banner kuning + log). */}
              {(detail.lembar1?.diserahkanOleh || canRecordLembar1) && (
                <div className="rounded-md border border-teal-200 bg-teal-50 p-3">
                  <p className="text-sm font-medium text-teal-900">Serah-terima lembar 1 disposisi — SOP/AS/04 langkah 17</p>
                  {detail.lembar1?.diserahkanOleh ? (
                    <p className="mt-1 text-xs text-teal-800">
                      Tercatat: <span className="font-medium">{detail.lembar1.diserahkanOlehName || detail.lembar1.diserahkanOleh}</span>
                      {" → "}
                      <span className="font-medium">{detail.lembar1.diterimaOlehName || detail.lembar1.diterimaOleh}</span>
                      {detail.lembar1.diserahkanAt ? ` · ${String(detail.lembar1.diserahkanAt).slice(0, 16).replace("T", " ")}` : ""}
                      {" "}(sudah tercatat permanen, tidak bisa diubah).
                    </p>
                  ) : (
                    <>
                      <p className="mt-1 text-xs text-teal-800">
                        Belum tercatat. Mencatatnya tidak wajib sebelum ARSIP
                        {detail.lembar1Wajib ? " (toggle wajib AKTIF di Pengaturan — arsip akan ditolak tanpa ini)" : "; tanpa ini, arsip hanya menampilkan peringatan"}.
                      </p>
                      <div className="mt-2 grid gap-2 sm:grid-cols-2">
                        <select className={selectClass} value={lembarPenyerah} onChange={(e) => setLembarPenyerah(e.target.value)}>
                          <option value="">Penyerah lembar 1 (umumnya pelaksana)…</option>
                          {pegawaiList.map((p) => (
                            <option key={p.id} value={p.id}>{p.name} ({V2_ROLE_LABELS[p.role] || p.role})</option>
                          ))}
                        </select>
                        <select className={selectClass} value={lembarPenerima} onChange={(e) => setLembarPenerima(e.target.value)}>
                          <option value="">Penerima lembar 1 (umumnya Arsiparis)…</option>
                          {pegawaiList.map((p) => (
                            <option key={p.id} value={p.id}>{p.name} ({V2_ROLE_LABELS[p.role] || p.role})</option>
                          ))}
                        </select>
                      </div>
                      <Button size="sm" className="mt-2" disabled={!lembarPenerima} onClick={submitLembar1}>
                        Catat serah-terima
                      </Button>
                    </>
                  )}
                </div>
              )}

              {/* Fix 4: TUNJUK pegawai hanya oleh kepala unit, hanya di tahap
                  DITERUSKAN_KE_PELAKSANA (server yang menghitung isUnitHead). */}
              {canTunjuk && (
                <div className="rounded-md border border-emerald-200 bg-emerald-50 p-3">
                  <p className="text-sm font-medium text-emerald-900">Tunjuk pelaksana — SOP/AS/04 langkah 17 (kepala unit)</p>
                  <select className={selectClass + " mt-2"} value={pegawaiId} onChange={(e) => setPegawaiId(e.target.value)}>
                    <option value="">Pilih pegawai…</option>
                    {pegawaiList.map((p) => (
                      <option key={p.id} value={p.id}>{p.name} ({V2_ROLE_LABELS[p.role] || p.role})</option>
                    ))}
                  </select>
                  <Button size="sm" className="mt-2" disabled={!pegawaiId} onClick={submitTunjuk}>Tunjuk pegawai</Button>
                </div>
              )}

              {plainTransitions.length > 0 && (
                <div className="flex flex-wrap gap-2">
                  {plainTransitions.map((t) => (
                    <Button
                      key={t.toStage}
                      size="sm"
                      disabled={
                        (t.toStage === "DITERUSKAN_KE_PELAKSANA" && !unitTujuan && !detail.unitTujuan)
                        || (letter.currentStage === "MENUNGGU_KEBIJAKAN_PIMPINAN"
                          && t.toStage === "DITERUSKAN_KE_SEKRETARIS_PANITERA"
                          && arahan.trim().length < 10)
                      }
                      onClick={() => transition(t.toStage)}
                    >
                      {V2_STAGE_LABELS[t.toStage] || t.label}
                    </Button>
                  ))}
                </div>
              )}

              {transitions.length === 0 && <p className="text-xs text-slate-500">Tidak ada transisi yang dapat dilakukan role ini pada tahap saat ini.</p>}
              <p className="text-xs text-slate-400">{detail.ratificationNotice}</p>
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

      {/* Dialog koreksi data surat: porting fitur Edit dari menu "Surat Masuk"
          lama, ditambah field v2 (keamanan, urgensi, sumber, jenis naskah, kode
          arsip) + penggantian lampiran scan. */}
      <Dialog open={editOpen} onOpenChange={setEditOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Koreksi data surat</DialogTitle>
            <DialogDescription>
              Perubahan tercatat di audit log. Hanya tersedia sebelum surat masuk proses disposisi.
            </DialogDescription>
          </DialogHeader>
          {editForm && (
            <form onSubmit={saveEdit} className="grid gap-3 sm:grid-cols-2">
              <div><Label>Nomor agenda *</Label><Input className="mt-1" required value={editForm.agendaNumber} onChange={(e) => setEditField("agendaNumber", e.target.value)} /></div>
              <div><Label>Nomor surat *</Label><Input className="mt-1" required value={editForm.letterNumber} onChange={(e) => setEditField("letterNumber", e.target.value)} /></div>
              <div><Label>Tanggal surat *</Label><Input type="date" className="mt-1" required value={editForm.letterDate} onChange={(e) => setEditField("letterDate", e.target.value)} /></div>
              <div><Label>Tanggal diterima *</Label><Input type="date" className="mt-1" required value={editForm.receivedDate} onChange={(e) => setEditField("receivedDate", e.target.value)} /></div>
              <div><Label>Pengirim *</Label><Input className="mt-1" required value={editForm.sender} onChange={(e) => setEditField("sender", e.target.value)} /></div>
              <div><Label>Jenis surat *</Label><select className={selectClass + " mt-1"} value={editForm.letterCategory} onChange={(e) => setEditField("letterCategory", e.target.value)}>{V2_LETTER_CATEGORIES.map((c) => <option key={c} value={c}>{c === "DINAS" ? "Dinas" : "Pribadi"}</option>)}</select></div>
              <div className="sm:col-span-2"><Label>Perihal *</Label><Input className="mt-1" required value={editForm.subject} onChange={(e) => setEditField("subject", e.target.value)} /></div>
              <div><Label>Level keamanan MA *</Label><select className={selectClass + " mt-1"} value={editForm.securityLevel} onChange={(e) => setEditField("securityLevel", e.target.value)}>{V2_SECURITY_LEVELS.map((v) => <option key={v.value} value={v.value}>{v.label}</option>)}</select></div>
              <div><Label>Tingkat urgensi *</Label><select className={selectClass + " mt-1"} value={editForm.urgencyLevel} onChange={(e) => setEditField("urgencyLevel", e.target.value)}>{V2_URGENCY_LEVELS.map((v) => <option key={v} value={v}>{v}</option>)}</select></div>
              <div><Label>Sumber penerimaan *</Label><select className={selectClass + " mt-1"} value={editForm.sourceChannel} onChange={(e) => setEditField("sourceChannel", e.target.value)}>{V2_SOURCE_CHANNELS.map((v) => <option key={v} value={v}>{v}</option>)}</select></div>
              <div><Label>Jenis naskah *</Label><select className={selectClass + " mt-1"} value={editForm.documentType} onChange={(e) => setEditField("documentType", e.target.value)}>{V2_DOCUMENT_TYPES.map((v) => <option key={v} value={v}>{v}</option>)}</select></div>
              <div className="sm:col-span-2">
                <Label>Kode klasifikasi arsip</Label>
                <Input className="mt-1" placeholder="contoh: HK1.1.2" value={editForm.archiveCode} onChange={(e) => setEditField("archiveCode", e.target.value)} />
                <p className="mt-1 text-xs text-slate-500">Status kode (OFFICIAL / menunggu validasi arsiparis) dihitung ulang otomatis oleh server.</p>
              </div>
              <div className="sm:col-span-2"><Label>Catatan</Label><textarea className="mt-1 min-h-20 w-full rounded-md border border-slate-200 p-3 text-sm" value={editForm.description} onChange={(e) => setEditField("description", e.target.value)} /></div>
              <div className="sm:col-span-2 space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Ganti lampiran scan (opsional)</Label>
                <Input type="file" className="h-9 rounded-md text-sm" accept={V2_ATTACHMENT_ACCEPT} onChange={(e) => setEditFile(e.target.files?.[0] || null)} />
                <p className="text-xs text-slate-500">{V2_ATTACHMENT_HINT}.</p>
                {editForm.filePath && !editFile && <p className="text-xs text-slate-400">Lampiran saat ini: {editForm.filePath}</p>}
                {editFile && <p className="text-xs text-emerald-700">Lampiran pengganti: {editFile.name}</p>}
              </div>
              {error && <p className="sm:col-span-2 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">{error}</p>}
              <DialogFooter className="sm:col-span-2 gap-2">
                <Button type="button" variant="outline" onClick={() => setEditOpen(false)}>Batal</Button>
                <Button type="submit" disabled={busy}>{busy && <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />}Simpan perubahan</Button>
              </DialogFooter>
            </form>
          )}
        </DialogContent>
      </Dialog>

      {/* Konfirmasi hapus. Server hanya mengizinkan ADMIN dan hanya sebelum
          keputusan disposisi (V2Workflow::CORRECTABLE_STAGES). */}
      <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Hapus surat ini?</DialogTitle>
            <DialogDescription>
              {detail?.letter?.agendaNumber} · {detail?.letter?.letterNumber} · {detail?.letter?.subject}
            </DialogDescription>
          </DialogHeader>
          <p className="text-sm text-slate-600">
            Riwayat Buku Kendali dan riwayat pemeriksaan kelengkapan surat ini ikut terhapus.
            Aktivitas penghapusan tetap tercatat di Audit Log. Tindakan ini tidak dapat dibatalkan.
          </p>
          {error && <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">{error}</p>}
          <DialogFooter className="gap-2">
            <Button variant="outline" onClick={() => setDeleteOpen(false)}>Batal</Button>
            <Button variant="destructive" disabled={busy} onClick={confirmDelete}>
              {busy && <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />}Hapus surat
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Dialog TOLAK/RECALL/koreksi (K3, DEFAULT SEMENTARA - menunggu review
           pimpinan). Gerak mundur tahap WAJIB beralasan supaya bisa diaudit:
           pelaku, alasan, waktu, tahap asal -> tahap tujuan tercatat permanen
           (action STAGE_TOLAK / STAGE_RECALL / STAGE_REOPEN). */}
      <Dialog open={reopenOpen} onOpenChange={setReopenOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {detail?.rejectMode === "TOLAK" ? "Tolak & kembalikan satu langkah?"
                : detail?.rejectMode === "RECALL" ? "Tarik kembali kiriman Anda?"
                : "Koreksi (khusus ADMIN)?"}
            </DialogTitle>
            <DialogDescription>
              {detail?.letter?.agendaNumber} · {detail?.letter?.subject}
            </DialogDescription>
          </DialogHeader>
          <p className="text-sm text-slate-600">
            {detail?.rejectMode === "TOLAK"
              ? <>Surat dikembalikan SATU langkah ke pengirim sebelumnya (<span className="font-medium">{detail?.rejectTargetLabel}</span>) beserta alasan penolakan Anda. Pemegang baru akan menerima notifikasi.</>
              : detail?.rejectMode === "RECALL"
                ? <>Surat ditarik kembali oleh pengirimnya ke <span className="font-medium">{detail?.rejectTargetLabel}</span> — hanya bisa dilakukan karena penerima belum bertindak. Setelah penerima bertindak, recall ditolak.</>
                : <>Koreksi kasus khusus oleh ADMIN: surat dikembalikan ke <span className="font-medium">{detail?.rejectTargetLabel}</span> dan tindakan ini tercatat di log.</>}
          </p>
          <div>
            <Label>Alasan (wajib) *</Label>
            <textarea
              className="mt-1 min-h-20 w-full rounded-md border border-slate-200 p-3 text-sm"
              value={reopenReason}
              onChange={(e) => setReopenReason(e.target.value)}
              placeholder="Contoh: salah unit pelaksana, seharusnya Subbag Kepegawaian."
            />
            <p className={`mt-1 text-xs ${reopenReason.trim().length < (detail?.reopenReasonMin || V2_REOPEN_REASON_MIN) ? "text-rose-700" : "text-slate-500"}`}>
              Minimal {detail?.reopenReasonMin || V2_REOPEN_REASON_MIN} karakter — alasan tercatat permanen di riwayat Buku Kendali.
            </p>
          </div>
          {error && <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">{error}</p>}
          <DialogFooter className="gap-2">
            <Button variant="outline" onClick={() => setReopenOpen(false)}>Batal</Button>
            <Button variant="destructive" disabled={reopenReason.trim().length < (detail?.reopenReasonMin || V2_REOPEN_REASON_MIN)} onClick={submitReopen}>
              {detail?.rejectMode === "TOLAK" ? "Tolak & kembalikan" : "Tarik kembali"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
