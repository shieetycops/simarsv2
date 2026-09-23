import { useEffect, useMemo, useState } from "react";
import { useAuth } from "@/src/lib/AuthContext";
import { toast } from "sonner";
import { format } from "date-fns";
import { id as localeId } from "date-fns/locale";
import { Hash, Loader2, Copy, Check, RefreshCw, Info, History } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { ISSUING_UNIT_OPTIONS } from "@/src/lib/letterOptions";
import { parseLetterNumber, usedSequences } from "@/src/lib/letterNumber";
import type { Slot } from "@/src/lib/letterNumber";

// Halaman "Ambil Nomor" — reservasi nomor surat keluar untuk SEMUA user login.
// Nomor dihitung server-side dari database terkini (termasuk data import terbaru)
// dan dikunci di tabel outgoing_number_slots supaya tidak tubrukan.
export default function LetterNumberPickup() {
  const { token, user } = useAuth();

  const [unit, setUnit] = useState("PPK");
  const [date, setDate] = useState(() => format(new Date(), "yyyy-MM-dd"));
  const [kode, setKode] = useState("");
  const [isSisipan, setIsSisipan] = useState(false);
  const [afterSeq, setAfterSeq] = useState("");

  const [letters, setLetters] = useState<Array<Record<string, any>>>([]);
  const [slots, setSlots] = useState<Slot[]>([]);
  const [preview, setPreview] = useState<Record<string, any> | null>(null);
  const [result, setResult] = useState<Slot | null>(null);
  const [copied, setCopied] = useState(false);
  const [loading, setLoading] = useState(true);
  const [previewing, setPreviewing] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [updatingId, setUpdatingId] = useState<string | null>(null);
  // Dinaikkan setiap reservasi/batal/terbit berhasil supaya saran ikut dihitung ulang.
  const [refreshKey, setRefreshKey] = useState(0);

  const year = parseInt((date || "").slice(0, 4) || String(new Date().getFullYear()), 10);
  const isManager = user?.role === "ADMIN" || user?.role === "SEKRETARIS";

  const load = async () => {
    setLoading(true);
    try {
      const [lettersRes, slotsRes] = await Promise.all([
        fetch("/api/outgoing", { headers: { Authorization: `Bearer ${token}` } }),
        fetch(`/api/outgoing/slots?unit=${unit}&year=${year}`, { headers: { Authorization: `Bearer ${token}` } }),
      ]);
      if (lettersRes.ok) setLetters(await lettersRes.json());
      if (slotsRes.ok) setSlots(await slotsRes.json());
    } catch {
      toast.error("Gagal memuat data nomor surat.");
    } finally {
      setLoading(false);
    }
  };

  // Reload data saat unit / tahun berubah.
  useEffect(() => {
    if (!token) return;
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [unit, year, token]);

  // Pratinjau nomor saran (debounce) — read-only endpoint /outgoing/next-letter.
  useEffect(() => {
    if (!token) return;
    setPreviewing(true);
    const t = setTimeout(async () => {
      try {
        const params = new URLSearchParams({ unit, date });
        if (kode.trim()) params.set("kode", kode.trim());
        if (isSisipan && afterSeq) params.set("after", afterSeq);
        const res = await fetch(`/api/outgoing/next-letter?${params}`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        if (!res.ok) return;
        setPreview(await res.json());
      } catch {
        // pratinjau non-blokir
      } finally {
        setPreviewing(false);
      }
    }, 300);
    return () => clearTimeout(t);
  }, [unit, date, kode, isSisipan, afterSeq, token, refreshKey]);

  // Nomor dasar terpakai (surat + slot DIPESAN) untuk dropdown sisipan.
  const availableNumbers = useMemo(() => {
    const set = new Set<number>(usedSequences(letters, unit, year));
    for (const s of slots) {
      if (s.issuingUnit !== unit || s.status !== "DIPESAN") continue;
      const p = parseLetterNumber(s.letterNumber);
      if (p) set.add(p.sequence);
    }
    return [...set].sort((a, b) => a - b);
  }, [letters, slots, unit, year]);

  const filteredSlots = slots.filter((s) => s.issuingUnit === unit);
  const reservedCount = filteredSlots.filter((s) => s.status === "DIPESAN").length;
  const lastLetterSeq = letters
    .filter(
      (l) =>
        l.issuingUnit === unit &&
        parseInt(String(l.letterDate || "").slice(0, 4), 10) === year
    )
    .map((l) => parseLetterNumber(l.letterNumber || "")?.sequence || 0)
    .reduce((max, s) => Math.max(max, s), 0);

  const handleTake = async () => {
    setSubmitting(true);
    try {
      const res = await fetch("/api/outgoing/slots", {
        method: "POST",
        headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
        body: JSON.stringify({
          unit,
          date,
          kode: kode.trim() || null,
          after: isSisipan && afterSeq ? parseInt(afterSeq, 10) : null,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || "Gagal mengambil nomor.");
      setResult(data);
      toast.success(`Nomor ${data.letterNumber} berhasil dipesan.`);
      await load();
      setRefreshKey((k) => k + 1);
    } catch (err: any) {
      toast.error(err.message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleStatus = async (slot: Slot, status: "BATAL" | "TERBIT") => {
    setUpdatingId(slot.id);
    try {
      const res = await fetch(`/api/outgoing/slots/${slot.id}`, {
        method: "PUT",
        headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
        body: JSON.stringify({ status }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || "Gagal mengubah status slot.");
      toast.success(
        status === "BATAL"
          ? `Slot ${slot.letterNumber} dibatalkan dan nomornya bebas dipakai lagi.`
          : `Slot ${slot.letterNumber} ditandai terbit.`
      );
      await load();
      setRefreshKey((k) => k + 1);
    } catch (err: any) {
      toast.error(err.message);
    } finally {
      setUpdatingId(null);
    }
  };

  const handleCopy = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
      toast.success("Nomor disalin ke clipboard.");
    } catch {
      toast.error("Gagal menyalin nomor.");
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-slate-100">Ambil Nomor</h1>
        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
          Kunci nomor surat keluar sebelum suratnya diregistrasi, supaya tidak ada dua surat bernomor sama.
        </p>
      </div>

      {/* Statistik ringkas */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <Card>
          <CardContent className="p-4">
            <p className="text-xs text-slate-500">Nomor terakhir terpakai · {year}</p>
            <p className="text-2xl font-semibold mt-1">
              {lastLetterSeq > 0 ? lastLetterSeq : "—"}
              {reservedCount > 0 && (
                <span className="text-sm font-normal text-slate-400 ml-2">+ {reservedCount} dipesan</span>
              )}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-4">
            <p className="text-xs text-slate-500">Reservasi aktif ({unit})</p>
            <p className="text-2xl font-semibold mt-1">{reservedCount}</p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-4">
            <p className="text-xs text-slate-500">Unit penerbit</p>
            <p className="text-2xl font-semibold mt-1">{unit}</p>
          </CardContent>
        </Card>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Form reservasi */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base flex items-center gap-2">
              <Hash className="w-4 h-4 text-slate-400" /> Buat Reservasi Nomor
            </CardTitle>
            <CardDescription>
              Nomor dihitung dari database terkini (termasuk data import terbaru).
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">Unit Penerbit</Label>
              <Select value={unit} onValueChange={setUnit}>
                <SelectTrigger className="h-9 rounded-md">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {ISSUING_UNIT_OPTIONS.map((opt) => (
                    <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">Tanggal Surat</Label>
              <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="h-9 rounded-md" />
            </div>
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">
                Kode Surat <span className="text-slate-400 font-normal">(opsional)</span>
              </Label>
              <Input
                value={kode}
                onChange={(e) => setKode(e.target.value)}
                placeholder="cth. SPD.PPK.PA.PSW"
                className="h-9 rounded-md"
              />
              <p className="text-xs text-slate-400">Bagian tengah nomor surat. Kosongkan jika tanpa kode.</p>
            </div>
            <div className="space-y-1.5">
              <label className="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                <input
                  type="checkbox"
                  checked={isSisipan}
                  onChange={(e) => setIsSisipan(e.target.checked)}
                  className="h-4 w-4 rounded border-slate-300"
                />
                Surat Sisipan
              </label>
              {isSisipan && (
                <Select value={afterSeq} onValueChange={setAfterSeq}>
                  <SelectTrigger className="h-9 rounded-md">
                    <SelectValue placeholder="Pilih nomor yang disisipi..." />
                  </SelectTrigger>
                  <SelectContent>
                    {availableNumbers.length === 0 ? (
                      <div className="px-2 py-1.5 text-xs text-slate-400">Belum ada nomor yang bisa disisipi.</div>
                    ) : (
                      availableNumbers.map((n) => (
                        <SelectItem key={n} value={String(n)}>Setelah nomor {n}</SelectItem>
                      ))
                    )}
                  </SelectContent>
                </Select>
              )}
              {isSisipan && preview && (
                <p className="text-xs text-slate-500">
                  Sisipan setelah nomor {afterSeq || "…"} → <span className="font-mono">{preview.letterNumber}</span>
                </p>
              )}
            </div>
            <div className="bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-lg p-3 space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs text-slate-500">Nomor berikutnya (saran)</span>
                {previewing ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin text-slate-400" />
                ) : (
                  <Hash className="w-3.5 h-3.5 text-slate-400" />
                )}
              </div>
              <p className="font-mono text-base font-medium text-slate-800 dark:text-slate-100 break-words">
                {preview?.letterNumber || "-"}
              </p>
            </div>
            <Button
              onClick={handleTake}
              disabled={submitting || !preview?.letterNumber}
              className="w-full h-9 rounded-md"
            >
              {submitting ? (
                <Loader2 className="w-4 h-4 animate-spin mr-2" />
              ) : (
                <Hash className="w-4 h-4 mr-2" />
              )}
              Ambil Nomor
            </Button>
            <p className="text-xs text-slate-400 text-center leading-relaxed">
              Nomor otomatis tercatat sebagai <b>Dipesan</b> dan terkunci untuk user lain sampai suratnya
              diregistrasi di menu Surat Keluar.
            </p>
          </CardContent>
        </Card>

        {/* Hasil + riwayat */}
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle className="text-base flex items-center gap-2">
              <History className="w-4 h-4 text-slate-400" /> Nomor yang Sudah Dipesan
            </CardTitle>
            <CardDescription>
              Nomor aktif {unit} tahun {year}. Nomor <b>Dipesan</b> tidak akan diberikan ke user lain.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {result && (
              <div className="border border-green-200 bg-green-50 dark:bg-green-900/20 dark:border-green-800 rounded-lg p-4">
                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                  <div className="font-mono text-xl sm:text-2xl font-semibold text-green-700 dark:text-green-400 break-all">
                    {result.letterNumber}
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-8 rounded-md"
                    onClick={() => handleCopy(result.letterNumber)}
                  >
                    {copied ? <Check className="w-4 h-4 mr-1.5" /> : <Copy className="w-4 h-4 mr-1.5" />}
                    {copied ? "Tersalin" : "Salin"}
                  </Button>
                </div>
                <div className="mt-3 flex flex-wrap gap-2 text-xs">
                  <Badge variant="outline" className="rounded">{result.issuingUnit}</Badge>
                  <Badge variant="outline" className="rounded">
                    {format(new Date(result.letterDate + "T00:00:00"), "dd MMM yyyy", { locale: localeId })}
                  </Badge>
                  {result.suffix && (
                    <Badge variant="outline" className="rounded">
                      Sisipan ({result.sequence}.{result.suffix})
                    </Badge>
                  )}
                  <Badge className="rounded">Dipesan</Badge>
                </div>
              </div>
            )}

            <div className="flex items-center justify-between">
              <h3 className="text-sm font-medium text-slate-700 dark:text-slate-200">
                Riwayat Reservasi · {unit} · {year}
              </h3>
              <Button variant="ghost" size="sm" className="h-8 rounded-md" onClick={load}>
                <RefreshCw className="w-3.5 h-3.5 mr-1.5" /> Muat Ulang
              </Button>
            </div>

            {loading ? (
              <div className="flex justify-center py-10">
                <Loader2 className="h-5 w-5 animate-spin text-slate-400" />
              </div>
            ) : filteredSlots.length === 0 ? (
              <div className="text-sm text-slate-400 border border-dashed border-slate-200 dark:border-slate-700 rounded-lg p-6 text-center">
                Belum ada reservasi nomor untuk {unit} tahun {year}.
              </div>
            ) : (
              <div className="overflow-x-auto border border-slate-200 dark:border-slate-700 rounded-lg">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="bg-slate-50 dark:bg-slate-800/60 text-left text-xs text-slate-500">
                      <th className="px-3 py-2 font-medium">Nomor Surat</th>
                      <th className="px-3 py-2 font-medium">Tanggal</th>
                      <th className="px-3 py-2 font-medium">Status</th>
                      <th className="px-3 py-2 font-medium text-right">Aksi</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {filteredSlots.map((s) => (
                      <tr key={s.id}>
                        <td className="px-3 py-2.5 font-mono text-xs break-all">{s.letterNumber}</td>
                        <td className="px-3 py-2.5 whitespace-nowrap text-slate-500">
                          {format(new Date(s.letterDate + "T00:00:00"), "dd MMM yyyy", { locale: localeId })}
                        </td>
                        <td className="px-3 py-2.5">
                          <Badge
                            variant={s.status === "DIPESAN" ? "default" : s.status === "TERBIT" ? "secondary" : "outline"}
                            className="text-xs px-2 py-0.5 rounded"
                          >
                            {s.status}
                          </Badge>
                        </td>
                        <td className="px-3 py-2.5 text-right whitespace-nowrap">
                          {s.status === "DIPESAN" && (
                            <div className="inline-flex gap-1.5">
                              {isManager && (
                                <Button
                                  variant="outline"
                                  size="sm"
                                  className="h-7 text-xs rounded-md"
                                  disabled={updatingId === s.id}
                                  onClick={() => handleStatus(s, "TERBIT")}
                                >
                                  Tandai Terbit
                                </Button>
                              )}
                              <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 text-xs text-red-600 rounded-md hover:bg-red-50 dark:hover:bg-red-900/20"
                                disabled={updatingId === s.id}
                                onClick={() => handleStatus(s, "BATAL")}
                              >
                                Batalkan
                              </Button>
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <div className="flex items-start gap-2 text-xs text-slate-400 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-lg p-3">
              <Info className="w-4 h-4 shrink-0 mt-0.5" />
              <p>
                Membatalkan slot akan membebaskan nomornya untuk dipakai lagi. Admin/Sekretaris dapat menandai
                slot <b>Terbit</b> setelah suratnya diregistrasi di menu Surat Keluar.
              </p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}



