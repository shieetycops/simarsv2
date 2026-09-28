import type React from "react";
import { useEffect, useState } from "react";
import { useAuth } from "@/src/lib/AuthContext";
import { toast } from "sonner";

// Ambil pesan error dari API (mis. "Password minimal 8 karakter.") supaya
// pengguna tahu penyebabnya, bukan hanya "Gagal menyimpan data".
// Penanda pilihan "tanpa atasan langsung" pada dropdown Atasan Langsung: Radix
// Select menolak value string kosong, sedangkan server menerima null untuk
// melepas hierarki (users.supervisor_id = NULL).
const NO_SUPERVISOR = "NO_SUPERVISOR";


const apiErrorMessage = async (res: Response, fallback: string): Promise<string> => {
  const data = (await res.json().catch(() => null)) as { message?: string } | null;
  return data?.message || fallback;
};

import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import {
  UserPlus,
  ShieldCheck,
  UserCog,
  MoreVertical,
  Shield,
  CheckCircle2,
  XCircle,
  Trash2,
  Ban,
  AlertTriangle,
  Edit,
  Loader2,
} from "lucide-react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuGroup,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

export default function UserManagement() {
  const { token, user: currentUser } = useAuth();
  const [users, setUsers] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  // Dialog states
  const [addOpen, setAddOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [resetOpen, setResetOpen] = useState(false);
  const [deactivateOpen, setDeactivateOpen] = useState(false);
  const [permanentDeleteOpen, setPermanentDeleteOpen] = useState(false);

  // Permanent delete precheck state
  const [deleteCheck, setDeleteCheck] = useState<{
    canDelete: boolean;
    isSelf: boolean;
    isLastAdmin: boolean;
    blockingReasons: string[];
    subordinateCount: number;
    willCascadeDelete?: boolean;
    cascadeCounts?: {
      dispositionCount: number;
      notificationCount: number;
      activityLogCount: number;
    };
  } | null>(null);
  const [checkingDelete, setCheckingDelete] = useState(false);
  const [confirmUsername, setConfirmUsername] = useState("");

  // Form states
  const [submitting, setSubmitting] = useState(false);
  const [selectedUser, setSelectedUser] = useState<any>(null);

  // Add user form
  const [addName, setAddName] = useState("");
  const [addUsername, setAddUsername] = useState("");
  const [addPassword, setAddPassword] = useState("");
  const [addRole, setAddRole] = useState("");

  // Edit user form
  const [editName, setEditName] = useState("");
  const [editRole, setEditRole] = useState("");
  const [addWaNumber, setAddWaNumber] = useState("");
  const [editWaNumber, setEditWaNumber] = useState("");
  // Fase 3: atasan langsung (users.supervisor_id) — dasar wewenang disposisi
  // hierarkis di server (Disposition::canDispose) dan sasaran menu WhatsApp untuk
  // Kasubag/Sekretaris/Panitera. Sebelum ini kolomnya tidak bisa diisi dari UI.
  // Radix Select tidak menerima value string kosong, jadi "melepas hierarki"
  // diwakili penanda NO_SUPERVISOR lalu dikirim sebagai null.
  const [addSupervisorId, setAddSupervisorId] = useState("");
  const [editSupervisorId, setEditSupervisorId] = useState("");

  // Reset password form
  const [newPassword, setNewPassword] = useState("");

  useEffect(() => {
    fetchUsers();
  }, []);

  const fetchUsers = async () => {
    try {
      const res = await fetch("/api/users", {
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) return;
      const data = await res.json();
      setUsers(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  // --- Add User ---
  const handleAddUser = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      const res = await fetch("/api/users", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          username: addUsername,
          password: addPassword,
          name: addName,
          role: addRole,
          waNumber: addWaNumber,
          // Fase 3: atasan langsung ikut dikirim saat pembuatan akun supaya
          // hierarki disposisi bisa langsung berfungsi tanpa edit kedua.
          supervisorId: addSupervisorId && addSupervisorId !== NO_SUPERVISOR ? addSupervisorId : null,
        }),
      });
      if (!res.ok) {
        toast.error(await apiErrorMessage(res, "Gagal menyimpan data"));
        return;
      }
      setAddOpen(false);
      setAddName("");
      setAddUsername("");
      setAddPassword("");
      setAddRole("");
      setAddWaNumber("");
      setAddSupervisorId(NO_SUPERVISOR);
      await fetchUsers();
      toast.success("Pengguna berhasil ditambahkan");
    } catch (err) {
      console.error(err);
      toast.error("Gagal menyimpan data");
    } finally {
      setSubmitting(false);
    }
  };

  // --- Edit User ---
  const openEditDialog = (u: any) => {
    setSelectedUser(u);
    setEditName(u.name);
    setEditRole(u.role);
    setEditWaNumber(u.waNumber || "");
    setEditSupervisorId(u.supervisorId || NO_SUPERVISOR);
    setEditOpen(true);
  };

  // Fase 3: kandidat atasan langsung = pengguna AKTIF selain dirinya sendiri
  // (mencegah atasan = diri sendiri, yang ditolak server juga).
  const supervisorOptions = (excludeId?: string) =>
    users.filter((u) => u.isActive && u.id !== excludeId);

  const supervisorLabel = (id?: string | null) => {
    if (!id) return "belum dipetakan";
    const found = users.find((u) => u.id === id);
    return found ? `${found.name} (${roleLabel(found.role)})` : id;
  };

  const handleEditUser = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedUser) return;
    setSubmitting(true);
    try {
      const res = await fetch(`/api/users/${selectedUser.id}`, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          name: editName,
          role: editRole,
          waNumber: editWaNumber,
          // Fase 3: "NO_SUPERVISOR" dikirim sebagai null supaya hierarki bisa
          // DILEPAS (server menolak atasan = diri sendiri).
          supervisorId: editSupervisorId && editSupervisorId !== NO_SUPERVISOR ? editSupervisorId : null,
        }),
      });
      if (!res.ok) {
        toast.error(await apiErrorMessage(res, "Gagal menyimpan data"));
        return;
      }
      setEditOpen(false);
      setSelectedUser(null);
      await fetchUsers();
      toast.success("Data pengguna berhasil diperbarui");
    } catch (err) {
      console.error(err);
      toast.error("Gagal menyimpan data");
    } finally {
      setSubmitting(false);
    }
  };

  // --- Reset Password ---
  const openResetDialog = (u: any) => {
    setSelectedUser(u);
    setNewPassword("");
    setResetOpen(true);
  };

  const handleResetPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedUser) return;
    setSubmitting(true);
    try {
      const res = await fetch(`/api/users/${selectedUser.id}`, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ password: newPassword }),
      });
      if (!res.ok) {
        toast.error(await apiErrorMessage(res, "Gagal menyimpan data"));
        return;
      }
      setResetOpen(false);
      setSelectedUser(null);
      setNewPassword("");
      toast.success("Password berhasil direset");
    } catch (err) {
      console.error(err);
      toast.error("Gagal menyimpan data");
    } finally {
      setSubmitting(false);
    }
  };

  // --- Deactivate User ---
  const openDeactivateDialog = (u: any) => {
    setSelectedUser(u);
    setDeactivateOpen(true);
  };

  const handleDeactivateUser = async () => {
    if (!selectedUser) return;
    setSubmitting(true);
    try {
      const res = await fetch(`/api/users/${selectedUser.id}`, {
        method: "DELETE",
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) {
        toast.error(await apiErrorMessage(res, "Gagal menyimpan data"));
        return;
      }
      setDeactivateOpen(false);
      setSelectedUser(null);
      await fetchUsers();
      toast.success("User berhasil dinonaktifkan");
    } catch (err) {
      console.error(err);
      toast.error("Gagal menyimpan data");
    } finally {
      setSubmitting(false);
    }
  };

  // --- Permanent Delete User ---
  const openPermanentDeleteDialog = async (u: any) => {
    setSelectedUser(u);
    setConfirmUsername("");
    setDeleteCheck(null);
    setPermanentDeleteOpen(true);
    setCheckingDelete(true);
    try {
      const res = await fetch(`/api/users/${u.id}/delete-check`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      if (res.ok) {
        const data = await res.json();
        setDeleteCheck(data);
      } else {
        setDeleteCheck({
          canDelete: false,
          isSelf: false,
          isLastAdmin: false,
          blockingReasons: ["Gagal memeriksa data pengguna. Coba lagi."],
          subordinateCount: 0,
        });
      }
    } catch (err) {
      console.error(err);
      setDeleteCheck({
        canDelete: false,
        isSelf: false,
        isLastAdmin: false,
        blockingReasons: ["Gagal memeriksa data pengguna. Coba lagi."],
        subordinateCount: 0,
      });
    } finally {
      setCheckingDelete(false);
    }
  };

  const handlePermanentDeleteUser = async () => {
    if (!selectedUser || !deleteCheck?.canDelete) return;
    setSubmitting(true);
    try {
      const res = await fetch(`/api/users/${selectedUser.id}/permanent`, {
        method: "DELETE",
        headers: { Authorization: `Bearer ${token}` },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        toast.error(data.message || "Gagal menghapus pengguna");
        return;
      }
      setPermanentDeleteOpen(false);
      setSelectedUser(null);
      setConfirmUsername("");
      setDeleteCheck(null);
      await fetchUsers();
      toast.success("Pengguna berhasil dihapus permanen");
    } catch (err) {
      console.error(err);
      toast.error("Gagal menghapus pengguna");
    } finally {
      setSubmitting(false);
    }
  };

  // --- Activate User ---
  const handleActivateUser = async (u: any) => {
    try {
      const res = await fetch(`/api/users/${u.id}/activate`, {
        method: "PATCH",
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) {
        toast.error("Gagal mengaktifkan user");
        return;
      }
      await fetchUsers();
      toast.success("User berhasil diaktifkan");
    } catch (err) {
      console.error(err);
      toast.error("Gagal mengaktifkan user");
    }
  };

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

  const getRoleBadge = (role: string) => {
    const label = roleLabel(role);
    switch (role) {
      case "ADMIN":
        return <span className="text-xs px-2 py-0.5 rounded bg-red-50 text-red-700">{label}</span>;
      case "PIMPINAN":
        return <span className="text-xs px-2 py-0.5 rounded bg-blue-50 text-blue-700">{label}</span>;
      case "SEKRETARIS":
        return <span className="text-xs px-2 py-0.5 rounded bg-emerald-50 text-emerald-700">{label}</span>;
      case "HAKIM":
        return <span className="text-xs px-2 py-0.5 rounded bg-purple-50 text-purple-700">{label}</span>;
      case "PANITERA":
        return <span className="text-xs px-2 py-0.5 rounded bg-amber-50 text-amber-700">{label}</span>;
      case "PANITERA_MUDA_PERMOHONAN":
      case "PANITERA_MUDA_GUGATAN":
      case "PANITERA_MUDA_HUKUM":
        return <span className="text-xs px-2 py-0.5 rounded bg-orange-50 text-orange-700">{label}</span>;
      case "KEPALA_SUB_PTIP":
      case "KEPALA_SUB_KEPEGAWAIAN":
      case "KEPALA_SUB_UMUM":
        return <span className="text-xs px-2 py-0.5 rounded bg-teal-50 text-teal-700">{label}</span>;
      default:
        return <span className="text-xs px-2 py-0.5 rounded bg-slate-100 text-slate-600">{label}</span>;
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">
            Manajemen Pengguna
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Kelola akses dan akun pengguna sistem.
          </p>
        </div>
        <Button
          onClick={() => setAddOpen(true)}
          className="h-9 text-sm font-medium rounded-md"
        >
          <UserPlus className="w-4 h-4 mr-2" />
          Tambah Pegawai
        </Button>
      </div>

      {/* Stat Cards */}
      <div className="grid gap-4 md:grid-cols-3">
        <Card className="border border-slate-200 shadow-sm rounded-lg">
          <CardContent className="p-5 flex items-center gap-4">
            <div className="w-10 h-10 rounded-md bg-blue-50 flex items-center justify-center text-blue-600">
              <ShieldCheck className="w-5 h-5" />
            </div>
            <div>
              <p className="text-xs text-slate-500">Total Admin</p>
              <p className="text-xl font-semibold text-slate-900">
                {users.filter((u) => u.role === "ADMIN").length}
              </p>
            </div>
          </CardContent>
        </Card>
        <Card className="border border-slate-200 shadow-sm rounded-lg">
          <CardContent className="p-5 flex items-center gap-4">
            <div className="w-10 h-10 rounded-md bg-emerald-50 flex items-center justify-center text-emerald-600">
              <UserCog className="w-5 h-5" />
            </div>
            <div>
              <p className="text-xs text-slate-500">User Aktif</p>
              <p className="text-xl font-semibold text-slate-900">
                {users.filter((u) => u.isActive).length}
              </p>
            </div>
          </CardContent>
        </Card>
        <Card className="border border-slate-200 shadow-sm rounded-lg">
          <CardContent className="p-5 flex items-center gap-4">
            <div className="w-10 h-10 rounded-md bg-slate-50 flex items-center justify-center text-slate-600">
              <Shield className="w-5 h-5" />
            </div>
            <div>
              <p className="text-xs text-slate-500">Role Jabatan</p>
              <p className="text-xl font-semibold text-slate-900">12</p>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Users Table */}
      <Card className="border border-slate-200 shadow-sm rounded-lg">
        <CardHeader className="border-b border-slate-100 px-4 py-3 flex flex-row items-center justify-between">
          <CardTitle className="text-sm font-medium text-slate-700">
            Daftar Akun Pengguna
          </CardTitle>
          <Button
            variant="ghost"
            size="sm"
            className="h-9 text-sm font-medium rounded-md text-slate-600 hover:text-slate-900"
            onClick={() => fetchUsers()}
          >
            Refresh
          </Button>
        </CardHeader>
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow className="border-b border-slate-100">
                <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                  Nama Pegawai
                </TableHead>
                <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                  Username
                </TableHead>
                <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                  Jabatan / Role
                </TableHead>
                <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                  Status
                </TableHead>
                <TableHead className="text-right text-xs font-medium text-slate-500 px-4 py-3">
                  Opsi
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>

              {loading ? (
                <TableRow>
                  <TableCell colSpan={5} className="h-48 text-center">
                    <div className="flex flex-col items-center gap-2">
                      <Loader2 className="w-5 h-5 text-slate-400 animate-spin" />
                      <span className="text-sm text-slate-500">
                        Memuat data...
                      </span>
                    </div>
                  </TableCell>
                </TableRow>
              ) : (
                users.map((u) => (
                  <TableRow
                    key={u.id}
                    className="hover:bg-slate-50 border-b border-slate-50"
                  >
                    <TableCell className="px-4 py-3 text-sm">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-sm font-medium text-slate-600">
                          {u.name.charAt(0)}
                        </div>
                        <span className="font-medium text-slate-900">
                          {u.name}
                        </span>
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3 text-sm text-slate-600">
                      {u.username}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-sm">
                      {getRoleBadge(u.role)}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-sm">
                      {u.isActive ? (
                        <div className="flex items-center gap-1.5 text-sm text-green-600">
                          <CheckCircle2 className="w-3.5 h-3.5" />
                          <span>Aktif</span>
                        </div>
                      ) : (
                        <div className="flex items-center gap-1.5 text-sm text-red-500">
                          <XCircle className="w-3.5 h-3.5" />
                          <span>Non-aktif</span>
                        </div>
                      )}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-sm text-right">
                      <DropdownMenu>
                        <DropdownMenuTrigger className="h-8 w-8 p-0 text-slate-400 hover:text-slate-600 inline-flex items-center justify-center rounded-md hover:bg-slate-100">
                          <MoreVertical className="h-4 w-4" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                          align="end"
                          className="w-48 rounded-lg border border-slate-200 p-1"
                        >
                          <DropdownMenuGroup>
                            <DropdownMenuLabel className="text-xs text-slate-500 px-2 py-1.5">
                              Konfigurasi Akun
                            </DropdownMenuLabel>
                            <DropdownMenuItem
                              onClick={() => openEditDialog(u)}
                              className="gap-2 text-sm py-2 rounded-md cursor-pointer"
                            >
                              <Edit className="w-4 h-4" /> Ubah Profil & Role
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              onClick={() => openResetDialog(u)}
                              className="gap-2 text-sm py-2 rounded-md cursor-pointer"
                            >
                              <Shield className="w-4 h-4" /> Reset Kata Sandi
                            </DropdownMenuItem>

                            <DropdownMenuSeparator />
                            {u.isActive ? (
                              <DropdownMenuItem
                                onClick={() => openDeactivateDialog(u)}
                                className="gap-2 text-sm py-2 rounded-md text-red-600 focus:text-red-600 focus:bg-red-50 cursor-pointer"
                              >
                                <Ban className="w-4 h-4" /> Nonaktifkan User
                              </DropdownMenuItem>
                            ) : (
                              <DropdownMenuItem
                                onClick={() => handleActivateUser(u)}
                                className="gap-2 text-sm py-2 rounded-md text-green-600 focus:text-green-600 focus:bg-green-50 cursor-pointer"
                              >
                                <CheckCircle2 className="w-4 h-4" /> Aktifkan User
                              </DropdownMenuItem>
                            )}
                            <DropdownMenuItem
                              onClick={() => openPermanentDeleteDialog(u)}
                              disabled={u.id === currentUser?.id}
                              className="gap-2 text-sm py-2 rounded-md text-red-700 focus:text-red-700 focus:bg-red-50 cursor-pointer data-[disabled]:opacity-40 data-[disabled]:cursor-not-allowed"
                            >
                              <Trash2 className="w-4 h-4" /> Hapus Permanen
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
        </CardContent>
      </Card>

      {/* Add User Dialog */}
      <Dialog open={addOpen} onOpenChange={setAddOpen}>
        <DialogContent className="rounded-lg">
          <DialogHeader>
            <DialogTitle className="text-lg font-semibold text-slate-900">
              Tambah Pegawai Baru
            </DialogTitle>
            <DialogDescription className="text-sm text-slate-500">
              Buat akun baru untuk pegawai yang akan mengakses sistem SIMARS.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleAddUser} className="space-y-4">
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">
                Nama Lengkap
              </Label>
              <Input
                value={addName}
                onChange={(e) => setAddName(e.target.value)}
                placeholder="Masukkan nama lengkap"
                required
                className="h-9 rounded-md"
              />
            </div>
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">
                Username
              </Label>
              <Input
                value={addUsername}
                onChange={(e) => setAddUsername(e.target.value)}
                placeholder="Masukkan username"
                required
                className="h-9 rounded-md"
              />
            </div>
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">
                Password
              </Label>
              <Input
                type="password"
                value={addPassword}
                onChange={(e) => setAddPassword(e.target.value)}
                placeholder="Masukkan password"
                required
                className="h-9 rounded-md"
              />
            </div>

            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">
                Role / Jabatan
              </Label>
              <Select value={addRole} onValueChange={setAddRole} required>
                <SelectTrigger className="h-9 rounded-md">
                  <SelectValue placeholder="Pilih role" />
                </SelectTrigger>
                <SelectContent className="rounded-md">
                  <SelectItem value="ADMIN">Admin</SelectItem>
                  <SelectItem value="PIMPINAN">Pimpinan / Ketua</SelectItem>
                  <SelectItem value="WAKIL_KETUA">Wakil Ketua</SelectItem>
                  <SelectItem value="HAKIM">Hakim</SelectItem>
                  <SelectItem value="PANITERA">Panitera</SelectItem>
                  <SelectItem value="SEKRETARIS">Sekretaris</SelectItem>
                  <SelectItem value="PANITERA_MUDA_PERMOHONAN">Panitera Muda Permohonan</SelectItem>
                  <SelectItem value="PANITERA_MUDA_GUGATAN">Panitera Muda Gugatan</SelectItem>
                  <SelectItem value="PANITERA_MUDA_HUKUM">Panitera Muda Hukum</SelectItem>
                  <SelectItem value="KEPALA_SUB_PTIP">Kepala Sub Bagian PTIP</SelectItem>
                  <SelectItem value="KEPALA_SUB_KEPEGAWAIAN">Kepala Sub Bagian Kepegawaian</SelectItem>
                  <SelectItem value="KEPALA_SUB_UMUM">Kepala Sub Bagian Umum & Keuangan</SelectItem>
                  <SelectItem value="STAFF">Staff</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">
                Nomor WhatsApp
              </Label>
              <Input
                value={addWaNumber}
                onChange={(e) => setAddWaNumber(e.target.value)}
                placeholder="08xxxxxxxxxx"
                className="h-9 rounded-md"
              />
              <p className="text-xs text-slate-400">Untuk notifikasi disposisi via bot WhatsApp (opsional).</p>
            </div>
            {/* Fase 3: atasan langsung = dasar wewenang disposisi hierarkis
                (Disposition::canDispose) dan sasaran menu WhatsApp Kasubag/
                Sekretaris/Panitera. Tanpa pemetaan ini, server MENOLAK disposisi
                ke bawahan dan bot tidak punya kandidat pegawai. */}
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">Atasan Langsung</Label>
              <Select value={addSupervisorId || NO_SUPERVISOR} onValueChange={setAddSupervisorId}>
                <SelectTrigger className="h-9 rounded-md">
                  <SelectValue placeholder="Pilih atasan langsung" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NO_SUPERVISOR}>— Tanpa atasan langsung —</SelectItem>
                  {supervisorOptions().map((u) => (
                    <SelectItem key={u.id} value={u.id}>{u.name} ({roleLabel(u.role)})</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-slate-400">
                Menentukan siapa yang boleh mendisposisi pegawai ini dan siapa saja yang muncul
                di menu WhatsApp atasannya (opsional).
              </p>
            </div>
            <DialogFooter className="pt-4">
              <Button
                type="button"
                variant="ghost"
                onClick={() => setAddOpen(false)}
                className="h-9 text-sm font-medium rounded-md"
              >
                Batal
              </Button>
              <Button
                type="submit"
                disabled={submitting || !addName || !addUsername || !addPassword || !addRole}
                className="h-9 text-sm font-medium rounded-md"
              >
                {submitting && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
                Simpan
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Edit User Dialog */}
      <Dialog open={editOpen} onOpenChange={setEditOpen}>
        <DialogContent className="rounded-lg">
          <DialogHeader>
            <DialogTitle className="text-lg font-semibold text-slate-900">
              Ubah Profil & Role
            </DialogTitle>
            <DialogDescription className="text-sm text-slate-500">
              Perbarui nama dan jabatan untuk{" "}
              <span className="font-medium">{selectedUser?.name}</span>.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleEditUser} className="space-y-4">
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">
                Nama Lengkap
              </Label>
              <Input
                value={editName}
                onChange={(e) => setEditName(e.target.value)}
                placeholder="Masukkan nama lengkap"
                required
                className="h-9 rounded-md"
              />
            </div>
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">
                Role / Jabatan
              </Label>
              <Select value={editRole} onValueChange={setEditRole} required>
                <SelectTrigger className="h-9 rounded-md">
                  <SelectValue placeholder="Pilih role" />
                </SelectTrigger>
                <SelectContent className="rounded-md">
                  <SelectItem value="ADMIN">Admin</SelectItem>
                  <SelectItem value="PIMPINAN">Pimpinan / Ketua</SelectItem>
                  <SelectItem value="WAKIL_KETUA">Wakil Ketua</SelectItem>
                  <SelectItem value="HAKIM">Hakim</SelectItem>
                  <SelectItem value="PANITERA">Panitera</SelectItem>
                  <SelectItem value="SEKRETARIS">Sekretaris</SelectItem>
                  <SelectItem value="PANITERA_MUDA_PERMOHONAN">Panitera Muda Permohonan</SelectItem>
                  <SelectItem value="PANITERA_MUDA_GUGATAN">Panitera Muda Gugatan</SelectItem>
                  <SelectItem value="PANITERA_MUDA_HUKUM">Panitera Muda Hukum</SelectItem>
                  <SelectItem value="KEPALA_SUB_PTIP">Kepala Sub Bagian PTIP</SelectItem>
                  <SelectItem value="KEPALA_SUB_KEPEGAWAIAN">Kepala Sub Bagian Kepegawaian</SelectItem>
                  <SelectItem value="KEPALA_SUB_UMUM">Kepala Sub Bagian Umum & Keuangan</SelectItem>
                  <SelectItem value="STAFF">Staff</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">
                Nomor WhatsApp
              </Label>
              <Input
                value={editWaNumber}
                onChange={(e) => setEditWaNumber(e.target.value)}
                placeholder="08xxxxxxxxxx"
                className="h-9 rounded-md"
              />
              <p className="text-xs text-slate-400">Untuk notifikasi disposisi via bot WhatsApp (opsional).</p>
            </div>
            {/* Fase 3: atasan langsung. Pilihan "tanpa atasan langsung" melepas
                hierarki (dikirim sebagai null) — server menolak atasan = diri sendiri. */}
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">Atasan Langsung</Label>
              <Select value={editSupervisorId || NO_SUPERVISOR} onValueChange={setEditSupervisorId}>
                <SelectTrigger className="h-9 rounded-md">
                  <SelectValue placeholder="Pilih atasan langsung" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NO_SUPERVISOR}>— Tanpa atasan langsung —</SelectItem>
                  {supervisorOptions(selectedUser?.id).map((u) => (
                    <SelectItem key={u.id} value={u.id}>{u.name} ({roleLabel(u.role)})</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-slate-400">
                Saat ini: {supervisorLabel(selectedUser?.supervisorId)}.
              </p>
            </div>
            <DialogFooter className="pt-4">
              <Button
                type="button"
                variant="ghost"
                onClick={() => setEditOpen(false)}
                className="h-9 text-sm font-medium rounded-md"
              >
                Batal
              </Button>
              <Button
                type="submit"
                disabled={submitting || !editName || !editRole}
                className="h-9 text-sm font-medium rounded-md"
              >
                {submitting && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
                Simpan Perubahan
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Reset Password Dialog */}
      <Dialog open={resetOpen} onOpenChange={setResetOpen}>
        <DialogContent className="rounded-lg">
          <DialogHeader>
            <DialogTitle className="text-lg font-semibold text-slate-900">
              Reset Kata Sandi
            </DialogTitle>
            <DialogDescription className="text-sm text-slate-500">
              Atur ulang password untuk{" "}
              <span className="font-medium">{selectedUser?.name}</span>.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleResetPassword} className="space-y-4">
            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-slate-700">
                Password Baru
              </Label>
              <Input
                type="password"
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                placeholder="Masukkan password baru"
                required
                className="h-9 rounded-md"
              />
            </div>
            <DialogFooter className="pt-4">
              <Button
                type="button"
                variant="ghost"
                onClick={() => setResetOpen(false)}
                className="h-9 text-sm font-medium rounded-md"
              >
                Batal
              </Button>
              <Button
                type="submit"
                disabled={submitting || !newPassword}
                className="h-9 text-sm font-medium rounded-md"
              >
                {submitting && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
                Reset Password
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Deactivate User Dialog */}
      <Dialog open={deactivateOpen} onOpenChange={setDeactivateOpen}>
        <DialogContent className="rounded-lg">
          <DialogHeader>
            <DialogTitle className="text-lg font-semibold text-slate-900">
              Nonaktifkan User
            </DialogTitle>
            <DialogDescription className="text-sm text-slate-500">
              Apakah Anda yakin ingin menonaktifkan akun{" "}
              <span className="font-medium">{selectedUser?.name}</span>? User
              tidak akan bisa login setelah dinonaktifkan.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="pt-4">
            <Button
              type="button"
              variant="ghost"
              onClick={() => setDeactivateOpen(false)}
              className="h-9 text-sm font-medium rounded-md"
            >
              Batal
            </Button>
            <Button
              type="button"
              onClick={handleDeactivateUser}
              disabled={submitting}
              className="h-9 text-sm font-medium rounded-md bg-red-600 hover:bg-red-700 text-white"
            >
              {submitting && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
              Ya, Nonaktifkan
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Permanent Delete User Dialog */}
      <Dialog
        open={permanentDeleteOpen}
        onOpenChange={(open) => {
          setPermanentDeleteOpen(open);
          if (!open) {
            setDeleteCheck(null);
            setConfirmUsername("");
          }
        }}
      >
        <DialogContent className="rounded-lg">
          <DialogHeader>
            <DialogTitle className="text-lg font-semibold text-red-700 flex items-center gap-2">
              <AlertTriangle className="w-5 h-5" /> Hapus Pengguna Permanen
            </DialogTitle>
            <DialogDescription className="text-sm text-slate-500">
              Menghapus akun{" "}
              <span className="font-medium">{selectedUser?.name}</span> secara
              permanen dari sistem.
            </DialogDescription>
          </DialogHeader>

          {checkingDelete ? (
            <div className="flex items-center gap-2 text-sm text-slate-500 py-4">
              <Loader2 className="w-4 h-4 animate-spin" />
              Memeriksa data terkait pengguna...
            </div>
          ) : deleteCheck && !deleteCheck.canDelete ? (
            <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
              {deleteCheck.isSelf ? (
                <p>Anda tidak dapat menghapus akun Anda sendiri.</p>
              ) : deleteCheck.isLastAdmin ? (
                <p>
                  Pengguna ini adalah satu-satunya Admin aktif di sistem dan
                  tidak dapat dihapus.
                </p>
              ) : (
                <>
                  <p className="font-medium mb-1">
                    Pengguna ini masih memiliki data terkait:
                  </p>
                  <ul className="list-disc list-inside space-y-0.5">
                    {deleteCheck.blockingReasons.map((reason, i) => (
                      <li key={i}>{reason}</li>
                    ))}
                  </ul>
                  <p className="mt-2">
                    Data ini dipertahankan untuk keperluan audit dan riwayat
                    surat-menyurat. Gunakan{" "}
                    <span className="font-medium">Nonaktifkan</span> agar
                    akun tidak bisa login tanpa kehilangan riwayat.
                  </p>
                </>
              )}
            </div>
          ) : deleteCheck?.canDelete ? (
            <div className="space-y-4">
              <div className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                {deleteCheck.willCascadeDelete ? (
                  <>
                    Menghapus user ini akan{" "}
                    <span className="font-semibold">JUGA</span> menghapus permanen:{" "}
                    {deleteCheck.cascadeCounts?.dispositionCount ?? 0} riwayat disposisi
                    surat, {deleteCheck.cascadeCounts?.notificationCount ?? 0} notifikasi,{" "}
                    {deleteCheck.cascadeCounts?.activityLogCount ?? 0} log aktivitas.
                    Tindakan ini{" "}
                    <span className="font-semibold">tidak dapat dibatalkan</span>.
                  </>
                ) : (
                  <>
                    Semua data akun ini akan dihapus permanen dari sistem.
                    Tindakan ini <span className="font-semibold">tidak dapat dibatalkan</span>.
                  </>
                )}
                {deleteCheck.subordinateCount > 0 && (
                  <p className="mt-1">
                    Catatan: {deleteCheck.subordinateCount} pegawai bawahan
                    akan kehilangan atasan langsung dan perlu diatur ulang.
                  </p>
                )}
              </div>
              <div className="space-y-1.5">
                <Label className="text-sm font-medium text-slate-700">
                  Ketik{" "}
                  <span className="font-mono text-slate-900">
                    {selectedUser?.username}
                  </span>{" "}
                  untuk konfirmasi
                </Label>
                <Input
                  value={confirmUsername}
                  onChange={(e) => setConfirmUsername(e.target.value)}
                  placeholder={selectedUser?.username}
                  className="h-9 rounded-md"
                  autoComplete="off"
                />
              </div>
            </div>
          ) : null}

          <DialogFooter className="pt-4">
            <Button
              type="button"
              variant="ghost"
              onClick={() => setPermanentDeleteOpen(false)}
              className="h-9 text-sm font-medium rounded-md"
            >
              Batal
            </Button>
            {deleteCheck && !deleteCheck.canDelete && (
              <Button
                type="button"
                onClick={() => {
                  setPermanentDeleteOpen(false);
                  openDeactivateDialog(selectedUser);
                }}
                className="h-9 text-sm font-medium rounded-md"
              >
                Nonaktifkan Saja
              </Button>
            )}
            {deleteCheck?.canDelete && (
              <Button
                type="button"
                onClick={handlePermanentDeleteUser}
                disabled={submitting || confirmUsername !== selectedUser?.username}
                className="h-9 text-sm font-medium rounded-md bg-red-700 hover:bg-red-800 text-white"
              >
                {submitting && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
                Ya, Hapus Permanen
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}