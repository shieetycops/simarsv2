import { useEffect, useState } from "react";
import type React from "react";
import { useNavigate, useLocation } from "react-router-dom";
import { useAuth } from "@/src/lib/AuthContext";
import { toast } from "sonner";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuLabel,
  DropdownMenuItem,
} from "@/components/ui/dropdown-menu";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Clock,
  CheckCircle2,
  AlertCircle,
  User,
  ArrowRight,
  MessageSquare,
  Calendar,
  History,
  MoreVertical,
  ExternalLink,
  ClipboardList,
  FileText,
  Forward,
  Loader2,
  Printer,
} from "lucide-react";
import { format } from "date-fns";
import { id } from "date-fns/locale";
import { cn } from "@/lib/utils";

const roleLabel = (role: string): string => {
  const map: Record<string, string> = {
    ADMIN: "Admin",
    PIMPINAN: "Pimpinan / Ketua",
    WAKIL_KETUA: "Wakil Ketua",
    HAKIM: "Hakim",
    PANITERA: "Panitera",
    SEKRETARIS: "Sekretaris",
    PANITERA_MUDA_PERMOHONAN: "Panitera Muda Permohonan",
    PANITERA_MUDA_GUGATAN: "Panitera Muda Gugatan",
    PANITERA_MUDA_HUKUM: "Panitera Muda Hukum",
    KEPALA_SUB_PTIP: "Kepala Sub Bagian PTIP",
    KEPALA_SUB_KEPEGAWAIAN: "Kepala Sub Bagian Kepegawaian",
    KEPALA_SUB_UMUM: "Kepala Sub Bagian Umum & Keuangan",
    STAFF: "Staff",
  };
  return map[role] ?? role;
};

