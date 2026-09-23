import { useEffect, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { useAuth } from "@/src/lib/AuthContext";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Search,
  Download,
  ExternalLink,
  FileText,
  Loader2,
  Archive,
} from "lucide-react";
import { format } from "date-fns";
import { id } from "date-fns/locale";

export default function DigitalArchive() {
  const { token } = useAuth();
  const [searchParams] = useSearchParams();
  const [archives, setArchives] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState(searchParams.get("search") || "");
  const [page, setPage] = useState(1);
  const pageSize = 10;

  useEffect(() => {
    fetchArchives();
  }, []);

  const fetchArchives = async () => {
    try {
      const currentToken = localStorage.getItem('token');
      if (!currentToken) return;
      const res = await fetch("/api/dashboard/archives", {
        headers: { Authorization: `Bearer ${currentToken}` },
      });
      if (!res.ok) {
        setLoading(false);
        return;
      }
      const contentType = res.headers.get("content-type");
      if (!contentType || !contentType.includes("application/json")) {
        setLoading(false);
        return;
      }
      const data = await res.json();
      setArchives(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const filteredArchives = archives.filter(
    (a) =>
      a.subject?.toLowerCase().includes(search.toLowerCase()) ||
      a.letterNumber?.toLowerCase().includes(search.toLowerCase()) ||
      a.origin?.toLowerCase().includes(search.toLowerCase()) ||
      a.agendaNumber?.toLowerCase().includes(search.toLowerCase())
  );

  const totalPages = Math.ceil(filteredArchives.length / pageSize);
  const paginatedArchives = filteredArchives.slice(
    (page - 1) * pageSize,
    page * pageSize
  );

  const getFileName = (filePath: string) => {
    return filePath.split("/").pop() || filePath;
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Arsip Digital</h1>
          <p className="text-sm text-slate-500 mt-0.5">
            Semua surat masuk dan keluar yang terdaftar dalam sistem.
          </p>
        </div>
      </div>

      <Card className="border border-slate-200 shadow-sm rounded-lg">
        <CardHeader className="border-b border-slate-100 px-4 py-3">
          <div className="flex flex-col sm:flex-row gap-3 justify-between items-center">
            <CardTitle className="text-sm font-medium text-slate-700 flex items-center gap-2">
              <Archive className="w-4 h-4 text-slate-500" />
              Daftar File ({filteredArchives.length})
            </CardTitle>
            <div className="relative w-full max-w-sm">
              <Search className="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
              <Input
                placeholder="Cari file atau perihal..."
                className="pl-9 h-9 text-sm rounded-md"
                value={search}
                onChange={(e) => {
                  setSearch(e.target.value);
                  setPage(1);
                }}
              />
            </div>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow className="border-b border-slate-100">
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                    Tipe
                  </TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                    Nomor Surat
                  </TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                    Perihal
                  </TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                    File
                  </TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">
                    Tanggal Upload
                  </TableHead>
                  <TableHead className="text-right text-xs font-medium text-slate-500 px-4 py-3">
                    Aksi
                  </TableHead>
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
                ) : paginatedArchives.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="h-48 text-center">
                      <div className="flex flex-col items-center justify-center gap-2 py-8">
                        <FileText className="w-10 h-10 text-slate-300" />
                        <p className="text-sm font-medium text-slate-700">
                          Belum ada arsip digital
                        </p>
                        <p className="text-xs text-slate-400">
                          File yang diunggah pada surat masuk/keluar akan muncul di sini
                        </p>
                      </div>
                    </TableCell>
                  </TableRow>
                ) : (
                  paginatedArchives.map((archive) => (
                    <TableRow
                      key={`${archive.type}-${archive.id}`}
                      className="hover:bg-slate-50 border-b border-slate-50"
                    >
                      <TableCell className="px-4 py-3">
                        <Badge
                          className={
                            archive.type === "masuk"
                              ? "text-xs px-2 py-0.5 rounded bg-blue-50 text-blue-700 border-none"
                              : "text-xs px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border-none"
                          }
                        >
                          {archive.type === "masuk" ? "Masuk" : "Keluar"}
                        </Badge>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm font-medium text-slate-900">
                        {archive.letterNumber}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm text-slate-700 max-w-[200px] truncate">
                        {archive.subject}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm text-slate-600">
                        {archive.filePath ? (
                          <span className="text-blue-600">{getFileName(archive.filePath)}</span>
                        ) : (
                          <span className="text-slate-400 italic">Belum ada file</span>
                        )}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-sm text-slate-500">
                        {format(new Date(archive.createdAt), "dd MMM yyyy", {
                          locale: id,
                        })}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-right">
                        {archive.filePath ? (
                          <div className="flex items-center justify-end gap-1">
                            <a
                              href={archive.filePath}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="h-8 w-8 inline-flex items-center justify-center rounded-md text-slate-500 hover:text-blue-600 hover:bg-blue-50"
                            >
                              <ExternalLink className="w-4 h-4" />
                            </a>
                            <a
                              href={archive.filePath}
                              download
                              className="h-8 w-8 inline-flex items-center justify-center rounded-md text-slate-500 hover:text-green-600 hover:bg-green-50"
                            >
                              <Download className="w-4 h-4" />
                            </a>
                          </div>
                        ) : (
                          <span className="text-xs text-slate-400">—</span>
                        )}
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
              {filteredArchives.length === 0
                ? 0
                : (page - 1) * pageSize + 1}
              –{Math.min(page * pageSize, filteredArchives.length)} dari{" "}
              {filteredArchives.length} file
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
