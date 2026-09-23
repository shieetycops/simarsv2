import { useEffect, useMemo, useState } from "react";
import type React from "react";
import { useAuth } from "@/src/lib/AuthContext";
import { toast } from "sonner";
import { 
  Table, 
  TableBody, 
  TableCell, 
  TableHead, 
  TableHeader, 
  TableRow 
} from "@/components/ui/table";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { 
  Plus, 
  Search, 
  MoreHorizontal, 
  Download,
  Trash2,
  Edit2,
  ExternalLink,
  MailCheck,
  Loader2,
  FileText
} from "lucide-react";
import { 
  DropdownMenu, 
  DropdownMenuContent, 
  DropdownMenuItem, 
  DropdownMenuSeparator, 
  DropdownMenuTrigger 
} from "@/components/ui/dropdown-menu";
import { 
  Dialog, 
  DialogContent, 
  DialogDescription, 
  DialogFooter, 
  DialogHeader, 
  DialogTitle, 
  DialogTrigger 
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { format } from "date-fns";
import { id } from "date-fns/locale";
import { CLASSIFICATION_OPTIONS, NATURE_OPTIONS, ISSUING_UNIT_OPTIONS } from "@/src/lib/letterOptions";
import { parseLetterNumber, usedSequences, extractKode } from "@/src/lib/letterNumber";
import type { Slot } from "@/src/lib/letterNumber";

export default function OutgoingLetters() {
  const { token, user } = useAuth();
  const [letters, setLetters] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [incomingLetters, setIncomingLetters] = useState<any[]>([]);
  const [users, setUsers] = useState<any[]>([]);
  const [search, setSearch] = useState("");
  const [filterClassification, setFilterClassification] = useState("");
  const [filterIssuingUnit, setFilterIssuingUnit] = useState("");
  const [open, setOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [page, setPage] = useState(1);
  const pageSize = 10;

  // Preview dialog state
  const [previewOpen, setPreviewOpen] = useState(false);
  const [previewLetter, setPreviewLetter] = useState<any>(null);

  // Edit dialog state
  const [editOpen, setEditOpen] = useState(false);
  const [editLetter, setEditLetter] = useState<any>(null);
  const [editSubmitting, setEditSubmitting] = useState(false);
  const [editFile, setEditFile] = useState<File | null>(null);
  const [editFormData, setEditFormData] = useState<any>({
    letterNumber: "",
    letterDate: "",
    destination: "",
    subject: "",
    signer: "",
    agendaNumber: "",
    classification: "DINAS",
    issuingUnit: "PPK",
    nature: "BIASA",
    replyToIncomingId: "",
  });

  // Delete dialog state
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleteLetter, setDeleteLetter] = useState<any>(null);
  const [deleteSubmitting, setDeleteSubmitting] = useState(false);

  const [formData, setFormData] = useState({
    letterNumber: "",
    letterDate: format(new Date(), "yyyy-MM-dd"),
    destination: "",
    subject: "",
    signer: "",
    agendaNumber: "",
    classification: "DINAS",
    issuingUnit: "PPK",
    nature: "BIASA",
    replyToIncomingId: "",
    kode: "",
  });

  // State penomoran otomatis & slot reservasi.
  const [isSisipan, setIsSisipan] = useState(false);
  const [afterSeq, setAfterSeq] = useState("");
  const [pendingSlots, setPendingSlots] = useState<Slot[]>([]);
  const [selectedSlotId, setSelectedSlotId] = useState("");

  useEffect(() => {
    fetchLetters();
    fetchIncomingLetters();
    fetchUsers();
  }, []);

  // Opsi nomor untuk sisipan / slot dipesan (unit + tahun dari form).
  const afterOptions = useMemo(() => {
    const year = parseInt((formData.letterDate || "").slice(0, 4) || String(new Date().getFullYear()), 10);
    const set = new Set<number>(usedSequences(letters, formData.issuingUnit, year));
    for (const s of pendingSlots) {
      const p = parseLetterNumber(s.letterNumber);
      if (p) set.add(p.sequence);
    }
    return [...set].sort((a, b) => a - b);
  }, [letters, pendingSlots, formData.issuingUnit, formData.letterDate]);

  // Auto-generate nomor surat dari server (skip slot DIPESAN + data terbaru).
  useEffect(() => {
    if (!open || selectedSlotId) return;
    const t = setTimeout(async () => {
      try {
        const params = new URLSearchParams({
          unit: formData.issuingUnit,
          date: formData.letterDate || format(new Date(), "yyyy-MM-dd"),
        });
        if ((formData.kode || "").trim()) params.set("kode", formData.kode.trim());
        if (isSisipan && afterSeq) params.set("after", afterSeq);
        const res = await fetch(`/api/outgoing/next-letter?${params}`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        if (!res.ok) return;
        const data = await res.json();
        if (data?.letterNumber) {
          setFormData(prev => ({ ...prev, letterNumber: data.letterNumber }));
        }
      } catch {}
    }, 250);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, formData.issuingUnit, formData.letterDate, formData.kode, isSisipan, afterSeq, selectedSlotId, token]);

  const fetchUsers = async () => {
    try {
      const res = await fetch("/api/users/list", {
        headers: { Authorization: `Bearer ${token}` }
      });
      if (!res.ok) return;
      const data = await res.json();
      setUsers(Array.isArray(data) ? data : []);
    } catch (err) { console.error(err); }
  };

  const fetchIncomingLetters = async () => {
    try {
      const res = await fetch("/api/incoming", {
        headers: { Authorization: `Bearer ${token}` }
      });
      if (!res.ok) return;
      const data = await res.json();
      setIncomingLetters(Array.isArray(data) ? data : []);
    } catch (err) { console.error(err); }
  };

  const fetchLetters = async () => {
    try {
      const res = await fetch("/api/outgoing", {
        headers: { Authorization: `Bearer ${token}` }
      });
      if (!res.ok) return;
      const data = await res.json();
      setLetters(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      let payload = formData;
      // Pengaman: nomor belum ter-generate (submit terlalu cepat) — minta sekarang.
      if (!payload.letterNumber) {
        const params = new URLSearchParams({
          unit: payload.issuingUnit,
          date: payload.letterDate || format(new Date(), "yyyy-MM-dd"),
        });
        if ((payload.kode || "").trim()) params.set("kode", payload.kode.trim());
        if (isSisipan && afterSeq) params.set("after", afterSeq);
        const resNum = await fetch(`/api/outgoing/next-letter?${params}`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        if (resNum.ok) {
          const num = await resNum.json();
          payload = { ...payload, letterNumber: num.letterNumber || "" };
        }
      }
      const data = new FormData();
      data.append("data", JSON.stringify(payload));
      if (file) {
        data.append("file", file);
      }

      const res = await fetch("/api/outgoing", {
        method: "POST",
        headers: { 
          Authorization: `Bearer ${token}` 
        },
        body: data
      });
      if (res.ok) {
        // Jika nomor berasal dari slot yang dipesan, tandai slot TERBIT.
        if (selectedSlotId) {
          fetch(`/api/outgoing/slots/${selectedSlotId}`, {
            method: "PUT",
            headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
            body: JSON.stringify({ status: "TERBIT" }),
          }).catch(() => {});
        }
        setOpen(false);
        fetchLetters();
        setFormData({
          letterNumber: "",
          letterDate: format(new Date(), "yyyy-MM-dd"),
          destination: "",
          subject: "",
          signer: "",
          agendaNumber: '',
          classification: 'DINAS',
          issuingUnit: 'PPK',
          nature: 'BIASA',
          replyToIncomingId: '',
          kode: '',
        });
        setIsSisipan(false);
        setAfterSeq("");
        setSelectedSlotId("");
        setFile(null);
        toast.success("Surat keluar berhasil disimpan");
      } else {
        toast.error("Gagal menyimpan data");
      }
    } catch (err) {
      console.error(err);
      toast.error("Gagal menyimpan data");
    } finally {
      setSubmitting(false);
    }
  };

  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editLetter) return;
    setEditSubmitting(true);
    try {
      let res: Response;
      if (editFile) {
        const fd = new FormData();
        fd.append("data", JSON.stringify(editFormData));
        fd.append("file", editFile);
        res = await fetch(`/api/outgoing/${editLetter.id}`, {
          method: "PUT",
          headers: { Authorization: `Bearer ${token}` },
          body: fd,
        });
      } else {
        res = await fetch(`/api/outgoing/${editLetter.id}`, {
          method: "PUT",
          headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify(editFormData),
        });
      }
      if (!res.ok) {
        toast.error("Gagal menyimpan data");
        return;
      }
      setEditOpen(false);
      setEditLetter(null);
      fetchLetters();
      toast.success("Data surat berhasil diperbarui");
    } catch (err) {
      console.error(err);
      toast.error("Gagal menyimpan data");
    } finally {
      setEditSubmitting(false);
    }
  };

  const handleDelete = async () => {
    if (!deleteLetter) return;
    setDeleteSubmitting(true);
    try {
      const res = await fetch(`/api/outgoing/${deleteLetter.id}`, {
        method: "DELETE",
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) {
        toast.error("Gagal menyimpan data");
        return;
      }
      setDeleteOpen(false);
      setDeleteLetter(null);
      fetchLetters();
      toast.success("Surat berhasil dihapus");
    } catch (err) {
      console.error(err);
      toast.error("Gagal menyimpan data");
    } finally {
      setDeleteSubmitting(false);
    }
  };

  const openPreview = (letter: any) => {
    setPreviewLetter(letter);
    setPreviewOpen(true);
  };

  const openEdit = (letter: any) => {
    setEditLetter(letter);
    setEditFormData({
      letterNumber: letter.letterNumber || "",
      letterDate: letter.letterDate ? format(new Date(letter.letterDate), "yyyy-MM-dd") : "",
      destination: letter.destination || "",
      subject: letter.subject || "",
      signer: letter.signer || "",
      agendaNumber: letter.agendaNumber || "",
      classification: letter.classification || "DINAS",
      issuingUnit: letter.issuingUnit || "PPK",
      nature: letter.nature || "BIASA",
      replyToIncomingId: letter.replyToIncomingId || "",
      filePath: letter.filePath,
    });
    setEditFile(null);
    setEditOpen(true);
  };

  const openDeleteConfirm = (letter: any) => {
    setDeleteLetter(letter);
    setDeleteOpen(true);
  };

  const filteredLetters = letters.filter(l => {
    const q = search.toLowerCase();
    const matchesSearch = l.subject?.toLowerCase().includes(q) ||
      l.destination?.toLowerCase().includes(q) ||
      l.letterNumber?.toLowerCase().includes(q) ||
      l.agendaNumber?.toLowerCase().includes(q);
    const matchesClassification = !filterClassification || l.classification === filterClassification;
    const matchesIssuingUnit = !filterIssuingUnit || l.issuingUnit === filterIssuingUnit;
    return matchesSearch && matchesClassification && matchesIssuingUnit;
  });

  const totalPages = Math.ceil(filteredLetters.length / pageSize);
  const paginatedLetters = filteredLetters.slice((page - 1) * pageSize, page * pageSize);

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Surat Keluar</h1>
          <p className="text-sm text-slate-500 mt-1">Registrasi dan dokumentasi surat dinas keluar.</p>
        </div>
        {(user?.role === "ADMIN" || user?.role === "SEKRETARIS") && (
          <Dialog open={open} onOpenChange={(isOpen) => {
            setOpen(isOpen);
            if (isOpen) {
              fetch("/api/outgoing/next-agenda", {
                headers: { Authorization: `Bearer ${token}` }
              }).then(res => {
                if (!res.ok) return;
                return res.json();
              }).then(data => {
                if (data?.agendaNumber) {
                  setFormData(prev => ({ ...prev, agendaNumber: data.agendaNumber }));
                }
              }).catch(() => {});
              // Prefill kode surat dari surat terakhir unit tersebut.
              const unit = formData.issuingUnit;
              const lastLetter = letters.find(l => l.issuingUnit === unit && l.letterNumber);
              setFormData(prev => ({
                ...prev,
                kode: lastLetter ? extractKode(lastLetter.letterNumber) : "",
              }));
              setIsSisipan(false);
              setAfterSeq("");
              setSelectedSlotId("");
              // Muat slot DIPESAN yang bisa dipakai langsung.
              const year = (formData.letterDate || "").slice(0, 4) || String(new Date().getFullYear());
              fetch(`/api/outgoing/slots?unit=${unit}&year=${year}`, {
                headers: { Authorization: `Bearer ${token}` },
              }).then(res => (res.ok ? res.json() : [])).then((data: any[]) => {
                setPendingSlots(Array.isArray(data) ? data.filter((s: any) => s.status === "DIPESAN") : []);
              }).catch(() => setPendingSlots([]));
            }
          }}>
            <DialogTrigger className="bg-primary text-primary-foreground h-9 px-4 text-sm font-medium rounded-md inline-flex items-center justify-center hover:bg-primary/90 transition-colors">
              <Plus className="w-4 h-4 mr-1.5" />
              Terbitkan Surat
            </DialogTrigger>
            <DialogContent className="sm:max-w-[600px] rounded-lg">
              <form onSubmit={handleSubmit}>
                <DialogHeader>
                  <DialogTitle>Registrasi Surat Keluar</DialogTitle>
                  <DialogDescription>Penomoran dan dokumentasi arsip keluar</DialogDescription>
                </DialogHeader>
                <div className="p-6 space-y-4">
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Nomor Agenda</Label>
                <div className="flex gap-1.5">
                  <Input placeholder="SK/2024/..." className="h-9 rounded-md flex-1" value={formData.agendaNumber} onChange={(e) => setFormData({...formData, agendaNumber: e.target.value})} />
                  <Button type="button" variant="outline" size="sm" className="h-9 px-2 text-xs font-medium rounded-md" onClick={async () => {
                    try {
                      const res = await fetch("/api/outgoing/next-agenda", {
                        headers: { Authorization: `Bearer ${token}` }
                      });
                      if (!res.ok) return;
                      const data = await res.json();
                      if (data?.agendaNumber) setFormData(prev => ({ ...prev, agendaNumber: data.agendaNumber }));
                    } catch {}
                  }}>Generate</Button>
                </div>
              </div>
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Kode Surat</Label>
                    <Input placeholder="cth. SPD.PPK.PA.PSW" className="h-9 rounded-md" value={formData.kode} onChange={(e) => setFormData({...formData, kode: e.target.value})} />
                    <p className="text-xs text-slate-400">Diisi otomatis dari surat terakhir, bisa diganti. Nomor surat akan menyesuaikan.</p>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-1.5">
                      <Label className="text-sm font-medium text-slate-700">Nomor Surat</Label>
                      <Input required placeholder="W20-A/123/..." className="h-9 rounded-md" value={formData.letterNumber} onChange={(e) => setFormData({...formData, letterNumber: e.target.value})} />
                    </div>
                    <div className="space-y-1.5">
                      <Label className="text-sm font-medium text-slate-700">Tanggal Surat</Label>
                      <Input required type="date" className="h-9 rounded-md" value={formData.letterDate} onChange={(e) => setFormData({...formData, letterDate: e.target.value})} />
                    </div>
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Surat Sisipan</Label>
                    <label className="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={isSisipan}
                        onChange={(e) => setIsSisipan(e.target.checked)}
                        className="h-4 w-4 rounded border-slate-300"
                      />
                      Buat nomor sisipan (di antara nomor yang sudah ada)
                    </label>
                    {isSisipan && (
                      <Select value={afterSeq} onValueChange={setAfterSeq}>
                        <SelectTrigger className="h-9 rounded-md"><SelectValue placeholder="Pilih nomor yang disisipi..." /></SelectTrigger>
                        <SelectContent>
                          {afterOptions.length === 0 ? (
                            <div className="px-2 py-1.5 text-xs text-slate-400">Belum ada nomor yang bisa disisipi.</div>
                          ) : (
                            afterOptions.map((n) => (
                              <SelectItem key={n} value={String(n)}>Setelah nomor {n}</SelectItem>
                            ))
                          )}
                        </SelectContent>
                      </Select>
                    )}
                    {pendingSlots.length > 0 && (
                      <div className="space-y-1.5 pt-1">
                        <Label className="text-sm font-medium text-slate-700">Gunakan Nomor yang Sudah Dipesan (Opsional)</Label>
                        <Select value={selectedSlotId} onValueChange={(v) => {
                          setSelectedSlotId(v);
                          if (v === "NONE") return;
                          const slot = pendingSlots.find((s) => s.id === v);
                          if (slot) setFormData(prev => ({ ...prev, letterNumber: slot.letterNumber }));
                        }}>
                          <SelectTrigger className="h-9 rounded-md"><SelectValue placeholder="Pilih slot..." /></SelectTrigger>
                          <SelectContent>
                            <SelectItem value="NONE">Generate otomatis</SelectItem>
                            {pendingSlots.map((s) => (
                              <SelectItem key={s.id} value={s.id}>{s.letterNumber}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                    )}
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Tujuan Surat</Label>
                    <Input required placeholder="Instansi atau perorangan..." className="h-9 rounded-md" value={formData.destination} onChange={(e) => setFormData({...formData, destination: e.target.value})} />
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Perihal / Hal</Label>
                    <Input required placeholder="Isi perihal..." className="h-9 rounded-md" value={formData.subject} onChange={(e) => setFormData({...formData, subject: e.target.value})} />
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Penanggung Jawab</Label>
                    <Select value={formData.signer} onValueChange={(v) => setFormData({...formData, signer: v})}>
                      <SelectTrigger className="h-9 rounded-md"><SelectValue placeholder="Pilih penanggung jawab..." /></SelectTrigger>
                      <SelectContent>
                        {users.map((u) => (
                          <SelectItem key={u.id} value={u.name}>{u.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
              <div className="grid grid-cols-3 gap-4">
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Klasifikasi</Label>
                  <Select value={formData.classification} onValueChange={(v) => setFormData({...formData, classification: v})}>
                    <SelectTrigger className="h-9 rounded-md"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {CLASSIFICATION_OPTIONS.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Unit Penerbit</Label>
                  <Select value={formData.issuingUnit} onValueChange={(v) => {
                    const lastUnitLetter = letters.find((l) => l.issuingUnit === v && l.letterNumber);
                    setFormData((prev) => ({
                      ...prev,
                      issuingUnit: v,
                      kode: lastUnitLetter ? extractKode(lastUnitLetter.letterNumber) : "",
                    }));
                  }}>
                    <SelectTrigger className="h-9 rounded-md"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {ISSUING_UNIT_OPTIONS.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Sifat Surat</Label>
                  <Select value={formData.nature} onValueChange={(v) => setFormData({...formData, nature: v})}>
                    <SelectTrigger className="h-9 rounded-md"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {NATURE_OPTIONS.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Balasan Surat Masuk (Opsional)</Label>
                <Select value={formData.replyToIncomingId} onValueChange={(v) => setFormData({...formData, replyToIncomingId: v === "NONE" ? "" : v})}>
                  <SelectTrigger className="h-9 rounded-md"><SelectValue placeholder="Pilih surat masuk..." /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="NONE">Tanpa balasan</SelectItem>
                    {incomingLetters.map((l: any) => (
                      <SelectItem key={l.id} value={l.id}>{l.agendaNumber} - {l.subject}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Unggah Dokumen (PDF/DOCX)</Label>
                    <Input 
                      type="file" 
                      className="h-9 rounded-md" 
                      onChange={(e) => setFile(e.target.files?.[0] || null)}
                    />
                  </div>
                </div>
                <DialogFooter className="px-6 py-4 border-t border-slate-100">
                  <Button type="button" variant="ghost" onClick={() => setOpen(false)} className="h-9 text-sm font-medium rounded-md">Batal</Button>
                  <Button disabled={submitting} type="submit" className="h-9 px-4 text-sm font-medium rounded-md">
                    {submitting ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : <MailCheck className="w-4 h-4 mr-2" />}
                    Simpan & Terbit
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        )}
      </div>

      {/* Preview Dialog */}
      <Dialog open={previewOpen} onOpenChange={setPreviewOpen}>
        <DialogContent className="sm:max-w-[550px] rounded-lg">
          <DialogHeader>
            <DialogTitle>Detail Surat Keluar</DialogTitle>
            <DialogDescription>Informasi lengkap dokumen</DialogDescription>
          </DialogHeader>
          {previewLetter && (
            <div className="p-6 space-y-4">
              <div className="space-y-1">
                <Label className="text-sm font-medium text-slate-700">Nomor Surat</Label>
                <p className="font-mono text-sm text-slate-900">{previewLetter.letterNumber}</p>
              </div>
              <div className="space-y-1">
                <Label className="text-sm font-medium text-slate-700">Tanggal Surat</Label>
                <p className="text-sm text-slate-700">{format(new Date(previewLetter.letterDate), "dd MMMM yyyy", { locale: id })}</p>
              </div>
              <div className="space-y-1">
                <Label className="text-sm font-medium text-slate-700">Tujuan</Label>
                <p className="text-sm text-slate-700">{previewLetter.destination}</p>
              </div>
              <div className="space-y-1">
                <Label className="text-sm font-medium text-slate-700">Perihal</Label>
                <p className="text-sm text-slate-700">{previewLetter.subject}</p>
              </div>
              <div className="space-y-1">
                <Label className="text-sm font-medium text-slate-700">Penanggung Jawab</Label>
                <p className="text-sm text-slate-700">{previewLetter.signer}</p>
              </div>
              <div className="space-y-1">
                <Label className="text-sm font-medium text-slate-700">Unit Penerbit</Label>
                <p className="text-sm text-slate-700">
                  {ISSUING_UNIT_OPTIONS.find(o => o.value === previewLetter.issuingUnit)?.label || previewLetter.issuingUnit || "-"}
                </p>
              </div>
              {previewLetter.filePath && (
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Dokumen Terlampir</Label>
                  <a
                    href={previewLetter.filePath}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 text-sm font-medium"
                  >
                    <ExternalLink className="w-4 h-4" />
                    Buka File
                  </a>
                </div>
              )}
            </div>
          )}
          <DialogFooter className="px-6 py-4 border-t border-slate-100">
            <Button type="button" variant="ghost" onClick={() => setPreviewOpen(false)} className="h-9 text-sm font-medium rounded-md">Tutup</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit Dialog */}
      <Dialog open={editOpen} onOpenChange={setEditOpen}>
        <DialogContent className="sm:max-w-[600px] rounded-lg">
          <form onSubmit={handleEditSubmit}>
            <DialogHeader>
              <DialogTitle>Edit Registrasi</DialogTitle>
              <DialogDescription>Perbarui data surat keluar</DialogDescription>
            </DialogHeader>
            <div className="p-6 space-y-4">
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Nomor Agenda</Label>
                <Input placeholder="SK/2024/..." className="h-9 rounded-md" value={editFormData.agendaNumber} onChange={(e) => setEditFormData({...editFormData, agendaNumber: e.target.value})} />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Nomor Surat</Label>
                  <Input required placeholder="W20-A/123/..." className="h-9 rounded-md" value={editFormData.letterNumber} onChange={(e) => setEditFormData({...editFormData, letterNumber: e.target.value})} />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Tanggal Surat</Label>
                  <Input required type="date" className="h-9 rounded-md" value={editFormData.letterDate} onChange={(e) => setEditFormData({...editFormData, letterDate: e.target.value})} />
                </div>
              </div>
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Tujuan Surat</Label>
                <Input required placeholder="Instansi atau perorangan..." className="h-9 rounded-md" value={editFormData.destination} onChange={(e) => setEditFormData({...editFormData, destination: e.target.value})} />
              </div>
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Perihal / Hal</Label>
                <Input required placeholder="Isi perihal..." className="h-9 rounded-md" value={editFormData.subject} onChange={(e) => setEditFormData({...editFormData, subject: e.target.value})} />
              </div>
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Penanggung Jawab</Label>
                <Select value={editFormData.signer} onValueChange={(v) => setEditFormData({...editFormData, signer: v})}>
                  <SelectTrigger className="h-9 rounded-md"><SelectValue placeholder="Pilih penanggung jawab..." /></SelectTrigger>
                  <SelectContent>
                    {users.map((u) => (
                      <SelectItem key={u.id} value={u.name}>{u.name}</SelectItem>
                    ))}
                    {editFormData.signer && !users.some((u) => u.name === editFormData.signer) && (
                      <SelectItem value={editFormData.signer}>{editFormData.signer} (data lama)</SelectItem>
                    )}
                  </SelectContent>
                </Select>
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Klasifikasi</Label>
                  <Select value={editFormData.classification} onValueChange={(v) => setEditFormData({...editFormData, classification: v})}>
                    <SelectTrigger className="h-9 rounded-md"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {CLASSIFICATION_OPTIONS.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Unit Penerbit</Label>
                  <Select value={editFormData.issuingUnit} onValueChange={(v) => setEditFormData({...editFormData, issuingUnit: v})}>
                    <SelectTrigger className="h-9 rounded-md"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {ISSUING_UNIT_OPTIONS.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Sifat Surat</Label>
                  <Select value={editFormData.nature} onValueChange={(v) => setEditFormData({...editFormData, nature: v})}>
                    <SelectTrigger className="h-9 rounded-md"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {NATURE_OPTIONS.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Ganti Lampiran Surat (PDF/JPG/PNG) - Opsional</Label>
                <Input
                  type="file"
                  className="h-9 rounded-md"
                  onChange={(e) => setEditFile(e.target.files?.[0] || null)}
                />
                {editLetter?.filePath && !editFile && (
                  <p className="text-xs text-slate-400">File saat ini: {editLetter.filePath}</p>
                )}
              </div>
            </div>
            <DialogFooter className="px-6 py-4 border-t border-slate-100">
              <Button type="button" variant="ghost" onClick={() => setEditOpen(false)} className="h-9 text-sm font-medium rounded-md">Batal</Button>
              <Button disabled={editSubmitting} type="submit" className="h-9 px-4 text-sm font-medium rounded-md">
                {editSubmitting ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : <Edit2 className="w-4 h-4 mr-2" />}
                Simpan Perubahan
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
        <DialogContent className="sm:max-w-[450px] rounded-lg">
          <DialogHeader>
            <DialogTitle>Hapus Data</DialogTitle>
            <DialogDescription>Konfirmasi penghapusan surat</DialogDescription>
          </DialogHeader>
          <div className="p-6 space-y-4">
            <p className="text-sm text-slate-700">
              Apakah Anda yakin ingin menghapus surat ini?
            </p>
            {deleteLetter && (
              <div className="bg-red-50 border border-red-100 rounded-md p-3 space-y-1">
                <p className="font-mono text-xs text-red-700">{deleteLetter.letterNumber}</p>
                <p className="text-xs text-red-600">{deleteLetter.subject}</p>
              </div>
            )}
            <p className="text-xs text-slate-500">
              Tindakan ini tidak dapat dibatalkan. Data surat akan dihapus secara permanen.
            </p>
          </div>
          <DialogFooter className="px-6 py-4 border-t border-slate-100">
            <Button type="button" variant="ghost" onClick={() => setDeleteOpen(false)} className="h-9 text-sm font-medium rounded-md">Batal</Button>
            <Button disabled={deleteSubmitting} type="button" onClick={handleDelete} className="bg-red-600 hover:bg-red-700 text-white h-9 px-4 text-sm font-medium rounded-md">
              {deleteSubmitting ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : <Trash2 className="w-4 h-4 mr-2" />}
              Ya, Hapus
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Card className="border border-slate-200 shadow-sm rounded-lg bg-white">
        <CardHeader className="border-b border-slate-100 p-4">
           <div className="flex flex-col lg:flex-row gap-4 justify-between items-center">
              <div className="relative w-full max-w-sm">
                <Search className="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                <Input 
                  placeholder="Cari nomor agenda, surat, tujuan, atau perihal..." 
                  className="pl-9 h-9 text-sm rounded-md" 
                  value={search}
                  onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                />
              </div>
              <div className="flex gap-2 w-full lg:w-auto items-center">
                 <Select value={filterClassification || "ALL"} onValueChange={(v) => { setFilterClassification(v === "ALL" ? "" : v); setPage(1); }}>
                   <SelectTrigger className="h-9 w-[180px] text-sm rounded-md">
                     <SelectValue placeholder="Semua Klasifikasi" />
                   </SelectTrigger>
                   <SelectContent>
                     <SelectItem value="ALL">Semua Klasifikasi</SelectItem>
                     {CLASSIFICATION_OPTIONS.map((opt) => (
                       <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                     ))}
                   </SelectContent>
                 </Select>
                 <Select value={filterIssuingUnit || "ALL"} onValueChange={(v) => { setFilterIssuingUnit(v === "ALL" ? "" : v); setPage(1); }}>
                   <SelectTrigger className="h-9 w-[160px] text-sm rounded-md">
                     <SelectValue placeholder="Semua Unit" />
                   </SelectTrigger>
                   <SelectContent>
                     <SelectItem value="ALL">Semua Unit</SelectItem>
                     {ISSUING_UNIT_OPTIONS.map((opt) => (
                       <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                     ))}
                   </SelectContent>
                 </Select>
                 <Button variant="outline" size="sm" className="h-9 px-3 text-sm font-medium rounded-md" onClick={() => { setFilterClassification(""); setFilterIssuingUnit(""); setSearch(""); setPage(1); }}>
                    Reset
                 </Button>
                 <Button variant="outline" size="sm" className="gap-1.5 h-9 px-3 text-sm font-medium rounded-md">
                    <Download className="w-4 h-4" />
                    Export
                 </Button>
              </div>
           </div>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow className="border-b border-slate-100">
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Agenda</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Nomor Surat</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Hal / Perihal</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Tujuan</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Klasifikasi</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Unit</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Tanggal</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Penanggung Jawab</TableHead>
                  <TableHead className="text-right text-xs font-medium text-slate-500 px-4 py-3">Opsi</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {loading ? (
                  <TableRow>
                    <TableCell colSpan={9} className="h-48 text-center">
                       <div className="flex flex-col items-center gap-2">
                          <Loader2 className="w-4 h-4 animate-spin text-slate-400" />
                          <span className="text-sm text-slate-500">Memuat data...</span>
                       </div>
                    </TableCell>
                  </TableRow>
                ) : filteredLetters.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={9} className="h-48 text-center">
                       <div className="flex flex-col items-center justify-center gap-2 py-8">
                          <MailCheck className="w-8 h-8 text-slate-300" />
                          <p className="text-sm font-medium text-slate-700">Belum Ada Surat Keluar</p>
                          <p className="text-xs text-slate-500">Klik tombol terbitkan surat untuk memulai.</p>
                       </div>
                    </TableCell>
                  </TableRow>
                ) : (
                  paginatedLetters.map((letter) => (
                    <TableRow key={letter.id} className="hover:bg-slate-50 transition-colors border-b border-slate-50">
                      <TableCell className="px-4 py-3 text-sm">
                        <span className="font-mono text-xs text-slate-600">
                          {letter.agendaNumber || "-"}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm">
                        <span className="font-mono text-sm text-slate-900">
                          {letter.letterNumber}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm max-w-[280px]">
                        <span className="text-slate-800 truncate block">{letter.subject}</span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm text-slate-600 max-w-[180px]">
                        <span className="truncate block">{letter.destination}</span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm">
                        <Badge variant="outline" className="text-xs px-2 py-0.5 rounded border-slate-200 text-slate-600">
                          {(() => {
                            const opt = CLASSIFICATION_OPTIONS.find(o => o.value === letter.classification);
                            return opt ? opt.label : (letter.classification || "-");
                          })()}
                        </Badge>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm">
                        <Badge variant="outline" className="text-xs px-2 py-0.5 rounded border-slate-200 text-slate-600 font-normal">
                          {ISSUING_UNIT_OPTIONS.find(o => o.value === letter.issuingUnit)?.label || letter.issuingUnit || "-"}
                        </Badge>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm text-slate-500">
                        {letter.letterDate ? format(new Date(letter.letterDate), 'dd MMM yyyy', { locale: id }) : '-'}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm">
                         <Badge variant="outline" className="text-xs px-2 py-0.5 rounded border-slate-200 text-slate-600">
                            {letter.signer}
                         </Badge>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm text-right">
                        <DropdownMenu>
                          <DropdownMenuTrigger className="h-8 w-8 p-0 text-slate-400 hover:text-slate-600 inline-flex items-center justify-center rounded-md hover:bg-slate-100">
                              <MoreHorizontal className="h-4 w-4" />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-48 rounded-md shadow-md border-slate-200 p-1">
                             <DropdownMenuItem className="gap-2 text-sm py-2 rounded-md cursor-pointer" onClick={() => openPreview(letter)}>
                                <ExternalLink className="w-4 h-4" /> Lihat Dokumen
                             </DropdownMenuItem>
                             <DropdownMenuItem className="gap-2 text-sm py-2 rounded-md cursor-pointer" onClick={() => openEdit(letter)}>
                                <Edit2 className="w-4 h-4" /> Edit Registrasi
                             </DropdownMenuItem>
                             <DropdownMenuSeparator className="my-1" />
                             <DropdownMenuItem className="gap-2 text-sm py-2 rounded-md text-red-600 focus:text-red-600 focus:bg-red-50 cursor-pointer" onClick={() => openDeleteConfirm(letter)}>
                                <Trash2 className="w-4 h-4" /> Hapus Data
                             </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>
          {/* Footer */}
          <div className="px-4 py-3 border-t border-slate-100 flex flex-col sm:flex-row justify-between items-center gap-3">
            <p className="text-xs text-slate-500">
              Menampilkan {filteredLetters.length === 0 ? 0 : (page - 1) * pageSize + 1}–{Math.min(page * pageSize, filteredLetters.length)} dari {filteredLetters.length} surat
            </p>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" className="h-8 text-xs font-medium rounded-md" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Sebelumnya</Button>
              <Button variant="outline" size="sm" className="h-8 text-xs font-medium rounded-md" disabled={page >= totalPages} onClick={() => setPage(p => p + 1)}>Selanjutnya</Button>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