export default function Dispositions() {
  const { token, user } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [dispositions, setDispositions] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCompleted, setShowCompleted] = useState(false);
  const [selectedLetter, setSelectedLetter] = useState<any | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [forwardOpen, setForwardOpen] = useState(false);
  const [forwardDisp, setForwardDisp] = useState<any>(null);
  const [forwardInstruction, setForwardInstruction] = useState("");
  const [forwardDeadline, setForwardDeadline] = useState("");
  const [forwardToUserId, setForwardToUserId] = useState("");
  const [forwardSubmitting, setForwardSubmitting] = useState(false);
  const [subordinates, setSubordinates] = useState<any[]>([]);
  const [subordinatesLoading, setSubordinatesLoading] = useState(false);

  // State for action form dialog
  const [actionOpen, setActionOpen] = useState(false);
  const [actionDisp, setActionDisp] = useState<any>(null);
  const [actionNextStatus, setActionNextStatus] = useState("");
  const [actionNotes, setActionNotes] = useState("");
  const [actionResult, setActionResult] = useState("");
  const [actionSubmitting, setActionSubmitting] = useState(false);

  useEffect(() => {
    fetchDispositions();
  }, []);

  const fetchDispositions = async () => {
    try {
      const res = await fetch("/api/dispositions", {
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) return;
      const data = await res.json();
      setDispositions(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const fetchSubordinates = async () => {
    setSubordinatesLoading(true);
    try {
      const res = await fetch("/api/users/subordinates", {
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) return;
      const data = await res.json();
      setSubordinates(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error(err);
    } finally {
      setSubordinatesLoading(false);
    }
  };

  const openForwardDialog = (disp: any) => {
    setForwardDisp(disp);
    setForwardInstruction("");
    setForwardDeadline("");
    setForwardToUserId("");
    fetchSubordinates();
    setForwardOpen(true);
  };

  const handleForwardSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!forwardDisp || !forwardToUserId) return;
    setForwardSubmitting(true);
    try {
      const res = await fetch("/api/dispositions", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          incomingLetterId: forwardDisp.incomingLetterId,
          fromUserId: user?.id,
          toUserId: forwardToUserId,
          instruction: forwardInstruction,
          deadline: forwardDeadline || undefined,
          status: "PENDING",
        }),
      });
      if (res.ok) {
        setForwardOpen(false);
        fetchDispositions();
        toast.success("Disposisi berhasil diteruskan");
      } else {
        const errData = await res.json().catch(() => ({}));
        toast.error(errData.message || "Gagal menyimpan data");
      }
    } catch (err) {
      console.error(err);
      toast.error("Gagal menyimpan data");
    } finally {
      setForwardSubmitting(false);
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case "SELESAI":
        return (
          <span className="text-xs px-2 py-0.5 rounded bg-green-50 text-green-700">
            SELESAI
          </span>
        );
      case "PROSES":
        return (
          <span className="text-xs px-2 py-0.5 rounded bg-blue-50 text-blue-700">
            DIPROSES
          </span>
        );
      default:
        return (
          <span className="text-xs px-2 py-0.5 rounded bg-amber-50 text-amber-700">
            PENDING
          </span>
        );
    }
  };
  const openActionDialog = (disp: any, currentStatus: string) => {
    let nextStatus = "PROSES";
    if (currentStatus === "PROSES") nextStatus = "SELESAI";
    if (currentStatus === "SELESAI") return;

    setActionDisp(disp);
    setActionNextStatus(nextStatus);
    setActionNotes("");
    setActionResult("");
    setActionOpen(true);
  };

  const handleActionSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!actionDisp || !actionNotes.trim()) return;
    setActionSubmitting(true);

    try {
      const res = await fetch(`/api/dispositions/${actionDisp.id}/status`, {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          status: actionNextStatus,
          notes: actionNotes.trim(),
          result: actionResult.trim() || undefined,
        }),
      });
      if (!res.ok) {
        toast.error("Gagal menyimpan data");
        return;
      }
      setActionOpen(false);
      fetchDispositions();
      toast.success(
        actionNextStatus === "PROSES"
          ? "Disposisi sedang ditindaklanjuti"
          : "Disposisi berhasil diselesaikan"
      );
    } catch (err) {
      console.error(err);
      toast.error("Gagal menyimpan data");
    } finally {
      setActionSubmitting(false);
    }
  };

  const openLetterDetail = (letter: any) => {
    setSelectedLetter(letter);
    setDetailOpen(true);
  };

  useEffect(() => {
    const letter = (location.state as { openLetterDetail?: any } | null)?.openLetterDetail;
    if (letter) {
      openLetterDetail(letter);
      window.history.replaceState({}, document.title);
    }
  }, []);


  const handlePrintDisposisi = (disp: any) => {
    const letter = disp.incomingLetter;
    const printWindow = window.open("", "_blank");
    if (!printWindow) return;
    const deadlineStr = disp.deadline
      ? new Date(disp.deadline).toLocaleDateString("id-ID", { day: "2-digit", month: "long", year: "numeric" })
      : "-";
    const letterDateStr = letter?.letterDate
      ? new Date(letter.letterDate).toLocaleDateString("id-ID", { day: "2-digit", month: "long", year: "numeric" })
      : "-";
    const statusLabel = disp.status === "SELESAI" ? "Selesai" : disp.status === "PROSES" ? "Diproses" : "Pending";

    printWindow.document.write(`<!DOCTYPE html>
<html><head><title>Lembar Disposisi</title>
<style>
  body { font-family: 'Times New Roman', serif; margin: 40px; font-size: 14px; color: #000; }
  .kop { text-align: center; border-bottom: 3px double #000; padding-bottom: 10px; margin-bottom: 20px; }
  .kop h2 { margin: 0; font-size: 16px; text-transform: uppercase; }
  .kop h3 { margin: 4px 0 0; font-size: 14px; }
  .title { text-align: center; font-size: 16px; font-weight: bold; margin: 20px 0; text-decoration: underline; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  table td, table th { border: 1px solid #000; padding: 8px 10px; vertical-align: top; }
  .label { width: 160px; font-weight: bold; }
  .signature { margin-top: 60px; display: flex; justify-content: space-between; }
  .signature div { text-align: center; width: 200px; }
  .signature .line { margin-top: 60px; border-top: 1px solid #000; }
</style></head><body>
<div class="kop">
  <h2>Pengadilan Agama Pasarwajo</h2>
  <h3>Kelas II</h3>
</div>
<div class="title">LEMBAR DISPOSISI</div>
<table>
  <tr><td class="label">Nomor Surat</td><td>${letter?.letterNumber || "-"}</td></tr>
  <tr><td class="label">Tanggal Surat</td><td>${letterDateStr}</td></tr>
  <tr><td class="label">Pengirim</td><td>${letter?.sender || "-"}</td></tr>
  <tr><td class="label">Perihal</td><td>${letter?.subject || "-"}</td></tr>
</table>
<table>
  <tr><td class="label">Dari</td><td>${disp.fromUser?.name || "-"}</td></tr>
  <tr><td class="label">Kepada</td><td>${disp.toUser?.name || "-"}</td></tr>
  <tr><td class="label">Instruksi</td><td>${disp.instruction || disp.notes || "-"}</td></tr>
  <tr><td class="label">Deadline</td><td>${deadlineStr}</td></tr>
  <tr><td class="label">Status</td><td>${statusLabel}</td></tr>
</table>
<div class="signature">
  <div>
    <p>Yang Mendisposisi,</p>
    <div class="line"></div>
    <p>${disp.fromUser?.name || ""}</p>
  </div>
  <div>
    <p>Yang Menerima,</p>
    <div class="line"></div>
    <p>${disp.toUser?.name || ""}</p>
  </div>
</div>
</body></html>`);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
  };
  const filteredDispositions = showCompleted
    ? dispositions.filter((d) => d.status === "SELESAI")
    : dispositions;

  return (
    <div className="space-y-6 pb-16">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Disposisi</h1>
          <p className="text-sm text-slate-500 mt-1">
            Pelacakan instruksi dan tindak lanjut surat.
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            variant="outline"
            onClick={() => setShowCompleted(!showCompleted)}
            className={cn(
              "h-9 text-sm font-medium rounded-md",
              showCompleted && "bg-slate-900 text-white hover:bg-slate-800"
            )}
          >
            <History className="w-4 h-4 mr-2" />
            Riwayat
          </Button>
          <Button
            onClick={() => navigate("/v2/surat-masuk")}
            className="h-9 text-sm font-medium rounded-md"
          >
            <ClipboardList className="w-4 h-4 mr-2" />
            Tugas Baru
          </Button>
        </div>
      </div>

      {/* Content */}
      <div className="grid gap-4">
        {loading ? (
          <div className="p-12 text-center border border-slate-200 shadow-sm rounded-lg bg-white flex flex-col items-center gap-3">
            <div className="w-8 h-8 border-3 border-slate-200 border-t-slate-600 rounded-full animate-spin"></div>
            <p className="text-sm text-slate-500">Memuat disposisi...</p>
          </div>
        ) : filteredDispositions.length === 0 ? (
          <div className="p-12 text-center border border-slate-200 shadow-sm rounded-lg bg-white flex flex-col items-center">
            <AlertCircle className="w-10 h-10 text-slate-300 mb-3" />
            <p className="text-sm font-medium text-slate-700">
              {showCompleted
                ? "Belum Ada Disposisi Selesai"
                : "Belum Ada Instruksi"}
            </p>
            <p className="text-xs text-slate-500 mt-1">
              {showCompleted
                ? "Disposisi yang telah selesai akan muncul di sini."
                : "Daftar disposisi untuk Anda akan muncul di sini."}
            </p>
            <Button
              variant="outline"
              onClick={() =>
                showCompleted ? setShowCompleted(false) : fetchDispositions()
              }
              className="mt-4 h-9 text-sm font-medium rounded-md"
            >
              {showCompleted ? "Lihat Semua" : "Refresh Halaman"}
            </Button>
          </div>
        ) : (
          filteredDispositions.map((disp) => (
            <div
              key={disp.id}
              className="border border-slate-200 shadow-sm rounded-lg bg-white"
            >
              <div className="flex flex-col lg:flex-row">
                {/* Left Section: Letter Info */}
                <div className="p-5 lg:w-80 border-b lg:border-b-0 lg:border-r border-slate-100">
                  <div className="flex items-center justify-between mb-3">
                    {getStatusBadge(disp.status)}
                    <DropdownMenu>
                      <DropdownMenuTrigger
                        render={
                          <button className="inline-flex items-center justify-center h-8 w-8 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100" />
                        }
                      >
                        <MoreVertical className="w-4 h-4" />
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end" sideOffset={4}>
                        <DropdownMenuGroup>
                          <DropdownMenuLabel>Opsi</DropdownMenuLabel>
                          <DropdownMenuItem
                            onClick={() => openLetterDetail(disp.incomingLetter)}
                          >
                            <ExternalLink className="w-4 h-4" />
                            Lihat Detail Surat
                          </DropdownMenuItem>
                        </DropdownMenuGroup>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>

                  <h4 className="text-sm font-medium text-slate-900 mb-1 leading-snug">
                    {disp.incomingLetter?.subject}
                  </h4>
                  <div className="flex items-center gap-1.5 text-xs text-slate-500 mb-4">
                    <FileText className="w-3 h-3" />
                    {disp.incomingLetter?.letterNumber}
                  </div>

                  <div className="space-y-2.5 pt-3 border-t border-slate-100">
                    <div className="flex items-center gap-2">
                      <User className="w-3.5 h-3.5 text-slate-400" />
                      <div className="flex flex-col">
                        <span className="text-xs text-slate-500">Dari</span>
                        <span className="text-sm font-medium text-slate-700">
                          {disp.fromUser?.name}
                        </span>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <ArrowRight className="w-3.5 h-3.5 text-slate-400" />
                      <div className="flex flex-col">
                        <span className="text-xs text-slate-500">Kepada</span>
                        <span className="text-sm font-medium text-slate-700">
                          {disp.toUser?.name}
                        </span>
                      </div>
                    </div>
                  </div>

                  <div className="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
                    <Clock className="w-3.5 h-3.5" />
                    Diterima:{" "}
                    {format(new Date(disp.createdAt), "dd MMM HH:mm", {
                      locale: id,
                    })}
                  </div>
                </div>                {/* Right Section: Instruction + Actions */}
                <div className="p-5 flex-1 flex flex-col justify-between">
                  {/* Deadline */}
                  {disp.deadline && (
                    <p className="text-xs text-red-600 mb-3 flex items-center gap-1.5">
                      <Calendar className="w-3.5 h-3.5" />
                      Deadline:{" "}
                      {format(new Date(disp.deadline), "dd MMM yyyy")}
                      {new Date(disp.deadline) < new Date() && disp.status !== "SELESAI" && (
                        <span className="ml-2 text-[10px] px-1.5 py-0.5 rounded bg-red-100 text-red-700 font-semibold uppercase">
                          TERLAMBAT
                        </span>
                      )}
                    </p>
                  )}

                  {/* Instruction */}
                  <div className="mb-4">
                    <p className="text-xs text-slate-500 mb-2 flex items-center gap-1.5">
                      <MessageSquare className="w-3.5 h-3.5" />
                      Instruksi Pimpinan
                    </p>
                    <div className="p-3 bg-slate-50 rounded-md">
                      <p className="text-sm text-slate-700 leading-relaxed">
                        &ldquo;
                        {disp.instruction ||
                          "Silakan laksanakan tindak lanjut sesuai dengan peraturan perundang-undangan yang berlaku dan laporkan hasilnya segera."}
                        &rdquo;
                      </p>
                      {disp.notes && (
                        <div className="mt-3 pt-3 border-t border-slate-200">
                          <p className="text-xs text-slate-500 mb-0.5">
                            Catatan Tambahan:
                          </p>
                          <p className="text-xs text-slate-600">
                            {disp.notes}
                          </p>
                        </div>
                      )}
                    </div>
                  </div>

                  {/* Actions */}
                  <div className="flex flex-col sm:flex-row items-center justify-end gap-2 mt-auto">
                    <Button
                      variant="outline"
                      onClick={() => handlePrintDisposisi(disp)}
                      className="h-9 text-sm font-medium rounded-md w-full sm:w-auto"
                    >
                      <Printer className="w-4 h-4 mr-1.5" />
                      Cetak
                    </Button>
                    <Button
                      variant="outline"
                      onClick={() => openLetterDetail(disp.incomingLetter)}
                      className="h-9 text-sm font-medium rounded-md w-full sm:w-auto"
                    >
                      <ExternalLink className="w-4 h-4 mr-1.5" />
                      Detail Surat
                    </Button>
                    {disp.toUser?.id === user?.id && disp.status !== "SELESAI" && (
                      <Button
                        variant="outline"
                        onClick={() => openForwardDialog(disp)}
                        className="h-9 text-sm font-medium rounded-md w-full sm:w-auto"
                      >
                        <Forward className="w-4 h-4 mr-1.5" />
                        Teruskan
                      </Button>
                    )}
                    {disp.toUser?.id === user?.id && (
                      <Button
                        onClick={() => openActionDialog(disp, disp.status)}
                        disabled={disp.status === "SELESAI"}
                        className={cn(
                          "h-9 text-sm font-medium rounded-md w-full sm:w-auto",
                          disp.status === "SELESAI"
                            ? "bg-slate-100 text-slate-400"
                            : ""
                        )}
                      >
                        {disp.status === "PENDING"
                          ? "Tindak Lanjut"
                          : disp.status === "PROSES"
                            ? "Selesaikan Tugas"
                            : "Tugas Selesai"}
                      </Button>
                    )}
                  </div>
                </div>
              </div>
            </div>
          ))
        )}
      </div>

      {/* Detail Surat Dialog */}
      <Dialog open={detailOpen} onOpenChange={setDetailOpen}>
        <DialogContent className="sm:max-w-lg rounded-lg">
          <DialogHeader>
            <DialogTitle className="text-lg font-semibold text-slate-900">
              Detail Surat Masuk
            </DialogTitle>
            <DialogDescription>
              Informasi lengkap surat yang didisposisikan.
            </DialogDescription>
          </DialogHeader>
          {selectedLetter && (
            <div className="space-y-4 py-2">
              <div className="space-y-1">
                <Label className="text-xs text-slate-500">Perihal</Label>
                <p className="text-sm font-medium text-slate-700">
                  {selectedLetter.subject || "-"}
                </p>
              </div>
              <div className="space-y-1">
                <Label className="text-xs text-slate-500">Nomor Surat</Label>
                <p className="text-sm font-medium text-slate-700">
                  {selectedLetter.letterNumber || "-"}
                </p>
              </div>
              <div className="space-y-1">
                <Label className="text-xs text-slate-500">Pengirim</Label>
                <p className="text-sm font-medium text-slate-700">
                  {selectedLetter.sender || "-"}
                </p>
              </div>
              <div className="space-y-1">
                <Label className="text-xs text-slate-500">Tanggal Surat</Label>
                <p className="text-sm font-medium text-slate-700">
                  {selectedLetter.letterDate
                    ? format(new Date(selectedLetter.letterDate), "dd MMMM yyyy", {
                        locale: id,
                      })
                    : "-"}
                </p>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1">
                  <Label className="text-xs text-slate-500">Klasifikasi</Label>
                  <p className="text-sm font-medium text-slate-700">
                    {selectedLetter.classification || "-"}
                  </p>
                </div>
                <div className="space-y-1">
                  <Label className="text-xs text-slate-500">Sifat Surat</Label>
                  <p className="text-sm font-medium text-slate-700">
                    {selectedLetter.nature || "-"}
                  </p>
                </div>
              </div>
            </div>
          )}
          <DialogFooter showCloseButton />
        </DialogContent>
      </Dialog>

      {/* Forward Disposition Dialog */}
      <Dialog open={forwardOpen} onOpenChange={setForwardOpen}>
        <DialogContent className="sm:max-w-[500px] rounded-lg">
          <form onSubmit={handleForwardSubmit}>
            <DialogHeader>
              <DialogTitle className="text-lg font-semibold text-slate-900">Teruskan Disposisi</DialogTitle>
              <DialogDescription className="text-sm text-slate-500">Teruskan disposisi ini kepada pejabat/staf di bawah Anda</DialogDescription>
            </DialogHeader>
            <div className="py-5 space-y-4">
              {forwardDisp && (
                <div className="p-3 bg-slate-50 rounded-lg border border-slate-100">
                  <p className="text-xs text-slate-500 mb-0.5">Surat:</p>
                  <p className="text-sm font-medium text-slate-900">{forwardDisp.incomingLetter?.subject}</p>
                  <p className="text-xs text-slate-400 mt-0.5">{forwardDisp.incomingLetter?.letterNumber}</p>
                </div>
              )}
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Tujukan Kepada</Label>
                {subordinatesLoading ? (
                  <div className="flex items-center gap-2 h-9 px-3 rounded-md border border-slate-200 bg-slate-50 text-sm text-slate-400">
                    <Loader2 className="w-4 h-4 animate-spin" />
                    Memuat daftar penerima...
                  </div>
                ) : subordinates.length === 0 ? (
                  <div className="flex items-center h-9 px-3 rounded-md border border-slate-200 bg-slate-50 text-sm text-slate-500">
                    Tidak ada penerima tersedia
                  </div>
                ) : (
                  <Select value={forwardToUserId} onValueChange={setForwardToUserId}>
                    <SelectTrigger className="h-9 rounded-md">
                      <SelectValue placeholder="Pilih pejabat/staf..." />
                    </SelectTrigger>
                    <SelectContent>
                      {subordinates.map((u) => (
                        <SelectItem key={u.id} value={u.id}>{u.name} &mdash; {roleLabel(u.role)}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              </div>
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Instruksi / Catatan</Label>
                <Input required placeholder="Tulis instruksi..." className="h-9 rounded-md" value={forwardInstruction} onChange={(e) => setForwardInstruction(e.target.value)} />
              </div>
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Batas Waktu</Label>
                <Input type="date" className="h-9 rounded-md" value={forwardDeadline} onChange={(e) => setForwardDeadline(e.target.value)} />
              </div>
            </div>
            <DialogFooter className="gap-2">
              <Button type="button" variant="outline" onClick={() => setForwardOpen(false)} className="h-9 text-sm font-medium rounded-md">Batal</Button>
              <Button disabled={forwardSubmitting || !forwardToUserId || subordinates.length === 0} type="submit" className="h-9 text-sm font-medium rounded-md">
                {forwardSubmitting && <Loader2 className="w-4 h-4 animate-spin mr-1.5" />}
                Teruskan
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Action Form Dialog - Tindak Lanjut / Selesaikan */}
      <Dialog open={actionOpen} onOpenChange={setActionOpen}>
        <DialogContent className="sm:max-w-[500px] rounded-lg">
          <form onSubmit={handleActionSubmit}>
            <DialogHeader>
              <DialogTitle className="text-lg font-semibold text-slate-900">
                {actionNextStatus === "PROSES" ? "Form Tindak Lanjut" : "Form Penyelesaian Tugas"}
              </DialogTitle>
              <DialogDescription className="text-sm text-slate-500">
                {actionNextStatus === "PROSES"
                  ? "Isi form berikut untuk memulai tindak lanjut disposisi ini."
                  : "Isi form berikut sebagai laporan penyelesaian tugas disposisi."}
              </DialogDescription>
            </DialogHeader>
            <div className="py-5 space-y-4">
              {actionDisp && (
                <div className="p-3 bg-slate-50 rounded-lg border border-slate-100">
                  <p className="text-xs text-slate-500 mb-0.5">Surat:</p>
                  <p className="text-sm font-medium text-slate-900">{actionDisp.incomingLetter?.subject}</p>
                  <p className="text-xs text-slate-400 mt-0.5">{actionDisp.incomingLetter?.letterNumber}</p>
                  {actionDisp.instruction && (
                    <div className="mt-2 pt-2 border-t border-slate-200">
                      <p className="text-xs text-slate-500">Instruksi:</p>
                      <p className="text-xs text-slate-700 italic">&ldquo;{actionDisp.instruction}&rdquo;</p>
                    </div>
                  )}
                </div>
              )}
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">
                  {actionNextStatus === "PROSES" ? "Rencana Tindak Lanjut *" : "Laporan Hasil Pelaksanaan *"}
                </Label>
                <textarea
                  required
                  rows={3}
                  placeholder={
                    actionNextStatus === "PROSES"
                      ? "Jelaskan langkah-langkah yang akan dilakukan..."
                      : "Jelaskan hasil pelaksanaan tugas..."
                  }
                  className="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-1 resize-none"
                  value={actionNotes}
                  onChange={(e) => setActionNotes(e.target.value)}
                />
              </div>
              {actionNextStatus === "SELESAI" && (
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Kesimpulan / Hasil Akhir</Label>
                  <Input
                    placeholder="Ringkasan hasil akhir (opsional)"
                    className="h-9 rounded-md"
                    value={actionResult}
                    onChange={(e) => setActionResult(e.target.value)}
                  />
                </div>
              )}
            </div>
            <DialogFooter className="gap-2">
              <Button type="button" variant="outline" onClick={() => setActionOpen(false)} className="h-9 text-sm font-medium rounded-md">
                Batal
              </Button>
              <Button
                disabled={actionSubmitting || !actionNotes.trim()}
                type="submit"
                className="h-9 text-sm font-medium rounded-md"
              >
                {actionSubmitting && <Loader2 className="w-4 h-4 animate-spin mr-1.5" />}
                {actionNextStatus === "PROSES" ? "Mulai Tindak Lanjut" : "Selesaikan Tugas"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}