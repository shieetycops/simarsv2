import { useEffect, useState } from "react";
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
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { 
  Plus, 
  Search, 
  MoreHorizontal, 
  FileText, 
  Eye, 
  Send,
  Download,
  Printer,
  FileSpreadsheet,
  Trash2,
  Edit2,
  Loader2
} from "lucide-react";
import { 
  DropdownMenu, 
  DropdownMenuContent, 
  DropdownMenuItem, 
  DropdownMenuGroup,
  DropdownMenuLabel, 
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
import { 
  Select, 
  SelectContent, 
  SelectItem, 
  SelectTrigger, 
  SelectValue 
} from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { format } from "date-fns";
import { id } from "date-fns/locale";
import { cn } from "@/lib/utils";
import { CLASSIFICATION_OPTIONS, NATURE_OPTIONS } from "@/src/lib/letterOptions";

export default function IncomingLetters() {
  const { token, user } = useAuth();
  const [letters, setLetters] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [open, setOpen] = useState(false);
  const [dispositionOpen, setDispositionOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [selectedLetter, setSelectedLetter] = useState<any>(null);
  const [users, setUsers] = useState<any[]>([]);
  const [file, setFile] = useState<File | null>(null);
  const [page, setPage] = useState(1);
  const pageSize = 10;
  const [filterDateFrom, setFilterDateFrom] = useState("");
  const [filterDateTo, setFilterDateTo] = useState("");
  const [filterSender, setFilterSender] = useState("");
  const [filterClassification, setFilterClassification] = useState("");

  const [formData, setFormData] = useState({
    agendaNumber: "",
    letterNumber: "",
    letterDate: format(new Date(), "yyyy-MM-dd"),
    receivedDate: format(new Date(), "yyyy-MM-dd"),
    sender: "",
    subject: "",
    classification: "DINAS",
    nature: "BIASA",
  });

  const [dispositionData, setDispositionData] = useState({
    instruction: "",
    deadline: format(new Date(), "yyyy-MM-dd"),
    toUserId: "",
  });

  const [editOpen, setEditOpen] = useState(false);
  const [editData, setEditData] = useState<any>(null);
  const [previewOpen, setPreviewOpen] = useState(false);
  const [previewLetter, setPreviewLetter] = useState<any>(null);
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<any>(null);
  const [editFile, setEditFile] = useState<File | null>(null);

  useEffect(() => {
    fetchLetters();
    fetchUsers();
  }, []);

  const fetchUsers = async () => {
    try {
      const res = await fetch("/api/users/list", {
        headers: { Authorization: `Bearer ${token}` }
      });
      if (!res.ok) return;
      const data = await res.json();
      setUsers(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error(err);
    }
  };

  const fetchLetters = async () => {
    try {
      const res = await fetch("/api/incoming", {
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
      const data = new FormData();
      data.append("data", JSON.stringify(formData));
      if (file) {
        data.append("file", file);
      }

      const res = await fetch("/api/incoming", {
        method: "POST",
        headers: { 
          Authorization: `Bearer ${token}` 
        },
        body: data
      });
      if (res.ok) {
        setOpen(false);
        fetchLetters();
        setFormData({
          agendaNumber: "",
          letterNumber: "",
          letterDate: format(new Date(), "yyyy-MM-dd"),
          receivedDate: format(new Date(), "yyyy-MM-dd"),
          sender: "",
          subject: "",
          classification: "DINAS",
          nature: "BIASA",
        });
        setFile(null);
        toast.success("Surat masuk berhasil disimpan");
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

  const filteredLetters = letters.filter(l => {
    const matchesSearch = l.subject?.toLowerCase().includes(search.toLowerCase()) ||
      l.sender?.toLowerCase().includes(search.toLowerCase()) ||
      l.letterNumber?.toLowerCase().includes(search.toLowerCase()) ||
      l.agendaNumber?.toLowerCase().includes(search.toLowerCase());
    
    const matchesSender = !filterSender || l.sender?.toLowerCase().includes(filterSender.toLowerCase());
    
    let matchesDate = true;
    if (filterDateFrom) {
      matchesDate = matchesDate && new Date(l.receivedDate) >= new Date(filterDateFrom);
    }
    if (filterDateTo) {
      matchesDate = matchesDate && new Date(l.receivedDate) <= new Date(filterDateTo + "T23:59:59");
    }
    
    const matchesClassification = !filterClassification || l.classification === filterClassification;
    return matchesSearch && matchesSender && matchesDate && matchesClassification;
  });

  const totalPages = Math.ceil(filteredLetters.length / pageSize);
  const paginatedLetters = filteredLetters.slice((page - 1) * pageSize, page * pageSize);

  const handleDispositionSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedLetter) return;
    setSubmitting(true);
    try {
      const res = await fetch("/api/dispositions", {
        method: "POST",
        headers: { 
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}` 
        },
        body: JSON.stringify({
          ...dispositionData,
          incomingLetterId: selectedLetter.id,
          fromUserId: user?.id,
          status: "PENDING"
        })
      });
      if (res.ok) {
        setDispositionOpen(false);
        fetchLetters();
        setDispositionData({
          instruction: "",
          deadline: format(new Date(), "yyyy-MM-dd"),
          toUserId: "",
        });
        toast.success("Disposisi berhasil dikirim");
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

  const getNatureBadge = (nature: string) => {
    switch (nature) {
      case 'RAHASIA': return <Badge className="bg-red-50 text-red-700 border border-red-200 text-xs px-2 py-0.5 rounded">Rahasia</Badge>;
      case 'PENTING': return <Badge className="bg-amber-50 text-amber-700 border border-amber-200 text-xs px-2 py-0.5 rounded">Penting</Badge>;
      case 'SEGERA': return <Badge className="bg-blue-50 text-blue-700 border border-blue-200 text-xs px-2 py-0.5 rounded">Segera</Badge>;
      default: return <Badge className="bg-slate-50 text-slate-600 border border-slate-200 text-xs px-2 py-0.5 rounded">Biasa</Badge>;
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'SELESAI': return <Badge className="bg-green-50 text-green-700 border border-green-200 text-xs px-2 py-0.5 rounded">Selesai</Badge>;
      case 'DALAM_PROSES': return <Badge className="bg-amber-50 text-amber-700 border border-amber-200 text-xs px-2 py-0.5 rounded">Dalam Proses</Badge>;
      default: return <Badge className="bg-slate-100 text-slate-600 border border-slate-200 text-xs px-2 py-0.5 rounded">Belum Didisposisi</Badge>;
    }
  };

  const handlePreview = (letter: any) => {
    setPreviewLetter(letter);
    setPreviewOpen(true);
  };

  const handleEdit = (letter: any) => {
    setEditData({
      id: letter.id,
      agendaNumber: letter.agendaNumber,
      letterNumber: letter.letterNumber,
      letterDate: format(new Date(letter.letterDate), "yyyy-MM-dd"),
      receivedDate: format(new Date(letter.receivedDate), "yyyy-MM-dd"),
      sender: letter.sender,
      subject: letter.subject,
      classification: letter.classification,
      nature: letter.nature,
      filePath: letter.filePath,
    });
    setEditFile(null);
    setEditOpen(true);
  };

  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editData) return;
    setSubmitting(true);
    try {
      const payload = {
        agendaNumber: editData.agendaNumber,
        letterNumber: editData.letterNumber,
        letterDate: editData.letterDate,
        receivedDate: editData.receivedDate,
        sender: editData.sender,
        subject: editData.subject,
        classification: editData.classification,
        nature: editData.nature,
      };
      let res: Response;
      if (editFile) {
        const fd = new FormData();
        fd.append("data", JSON.stringify(payload));
        fd.append("file", editFile);
        res = await fetch(`/api/incoming/${editData.id}`, {
          method: "PUT",
          headers: { Authorization: `Bearer ${token}` },
          body: fd,
        });
      } else {
        res = await fetch(`/api/incoming/${editData.id}`, {
          method: "PUT",
          headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify(payload),
        });
      }
      if (res.ok) {
        setEditOpen(false);
        setEditData(null);
        fetchLetters();
        toast.success("Data surat berhasil diperbarui");
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

  const handleDelete = async () => {
    if (!deleteTarget) return;
    setSubmitting(true);
    try {
      const res = await fetch(`/api/incoming/${deleteTarget.id}`, {
        method: "DELETE",
        headers: { Authorization: `Bearer ${token}` },
      });
      if (res.ok) {
        setDeleteConfirmOpen(false);
        setDeleteTarget(null);
        fetchLetters();
        toast.success("Surat berhasil dihapus");
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

  return (
    <div className="space-y-5">
      {/* Page Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Surat Masuk</h1>
          <p className="text-sm text-slate-500 mt-0.5">Registrasi dan manajemen arsip surat masuk</p>
        </div>
        {(user?.role === "ADMIN" || user?.role === "SEKRETARIS") && (
          <Dialog open={open} onOpenChange={(isOpen) => {
            setOpen(isOpen);
            if (isOpen) {
              fetch("/api/incoming/next-agenda", {
                headers: { Authorization: `Bearer ${token}` }
              }).then(res => {
                if (!res.ok) return;
                return res.json();
              }).then(data => {
                if (data?.agendaNumber) {
                  setFormData(prev => ({ ...prev, agendaNumber: data.agendaNumber }));
                }
              }).catch(() => {});
            }
          }}>
            <DialogTrigger className="bg-primary hover:bg-primary/90 text-primary-foreground h-9 px-4 text-sm font-medium rounded-md inline-flex items-center justify-center">
              <Plus className="w-4 h-4 mr-1.5" />
              Input Surat
            </DialogTrigger>
            <DialogContent className="sm:max-w-[640px] rounded-lg">
              <form onSubmit={handleSubmit}>
                <DialogHeader>
                  <DialogTitle className="text-lg font-semibold text-slate-900">Registrasi Surat Masuk</DialogTitle>
                  <DialogDescription className="text-sm text-slate-500">Lengkapi data surat masuk yang akan diarsipkan</DialogDescription>
                </DialogHeader>
                <div className="py-5 grid grid-cols-2 gap-4">
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Nomor Agenda</Label>
                    <Input required placeholder="AGD/2024/..." className="h-9 rounded-md" value={formData.agendaNumber} onChange={(e) => setFormData({...formData, agendaNumber: e.target.value})} />
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Nomor Surat</Label>
                    <Input required placeholder="001/MA/V/2024" className="h-9 rounded-md" value={formData.letterNumber} onChange={(e) => setFormData({...formData, letterNumber: e.target.value})} />
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Tanggal Surat</Label>
                    <Input required type="date" className="h-9 rounded-md" value={formData.letterDate} onChange={(e) => setFormData({...formData, letterDate: e.target.value})} />
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Tanggal Terima</Label>
                    <Input required type="date" className="h-9 rounded-md" value={formData.receivedDate} onChange={(e) => setFormData({...formData, receivedDate: e.target.value})} />
                  </div>
                  <div className="col-span-2 space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Asal / Pengirim</Label>
                    <Input required placeholder="Mahkamah Agung, PTA, atau Instansi Lainnya" className="h-9 rounded-md" value={formData.sender} onChange={(e) => setFormData({...formData, sender: e.target.value})} />
                  </div>
                  <div className="col-span-2 space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Perihal / Subjek</Label>
                    <Input required placeholder="Isi perihal surat..." className="h-9 rounded-md" value={formData.subject} onChange={(e) => setFormData({...formData, subject: e.target.value})} />
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Klasifikasi</Label>
                    <Select value={formData.classification} onValueChange={(v) => setFormData({...formData, classification: v})}>
                      <SelectTrigger className="h-9 rounded-md">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                      {CLASSIFICATION_OPTIONS.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                      ))}
                    </SelectContent>
                    </Select>
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Sifat Surat</Label>
                    <Select value={formData.nature} onValueChange={(v) => setFormData({...formData, nature: v})}>
                      <SelectTrigger className="h-9 rounded-md">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                      {NATURE_OPTIONS.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                      ))}
                    </SelectContent>
                    </Select>
                  </div>
                  <div className="col-span-2 space-y-1.5">
                    <Label className="text-sm font-medium text-slate-700">Unggah Scan Surat (PDF/JPG/PNG)</Label>
                    <Input 
                      type="file" 
                      className="h-9 rounded-md text-sm" 
                      onChange={(e) => setFile(e.target.files?.[0] || null)}
                    />
                  </div>
                </div>
                <DialogFooter className="gap-2">
                  <Button type="button" variant="outline" onClick={() => setOpen(false)} className="h-9 text-sm font-medium rounded-md">Batal</Button>
                  <Button disabled={submitting} type="submit" className="h-9 text-sm font-medium rounded-md">
                    {submitting && <Loader2 className="w-4 h-4 animate-spin mr-1.5" />}
                    Simpan
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        )}
      </div>

      {/* Main Table Card */}
      <Card className="border border-slate-200 shadow-sm rounded-lg">
        <CardHeader className="border-b border-slate-100 px-4 py-3">
          <div className="flex flex-col gap-3">
            <div className="flex flex-col lg:flex-row gap-3 justify-between items-center">
              <div className="relative w-full max-w-sm">
                <Search className="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                <Input 
                  placeholder="Cari surat..." 
                  className="pl-9 h-9 text-sm rounded-md" 
                  value={search}
                  onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                />
              </div>
              <div className="flex gap-2 w-full lg:w-auto">
                <DropdownMenu>
                  <DropdownMenuTrigger className="h-8 px-3 text-xs font-medium rounded-md gap-1.5 border border-slate-200 bg-white hover:bg-slate-50 inline-flex items-center justify-center">
                      <Download className="w-3.5 h-3.5" />
                      Export
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" className="w-44 rounded-lg">
                    <DropdownMenuItem className="gap-2 text-sm">
                      <FileSpreadsheet className="w-4 h-4 text-green-600" /> Export Excel
                    </DropdownMenuItem>
                    <DropdownMenuItem className="gap-2 text-sm">
                      <Printer className="w-4 h-4 text-blue-600" /> Cetak PDF
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            </div>
            <div className="flex flex-col sm:flex-row gap-3 items-end">
              <div className="space-y-1 flex-1">
                <label className="text-sm font-medium text-slate-700">Dari Tanggal</label>
                <Input type="date" className="h-9 rounded-md text-sm" value={filterDateFrom} onChange={(e) => { setFilterDateFrom(e.target.value); setPage(1); }} />
              </div>
              <div className="space-y-1 flex-1">
                <label className="text-sm font-medium text-slate-700">Sampai Tanggal</label>
                <Input type="date" className="h-9 rounded-md text-sm" value={filterDateTo} onChange={(e) => { setFilterDateTo(e.target.value); setPage(1); }} />
              </div>
              <div className="space-y-1 flex-1">
                <label className="text-sm font-medium text-slate-700">Pengirim</label>
                <Input placeholder="Filter pengirim..." className="h-9 rounded-md text-sm" value={filterSender} onChange={(e) => { setFilterSender(e.target.value); setPage(1); }} />
              </div>
              <div className="space-y-1 flex-1">
                <label className="text-sm font-medium text-slate-700">Klasifikasi</label>
                <Select value={filterClassification || "ALL"} onValueChange={(v) => { setFilterClassification(v === "ALL" ? "" : v); setPage(1); }}>
                  <SelectTrigger className="h-9 rounded-md text-sm">
                    <SelectValue placeholder="Semua Klasifikasi" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="ALL">Semua Klasifikasi</SelectItem>
                    {CLASSIFICATION_OPTIONS.map((opt) => (
                      <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <Button variant="outline" size="sm" className="h-9 text-xs font-medium rounded-md" onClick={() => { setFilterDateFrom(""); setFilterDateTo(""); setFilterSender(""); setFilterClassification(""); setPage(1); }}>
                Reset
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
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Data Surat</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Asal Surat</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Klasifikasi</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Status</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3 text-right">Opsi</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {loading ? (
                  <TableRow>
                    <TableCell colSpan={6} className="h-48 text-center">
                      <div className="flex items-center justify-center gap-2">
                        <Loader2 className="w-4 h-4 animate-spin text-slate-400" />
                        <span className="text-sm text-slate-500">Memuat data...</span>
                      </div>
                    </TableCell>
                  </TableRow>
                ) : filteredLetters.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="h-48 text-center">
                      <div className="flex flex-col items-center justify-center gap-2 py-8">
                        <FileText className="w-10 h-10 text-slate-300" />
                        <p className="text-sm font-medium text-slate-700">Belum ada data</p>
                        <p className="text-xs text-slate-400">Belum ada surat masuk yang terdaftar</p>
                      </div>
                    </TableCell>
                  </TableRow>
                ) : (
                  paginatedLetters.map((letter) => (
                    <TableRow key={letter.id} className="hover:bg-slate-50 border-b border-slate-50">
                      <TableCell className="px-4 py-3">
                        <div className="flex flex-col gap-0.5">
                          <span className="text-sm font-medium text-slate-900">{letter.agendaNumber}</span>
                          <span className="text-xs text-slate-400">{format(new Date(letter.receivedDate), 'dd/MM/yyyy')}</span>
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3 max-w-[320px]">
                        <div className="flex flex-col gap-0.5 min-w-0">
                          <div className="flex items-center gap-2 min-w-0">
                            {getNatureBadge(letter.nature)}
                            <span className="text-sm text-slate-900 truncate min-w-0">{letter.subject}</span>
                          </div>
                          <span className="text-xs text-slate-400 truncate">{letter.letterNumber}</span>
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3 max-w-[180px]">
                        <span className="text-sm text-slate-700 truncate block">{letter.sender}</span>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <Badge variant="outline" className="text-xs px-2 py-0.5 rounded border-slate-200 text-slate-600 font-normal">
                          {letter.classification}
                        </Badge>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        {getStatusBadge(letter.status)}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-right">
                        <DropdownMenu>
                          <DropdownMenuTrigger className="h-8 w-8 p-0 text-slate-400 hover:text-slate-600 inline-flex items-center justify-center rounded-md hover:bg-slate-100">
                              <MoreHorizontal className="h-4 w-4" />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-48 rounded-lg">
                           <DropdownMenuGroup>
                            <DropdownMenuLabel className="text-xs font-medium text-slate-500">Aksi</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={() => handlePreview(letter)} className="gap-2 text-sm cursor-pointer">
                              <Eye className="w-4 h-4" /> Lihat Detail
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={() => handleEdit(letter)} className="gap-2 text-sm cursor-pointer">
                              <Edit2 className="w-4 h-4" /> Edit
                            </DropdownMenuItem>
                            {user?.role === "PIMPINAN" && (
                              <DropdownMenuItem 
                                onClick={() => {
                                  setSelectedLetter(letter);
                                  setDispositionOpen(true);
                                }}
                                className="gap-2 text-sm cursor-pointer text-blue-600 focus:text-blue-700"
                              >
                                <Send className="w-4 h-4" /> Buat Disposisi
                              </DropdownMenuItem>
                            )}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={() => { setDeleteTarget(letter); setDeleteConfirmOpen(true); }} className="gap-2 text-sm cursor-pointer text-red-600 focus:text-red-600">
                              <Trash2 className="w-4 h-4" /> Hapus
                            </DropdownMenuItem>
                           </DropdownMenuGroup>
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

      {/* Disposition Dialog */}
      <Dialog open={dispositionOpen} onOpenChange={setDispositionOpen}>
        <DialogContent className="sm:max-w-[500px] rounded-lg">
          <form onSubmit={handleDispositionSubmit}>
            <DialogHeader>
              <DialogTitle className="text-lg font-semibold text-slate-900">Buat Disposisi</DialogTitle>
              <DialogDescription className="text-sm text-slate-500">Teruskan surat kepada pejabat/staf terkait</DialogDescription>
            </DialogHeader>
            <div className="py-5 space-y-4">
              {selectedLetter && (
                <div className="p-3 bg-slate-50 rounded-lg border border-slate-100">
                  <p className="text-xs text-slate-500 mb-0.5">Surat yang didisposisi:</p>
                  <p className="text-sm font-medium text-slate-900">{selectedLetter.subject}</p>
                  <p className="text-xs text-slate-400 mt-0.5">{selectedLetter.letterNumber}</p>
                </div>
              )}

              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Tujukan Kepada</Label>
                <Select value={dispositionData.toUserId} onValueChange={(v) => setDispositionData({...dispositionData, toUserId: v})}>
                  <SelectTrigger className="h-9 rounded-md">
                    <SelectValue placeholder="Pilih pejabat/staf...">
                      {dispositionData.toUserId ? users.find(u => u.id === dispositionData.toUserId)?.name || "Pilih..." : undefined}
                    </SelectValue>
                  </SelectTrigger>
                  <SelectContent>
                    {users.filter(u => u.id !== user?.id).map((u) => (
                      <SelectItem key={u.id} value={u.id}>{u.name} ({u.role})</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Instruksi / Catatan</Label>
                <Input required placeholder="Tulis instruksi..." className="h-9 rounded-md" value={dispositionData.instruction} onChange={(e) => setDispositionData({...dispositionData, instruction: e.target.value})} />
              </div>

              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">Batas Waktu</Label>
                <Input type="date" className="h-9 rounded-md" value={dispositionData.deadline} onChange={(e) => setDispositionData({...dispositionData, deadline: e.target.value})} />
              </div>
            </div>
            <DialogFooter className="gap-2">
              <Button type="button" variant="outline" onClick={() => setDispositionOpen(false)} className="h-9 text-sm font-medium rounded-md">Batal</Button>
              <Button disabled={submitting || !dispositionData.toUserId} type="submit" className="h-9 text-sm font-medium rounded-md">
                {submitting && <Loader2 className="w-4 h-4 animate-spin mr-1.5" />}
                Kirim Disposisi
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Preview Dialog */}
      <Dialog open={previewOpen} onOpenChange={setPreviewOpen}>
        <DialogContent className="sm:max-w-[560px] rounded-lg">
          <DialogHeader>
            <DialogTitle className="text-lg font-semibold text-slate-900">Detail Surat</DialogTitle>
            <DialogDescription className="text-sm text-slate-500">Informasi lengkap surat masuk</DialogDescription>
          </DialogHeader>
          {previewLetter && (
            <div className="py-4 space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-0.5">Nomor Agenda</p>
                  <p className="text-sm font-medium text-slate-900">{previewLetter.agendaNumber}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-0.5">Nomor Surat</p>
                  <p className="text-sm font-medium text-slate-900">{previewLetter.letterNumber}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-0.5">Tanggal Surat</p>
                  <p className="text-sm font-medium text-slate-900">{format(new Date(previewLetter.letterDate), 'dd MMMM yyyy', { locale: id })}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-0.5">Tanggal Terima</p>
                  <p className="text-sm font-medium text-slate-900">{format(new Date(previewLetter.receivedDate), 'dd MMMM yyyy', { locale: id })}</p>
                </div>
                <div className="col-span-2 p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-0.5">Pengirim</p>
                  <p className="text-sm font-medium text-slate-900">{previewLetter.sender}</p>
                </div>
                <div className="col-span-2 p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-0.5">Perihal</p>
                  <p className="text-sm font-medium text-slate-900">{previewLetter.subject}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-0.5">Klasifikasi</p>
                  <p className="text-sm font-medium text-slate-900">{previewLetter.classification}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-0.5">Sifat</p>
                  <p className="text-sm font-medium text-slate-900">{previewLetter.nature}</p>
                </div>
                <div className="col-span-2 p-3 bg-slate-50 rounded-lg">
                  <p className="text-xs text-slate-500 mb-0.5">Status</p>
                  <div className="mt-0.5">{getStatusBadge(previewLetter.status)}</div>
                </div>
              </div>
              {previewLetter.filePath && (
                <div className="p-3 bg-blue-50 rounded-lg border border-blue-100">
                  <p className="text-xs text-slate-500 mb-1">Dokumen Terlampir</p>
                  <a href={previewLetter.filePath} target="_blank" rel="noopener noreferrer" className="text-sm font-medium text-blue-600 hover:text-blue-700 hover:underline">
                    Buka File →
                  </a>
                </div>
              )}
            </div>
          )}
        </DialogContent>
      </Dialog>

      {/* Edit Dialog */}
      <Dialog open={editOpen} onOpenChange={setEditOpen}>
        <DialogContent className="sm:max-w-[640px] rounded-lg">
          <form onSubmit={handleEditSubmit}>
            <DialogHeader>
              <DialogTitle className="text-lg font-semibold text-slate-900">Edit Surat</DialogTitle>
              <DialogDescription className="text-sm text-slate-500">Perbarui data surat masuk</DialogDescription>
            </DialogHeader>
            {editData && (
              <div className="py-5 grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Nomor Agenda</Label>
                  <Input required className="h-9 rounded-md" value={editData.agendaNumber} onChange={(e) => setEditData({...editData, agendaNumber: e.target.value})} />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Nomor Surat</Label>
                  <Input required className="h-9 rounded-md" value={editData.letterNumber} onChange={(e) => setEditData({...editData, letterNumber: e.target.value})} />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Tanggal Surat</Label>
                  <Input required type="date" className="h-9 rounded-md" value={editData.letterDate} onChange={(e) => setEditData({...editData, letterDate: e.target.value})} />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Tanggal Terima</Label>
                  <Input required type="date" className="h-9 rounded-md" value={editData.receivedDate} onChange={(e) => setEditData({...editData, receivedDate: e.target.value})} />
                </div>
                <div className="col-span-2 space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Pengirim</Label>
                  <Input required className="h-9 rounded-md" value={editData.sender} onChange={(e) => setEditData({...editData, sender: e.target.value})} />
                </div>
                <div className="col-span-2 space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Perihal</Label>
                  <Input required className="h-9 rounded-md" value={editData.subject} onChange={(e) => setEditData({...editData, subject: e.target.value})} />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Klasifikasi</Label>
                  <Select value={editData.classification} onValueChange={(v) => setEditData({...editData, classification: v})}>
                    <SelectTrigger className="h-9 rounded-md">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {CLASSIFICATION_OPTIONS.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Sifat Surat</Label>
                  <Select value={editData.nature} onValueChange={(v) => setEditData({...editData, nature: v})}>
                    <SelectTrigger className="h-9 rounded-md">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {NATURE_OPTIONS.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="col-span-2 space-y-1.5">
                  <Label className="text-sm font-medium text-slate-700">Ganti Lampiran Surat (PDF/JPG/PNG) - Opsional</Label>
                  <Input
                    type="file"
                    className="h-9 rounded-md text-sm"
                    onChange={(e) => setEditFile(e.target.files?.[0] || null)}
                  />
                  {editData.filePath && !editFile && (
                    <p className="text-xs text-slate-400">File saat ini: {editData.filePath}</p>
                  )}
                </div>
              </div>
            )}
            <DialogFooter className="gap-2">
              <Button type="button" variant="outline" onClick={() => setEditOpen(false)} className="h-9 text-sm font-medium rounded-md">Batal</Button>
              <Button disabled={submitting} type="submit" className="h-9 text-sm font-medium rounded-md">
                {submitting && <Loader2 className="w-4 h-4 animate-spin mr-1.5" />}
                Simpan Perubahan
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteConfirmOpen} onOpenChange={setDeleteConfirmOpen}>
        <DialogContent className="sm:max-w-[420px] rounded-lg">
          <DialogHeader>
            <DialogTitle className="text-lg font-semibold text-slate-900">Konfirmasi Hapus</DialogTitle>
            <DialogDescription className="text-sm text-slate-500">Tindakan ini tidak dapat dibatalkan</DialogDescription>
          </DialogHeader>
          <div className="py-4 space-y-3">
            <p className="text-sm text-slate-700">
              Apakah Anda yakin ingin menghapus surat ini?
            </p>
            {deleteTarget && (
              <div className="p-3 bg-red-50 rounded-lg border border-red-100">
                <p className="text-sm font-medium text-slate-900">{deleteTarget.subject}</p>
                <p className="text-xs text-slate-500 mt-0.5">{deleteTarget.letterNumber} — {deleteTarget.sender}</p>
              </div>
            )}
            <p className="text-xs text-red-600">
              Semua data disposisi terkait juga akan ikut terhapus.
            </p>
          </div>
          <DialogFooter className="gap-2">
            <Button type="button" variant="outline" onClick={() => setDeleteConfirmOpen(false)} className="h-9 text-sm font-medium rounded-md">Batal</Button>
            <Button disabled={submitting} onClick={handleDelete} className="h-9 text-sm font-medium rounded-md bg-red-600 hover:bg-red-700 text-white">
              {submitting && <Loader2 className="w-4 h-4 animate-spin mr-1.5" />}
              Hapus
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
