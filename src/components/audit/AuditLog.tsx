import { useEffect, useState } from "react";
import { useAuth } from "@/src/lib/AuthContext";
import { toast } from "sonner";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Loader2, ShieldAlert, Search, Database } from "lucide-react";
import { format } from "date-fns";
import { id } from "date-fns/locale";

export default function AuditLog() {
  const { token } = useAuth();
  const [logs, setLogs] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [startDate, setStartDate] = useState("");
  const [endDate, setEndDate] = useState("");
  const [page, setPage] = useState(1);
  const pageSize = 15;
  const [backingUp, setBackingUp] = useState(false);

  useEffect(() => {
    fetchLogs();
  }, []);

  const fetchLogs = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (startDate) params.append("startDate", startDate);
      if (endDate) params.append("endDate", endDate);

      const res = await fetch(`/api/dashboard/activity?${params.toString()}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) return;
      const data = await res.json();
      setLogs(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const handleFilter = () => {
    setPage(1);
    fetchLogs();
  };

  const handleBackup = async () => {
    setBackingUp(true);
    try {
      const res = await fetch("/api/dashboard/backup", {
        method: "POST",
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) {
        toast.error("Gagal membuat backup");
        return;
      }
      const data = await res.json();
      toast.success(`Backup berhasil: ${data.filename}`);
    } catch (err) {
      console.error(err);
      toast.error("Gagal membuat backup");
    } finally {
      setBackingUp(false);
    }
  };

  const totalPages = Math.ceil(logs.length / pageSize);
  const paginatedLogs = logs.slice((page - 1) * pageSize, page * pageSize);

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Audit Log</h1>
          <p className="text-sm text-slate-500 mt-0.5">
            Riwayat aktivitas pengguna dalam sistem.
          </p>
        </div>
        <Button
          onClick={handleBackup}
          disabled={backingUp}
          className="h-9 text-sm font-medium rounded-md"
        >
          {backingUp ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : <Database className="w-4 h-4 mr-2" />}
          Backup Database
        </Button>
      </div>

      {/* Filter */}
      <Card className="border border-slate-200 shadow-sm rounded-lg bg-white">
        <CardContent className="p-4">
          <div className="flex flex-col sm:flex-row gap-4 items-end">
            <div className="space-y-1.5 flex-1">
              <Label className="text-sm font-medium text-slate-700">Dari Tanggal</Label>
              <Input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                className="h-9 rounded-md"
              />
            </div>
            <div className="space-y-1.5 flex-1">
              <Label className="text-sm font-medium text-slate-700">Sampai Tanggal</Label>
              <Input
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                className="h-9 rounded-md"
              />
            </div>
            <Button
              onClick={handleFilter}
              className="h-9 px-4 text-sm font-medium rounded-md"
            >
              <Search className="w-4 h-4 mr-2" />
              Filter
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Table */}
      <Card className="border border-slate-200 shadow-sm rounded-lg">
        <CardHeader className="border-b border-slate-100 px-4 py-3">
          <CardTitle className="text-sm font-medium text-slate-700 flex items-center gap-2">
            <ShieldAlert className="w-4 h-4 text-slate-500" />
            Log Aktivitas ({logs.length})
          </CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow className="border-b border-slate-100">
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                    User
                  </TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                    Action
                  </TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                    Entity
                  </TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                    Details
                  </TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                    Tanggal
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {loading ? (
                  <TableRow>
                    <TableCell colSpan={5} className="h-48 text-center">
                      <div className="flex items-center justify-center gap-2">
                        <Loader2 className="w-4 h-4 animate-spin text-slate-400" />
                        <span className="text-sm text-slate-500">Memuat data...</span>
                      </div>
                    </TableCell>
                  </TableRow>
                ) : paginatedLogs.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={5} className="h-48 text-center">
                      <div className="flex flex-col items-center justify-center gap-2 py-8">
                        <ShieldAlert className="w-10 h-10 text-slate-300" />
                        <p className="text-sm font-medium text-slate-700">
                          Belum ada log aktivitas
                        </p>
                      </div>
                    </TableCell>
                  </TableRow>
                ) : (
                  paginatedLogs.map((log) => (
                    <TableRow
                      key={log.id}
                      className="hover:bg-slate-50 border-b border-slate-50"
                    >
                      <TableCell className="px-4 py-3 text-sm font-medium text-slate-900">
                        {log.user?.name || "-"}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm text-slate-700">
                        {log.action}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm text-slate-600">
                        {log.entity}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm text-slate-500 max-w-[250px] truncate">
                        {log.details || "-"}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm text-slate-500">
                        {format(new Date(log.createdAt), "dd MMM yyyy HH:mm", {
                          locale: id,
                        })}
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
              Menampilkan{" "}
              {logs.length === 0 ? 0 : (page - 1) * pageSize + 1}–
              {Math.min(page * pageSize, logs.length)} dari {logs.length} log
            </p>
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                className="h-8 text-xs font-medium rounded-md"
                disabled={page <= 1}
                onClick={() => setPage((p) => p - 1)}
              >
                Sebelumnya
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="h-8 text-xs font-medium rounded-md"
                disabled={page >= totalPages}
                onClick={() => setPage((p) => p + 1)}
              >
                Selanjutnya
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
