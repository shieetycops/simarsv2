import { useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import { Link, useParams, useSearchParams } from "react-router-dom";
import { format } from "date-fns";
import { id as localeId } from "date-fns/locale";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { useSettings } from "@/src/lib/useSettings";
import {
 CalendarClock,
 ExternalLink,
 Eye,
 FileText,
 Inbox,
 Loader2,
 Paperclip,
 Send,
 Users,
} from "lucide-react";

interface DispositionView {
 id: string;
 instruction: string | null;
 status: string | null;
 deadline: string | null;
 notes: string | null;
 fromName: string | null;
 toName: string | null;
}

interface LetterViewData {
 id: string;
 agendaNumber: string | null;
 letterNumber: string | null;
 letterDate: string | null;
 receivedDate: string | null;
 sender: string | null;
 subject: string | null;
 classification: string | null;
 nature: string | null;
 description: string | null;
 filePath: string | null;
 status: string | null;
 dispositions: DispositionView[];
}

const formatDate = (date: string | null): string => {
 if (!date) return "-";
 try {
 return format(new Date(date), "dd MMMM yyyy", { locale: localeId });
 } catch {
 return date;
 }
};

const natureBadgeClass = (nature: string | null | undefined): string => {
 switch ((nature || "").toUpperCase()) {
 case "BIASA": return "bg-blue-50 text-blue-700 border-blue-200";
 case "PENTING": return "bg-emerald-50 text-emerald-700 border-emerald-200";
 case "SEGERA": return "bg-orange-50 text-orange-700 border-orange-200";
 case "RAHASIA": return "bg-purple-50 text-purple-700 border-purple-200";
 default: return "bg-slate-50 text-slate-600 border-slate-200";
 }
};

const statusBadge = (status: string | null | undefined) => {
 const s = (status || "PENDING").toUpperCase();
 const cls = s === "SELESAI" ? "bg-emerald-100 text-emerald-700 border-emerald-200"
 : s === "DIPROSES" || s === "DALAM_PROSES" ? "bg-blue-100 text-blue-700 border-blue-200"
 : "bg-amber-100 text-amber-700 border-amber-200";
 const label = s === "SELESAI" ? "Selesai" : s === "DIPROSES" || s === "DALAM_PROSES" ? "Diproses" : "Menunggu";
 return <Badge variant="outline" className={`font-medium ${cls}`}>{label}</Badge>;
};

const InfoRow = ({ label, value }: { label: string; value: ReactNode }) => (
 <div className="grid grid-cols-1 gap-0.5 sm:grid-cols-[10rem_1fr] sm:gap-3 py-1.5 border-b border-slate-100 last:border-0">
 <span className="text-xs font-medium text-slate-400 uppercase tracking-wide">{label}</span>
 <span className="text-sm text-slate-800">{value ?? "-"}</span>
 </div>
);

export default function LetterView() {
 const { id } = useParams<{ id: string }>();
  // Token HMAC dari tautan WA (?t=...). Tautan tanpa token tetap bisa dibuka
  // oleh pengguna yang sudah login, tapi tidak oleh tamu.
  const [searchParams] = useSearchParams();
  const linkToken = searchParams.get("t");

 const { settings } = useSettings();
 const [letter, setLetter] = useState<LetterViewData | null>(null);
 const [loading, setLoading] = useState(true);
 const [error, setError] = useState<string | null>(null);

 useEffect(() => {
 if (!id) {
 setError("ID surat tidak ada pada tautan.");
 setLoading(false);
 return;
 }
 let active = true;
 setLoading(true);
 setError(null);
 fetch(`/api/incoming/${encodeURIComponent(id)}${linkToken ? `?t=${encodeURIComponent(linkToken)}` : ""}`)
 .then(async (res) => {
 if (!res.ok) {
 const data = await res.json().catch(() => null);
 throw new Error(
 res.status === 404
 ? "Surat tidak ditemukan, atau tautan sudah tidak berlaku."
 : (data?.message || "Gagal memuat data surat.")
 );
 }
 return res.json();
 })
 .then((data) => {
 if (active) setLetter(data as LetterViewData);
 })
 .catch((err: Error) => {
 if (active) setError(err.message || "Terjadi kesalahan saat memuat surat.");
 })
 .finally(() => {
 if (active) setLoading(false);
 });
 return () => {
 active = false;
 };
 }, [id, linkToken]);

 const overall = useMemo(() => {
 const dispo = letter?.dispositions || [];
 if (!letter || dispo.length === 0) {
 return (letter?.status || "").toUpperCase() === "SELESAI" ? "SELESAI" : "BELUM_DISPOSISI";
 }
 const open = dispo.some((d) => (d.status || "PENDING").toUpperCase() !== "SELESAI");
 return open ? "DALAM_PROSES" : "SELESAI";
 }, [letter]);

 return (
 <div className="min-h-screen bg-slate-50">
 <header className="border-b border-slate-200 bg-white">
 <div className="mx-auto flex max-w-2xl items-center gap-3 px-4 py-3">
 {settings.logoUrl ? (
 <img src={settings.logoUrl} alt="Logo" className="h-10 w-10 rounded object-contain" />
 ) : (
 <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-600">
 <FileText className="h-5 w-5 text-white" />
 </div>
 )}
 <div className="min-w-0">
 <div className="truncate text-sm font-semibold text-slate-800">
 {settings.shortName || "SIMARS"}
 </div>
 <div className="flex items-center gap-1 text-xs text-slate-400">
 <Eye className="h-3 w-3" /> Halaman lihat surat (view-only)
 </div>
 </div>
 </div>
 </header>

 <main className="mx-auto max-w-2xl space-y-4 px-4 py-6">
 {loading && (
 <div className="flex flex-col items-center justify-center gap-3 py-24 text-slate-500">
 <Loader2 className="h-8 w-8 animate-spin text-emerald-600" />
 <span className="text-sm">Memuat data surat&hellip;</span>
 </div>
 )}

 {!loading && error && (
 <Card className="border-slate-200">
 <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
 <div className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100">
 <Inbox className="h-6 w-6 text-slate-400" />
 </div>
 <div className="text-base font-semibold text-slate-700">Surat tidak dapat ditampilkan</div>
 <p className="max-w-sm text-sm text-slate-500">{error}</p>
 <Link to="/">
 <Button variant="outline" size="sm" className="mt-1">
 Buka halaman utama SIMARS
 </Button>
 </Link>
 </CardContent>
 </Card>
 )}

 {!loading && !error && letter && (
 <>
 <Card className="border-slate-200">
 <CardHeader className="pb-2">
 <div className="flex flex-wrap items-start justify-between gap-2">
 <CardTitle className="flex min-w-0 items-center gap-2 text-base leading-snug text-slate-800">
 <FileText className="h-4 w-4 shrink-0 text-emerald-600" />
 <span className="min-w-0">{letter.subject || "Tanpa Nomor"}</span>
 </CardTitle>
 {overall === "SELESAI"
 ? statusBadge("SELESAI")
 : overall === "DALAM_PROSES"
 ? statusBadge("DIPROSES")
 : (
 <Badge variant="outline" className="bg-amber-100 font-medium text-amber-700 border-amber-200">
 Belum Didisposisi
 </Badge>
 )}
 </div>
 </CardHeader>
 <CardContent className="pt-3">
 <InfoRow label="Nomor Agenda" value={letter.agendaNumber || "-"} />
 <InfoRow label="Nomor Surat" value={letter.letterNumber || "-"} />
 <InfoRow label="Tanggal Surat" value={formatDate(letter.letterDate)} />
 <InfoRow label="Tanggal Diterima" value={formatDate(letter.receivedDate)} />
 <InfoRow label="Pengirim" value={letter.sender || "-"} />
 <InfoRow
 label="Sifat"
 value={
 letter.nature ? (
 <Badge variant="outline" className={`font-medium ${natureBadgeClass(letter.nature)}`}>
 {letter.nature}
 </Badge>
 ) : (
 "-"
 )
 }
 />
 <InfoRow label="Klasifikasi" value={letter.classification || "-"} />
 {letter.description && (
 <InfoRow
 label="Deskripsi"
 value={<span className="whitespace-pre-line leading-relaxed">{letter.description}</span>}
 />
 )}
 </CardContent>
 </Card>

 <Card className="border-slate-200">
 <CardHeader className="pb-2">
 <CardTitle className="flex items-center gap-2 text-base text-slate-800">
 <Paperclip className="h-4 w-4 text-emerald-600" /> Lampiran
 </CardTitle>
 </CardHeader>
 <CardContent>
 {letter.filePath ? (
 <div className="flex flex-wrap items-center justify-between gap-2">
 <a
 href={letter.filePath}
 target="_blank"
 rel="noopener noreferrer"
 className="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-700 hover:underline"
 >
 <ExternalLink className="h-3.5 w-3.5 shrink-0" /> Buka dokumen lampiran
 </a>
 <span className="text-xs text-slate-400">Terbuka di tab baru.</span>
 </div>
 ) : (
 <p className="text-sm italic text-slate-400">Tidak ada lampiran file untuk surat ini.</p>
 )}
 </CardContent>
 </Card>

 <Card className="border-slate-200">
 <CardHeader className="pb-2">
 <CardTitle className="flex items-center gap-2 text-base text-slate-800">
 <Users className="h-4 w-4 text-emerald-600" /> Disposisi
 {letter.dispositions && letter.dispositions.length > 0 && (
 <span className="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-emerald-100 px-1.5 text-xs font-semibold text-emerald-700">
 {letter.dispositions.length}
 </span>
 )}
 </CardTitle>
 </CardHeader>
 <CardContent className="space-y-3">
 {!letter.dispositions || letter.dispositions.length === 0 ? (
 <p className="text-sm italic text-slate-400">
 Surat ini belum didisposisi. Notifikasi WhatsApp akan terkirim otomatis setelah disposisi dibuat.
 </p>
 ) : (
 letter.dispositions.map((d) => (
 <div key={d.id} className="rounded-lg border border-slate-200 bg-white p-3.5">
 <div className="flex flex-wrap items-center justify-between gap-2">
 <div className="flex min-w-0 items-center gap-1.5 text-sm font-medium text-slate-700">
 <Send className="h-3.5 w-3.5 shrink-0 text-slate-400" />
 {d.toName || "Penerima disposisi"}
 </div>
 {statusBadge(d.status)}
 </div>
 {d.instruction && (
 <p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate-600">
 &ldquo;{d.instruction}&rdquo;
 </p>
 )}
 <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
 {d.fromName && <span>Dari: {d.fromName}</span>}
 {d.deadline && (
 <span className="inline-flex items-center gap-1">
 <CalendarClock className="h-3 w-3" /> Tenggat: {formatDate(d.deadline)}
 </span>
 )}
 </div>
 {d.notes && (
 <p className="mt-1.5 border-t border-slate-100 pt-1.5 text-xs italic text-slate-500">
 Catatan: {d.notes}
 </p>
 )}
 </div>
 ))
 )}
 </CardContent>
 </Card>

 <p className="pt-1 text-center text-xs text-slate-400">
 Ditampilkan secara view-only melalui tautan resmi SIMARS. Login diperlukan untuk menindaklanjuti surat.
 </p>
 </>
 )}
 </main>
 </div>
 );
}
