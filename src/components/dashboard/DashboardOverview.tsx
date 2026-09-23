import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/src/lib/AuthContext";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { 
  MailOpen, 
  Send, 
  GitPullRequest, 
  Inbox,
  TrendingUp,
  Clock,
  Activity,
  CheckCircle2,
  Loader2 as ProcessIcon,
  Users,
  AlarmClock
} from "lucide-react";
import { 
  BarChart, 
  Bar, 
  XAxis, 
  YAxis, 
  CartesianGrid, 
  Tooltip, 
  ResponsiveContainer
} from "recharts";
import { Skeleton } from "@/components/ui/skeleton";
import { format } from "date-fns";
import { id } from "date-fns/locale";

export default function DashboardOverview() {
  const { token } = useAuth();
  const navigate = useNavigate();
  const [stats, setStats] = useState<any>(null);
  const [myPending, setMyPending] = useState<any[]>([]);
  const [activities, setActivities] = useState<any[]>([]);
  const [chartData, setChartData] = useState<{ name: string; masuk: number; keluar: number }[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      try {
        const [statsRes, activityRes, chartRes, pendingRes] = await Promise.all([
          fetch("/api/dashboard/stats", { headers: { Authorization: `Bearer ${token}` } }),
          fetch("/api/dashboard/activity", { headers: { Authorization: `Bearer ${token}` } }),
          fetch("/api/dashboard/chart", { headers: { Authorization: `Bearer ${token}` } }),
          fetch("/api/dashboard/my-pending", { headers: { Authorization: `Bearer ${token}` } })
        ]);
        if (statsRes.ok) setStats(await statsRes.json());
        if (activityRes.ok) {
          const actData = await activityRes.json();
          setActivities(Array.isArray(actData) ? actData : []);
        }
        if (chartRes.ok) {
          const chartDataRes = await chartRes.json();
          if (Array.isArray(chartDataRes)) {
            setChartData(chartDataRes.map((item: any) => ({
              name: item.month,
              masuk: item.masuk,
              keluar: item.keluar
            })));
          }
        }
        if (pendingRes.ok) {
          const pendingData = await pendingRes.json();
          setMyPending(Array.isArray(pendingData) ? pendingData : []);
        }
      } catch (err) {
        console.error(err);
      } finally {
        setLoading(false);
      }
    };
    fetchData();
  }, [token]);

  if (loading) return <DashboardSkeleton />;

  return (
    <div className="space-y-6">
      {/* Stat Cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard title="Surat Masuk" value={stats?.incoming || 0} subtitle={`+${stats?.incomingToday || 0} hari ini`} icon={MailOpen} color="text-blue-600" bg="bg-blue-50" />
        <StatCard title="Surat Keluar" value={stats?.outgoing || 0} subtitle={`+${stats?.outgoingToday || 0} hari ini`} icon={Send} color="text-emerald-600" bg="bg-emerald-50" />
        <StatCard title="Disposisi Pending" value={stats?.pending || 0} icon={GitPullRequest} color="text-amber-600" bg="bg-amber-50" />
        <StatCard title="Belum Disposisi" value={stats?.notDisposed || 0} icon={Inbox} color="text-rose-600" bg="bg-rose-50" />
      </div>

      {/* Disposition Breakdown */}
      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center">
                <Clock className="w-5 h-5 text-amber-600" />
              </div>
              <div>
                <p className="text-sm text-slate-500">Pending</p>
                <p className="text-xl font-semibold text-slate-900">{stats?.pending || 0}</p>
              </div>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                <ProcessIcon className="w-5 h-5 text-blue-600" />
              </div>
              <div>
                <p className="text-sm text-slate-500">Diproses</p>
                <p className="text-xl font-semibold text-slate-900">{stats?.inProcess || 0}</p>
              </div>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-green-50 flex items-center justify-center">
                <CheckCircle2 className="w-5 h-5 text-green-600" />
              </div>
              <div>
                <p className="text-sm text-slate-500">Selesai</p>
                <p className="text-xl font-semibold text-slate-900">{stats?.completed || 0}</p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Disposisi Menunggu Anda */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-sm font-medium text-slate-700 flex items-center gap-2">
            <AlarmClock className="w-4 h-4 text-amber-500" />
            Disposisi Menunggu Anda
          </CardTitle>
        </CardHeader>
        <CardContent>
          {myPending.length === 0 ? (
            <p className="text-sm text-slate-400 text-center py-6">Tidak ada disposisi yang menunggu tindak lanjut</p>
          ) : (
            <div className="space-y-2 mt-2">
              {myPending.map((d) => (
                <button
                  key={d.id}
                  onClick={() =>
                    navigate("/disposisi", {
                      state: {
                        openLetterDetail: {
                          subject: d.letterSubject,
                          sender: d.letterSender,
                          letterNumber: d.letterNumber,
                        },
                      },
                    })
                  }
                  className="w-full flex items-center gap-3 p-3 rounded-lg border border-slate-100 hover:border-amber-200 hover:bg-amber-50/40 transition-colors text-left"
                >
                  <div className={"w-9 h-9 rounded-lg flex items-center justify-center shrink-0 " + (d.status === "PROSES" ? "bg-blue-50" : "bg-amber-50")}>
                    <Clock className={"w-4 h-4 " + (d.status === "PROSES" ? "text-blue-600" : "text-amber-600")} />
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium text-slate-900 truncate">{d.letterSubject}</p>
                    <p className="text-xs text-slate-500 truncate">Dari: {d.letterSender} - {d.letterNumber}</p>
                  </div>
                  {d.deadline && (
                    <div className="text-right shrink-0">
                      <p className="text-[11px] text-slate-400">Deadline</p>
                      <p className="text-xs font-medium text-slate-700">{format(new Date(d.deadline), "dd MMM yyyy", { locale: id })}</p>
                    </div>
                  )}
                </button>
              ))}
            </div>
          )}
        </CardContent>
      </Card>


      {/* Chart + Activity */}
      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-slate-700 flex items-center gap-2">
              <TrendingUp className="w-4 h-4 text-slate-400" />
              Volume Surat Bulanan
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[280px] mt-4">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={chartData}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" stroke="currentColor" opacity={0.15} />
                  <XAxis dataKey="name" fontSize={12} tickLine={false} axisLine={false} tick={{fill: '#64748b'}} />
                  <YAxis fontSize={12} tickLine={false} axisLine={false} tick={{fill: '#64748b'}} />
                  <Tooltip 
                    cursor={{fill: 'rgba(100,116,139,0.1)'}} 
                    contentStyle={{
                      borderRadius: '8px', 
                      border: '1px solid hsl(var(--border))', 
                      fontSize: '12px',
                      backgroundColor: 'hsl(var(--card))',
                      color: 'hsl(var(--card-foreground))',
                    }}
                    labelStyle={{color: 'hsl(var(--muted-foreground))'}}
                    itemStyle={{color: 'hsl(var(--card-foreground))'}}
                  />
                  <Bar dataKey="masuk" fill="#2563eb" radius={[3, 3, 0, 0]} name="Masuk" barSize={16} />
                  <Bar dataKey="keluar" fill="#10b981" radius={[3, 3, 0, 0]} name="Keluar" barSize={16} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-slate-700 flex items-center gap-2">
              <Clock className="w-4 h-4 text-slate-400" />
              Aktivitas Terbaru
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-4 mt-2">
              {activities.length === 0 ? (
                <p className="text-sm text-slate-400 text-center py-8">Belum ada aktivitas</p>
              ) : (
                activities.slice(0, 6).map((log) => (
                  <div key={log.id} className="flex gap-3 items-start">
                    <div className="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center shrink-0 mt-0.5">
                      <Activity className="w-3.5 h-3.5 text-slate-500" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="text-xs text-slate-700 leading-relaxed truncate">
                        <span className="font-medium">{log.user?.name}</span> — {log.details || log.action}
                      </p>
                      <p className="text-[11px] text-slate-400 mt-0.5">
                        {format(new Date(log.createdAt), 'dd MMM, HH:mm', { locale: id })}
                      </p>
                    </div>
                  </div>
                ))
              )}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* User Stats for PIMPINAN/ADMIN */}
      {stats?.userStats && stats.userStats.length > 0 && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-slate-700 flex items-center gap-2">
              <Users className="w-4 h-4 text-slate-400" />
              Kinerja Pengguna
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3">Nama</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3 text-center">Selesai</TableHead>
                  <TableHead className="text-xs font-medium text-slate-500 px-4 py-3 text-center">Pending</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {stats.userStats.map((u: { name: string; completed: number; pending: number }) => (
                  <TableRow key={u.name}>
                    <TableCell className="px-4 py-3 text-sm font-medium text-slate-900">{u.name}</TableCell>
                    <TableCell className="px-4 py-3 text-sm text-center text-green-700 font-medium">{u.completed}</TableCell>
                    <TableCell className="px-4 py-3 text-sm text-center text-amber-700 font-medium">{u.pending}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function StatCard({ title, value, subtitle, icon: Icon, color, bg }: { title: string; value: number; subtitle?: string; icon: any; color: string; bg: string }) {
  return (
    <Card>
      <CardContent className="p-5">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-sm text-slate-500">{title}</p>
            <p className="text-2xl font-semibold text-slate-900 mt-1">{value}</p>
            {subtitle && <p className="text-xs text-slate-400 mt-0.5">{subtitle}</p>}
          </div>
          <div className={`w-10 h-10 rounded-lg ${bg} flex items-center justify-center`}>
            <Icon className={`w-5 h-5 ${color}`} />
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function DashboardSkeleton() {
  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-24 rounded-lg" />)}
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <Skeleton className="lg:col-span-2 h-[360px] rounded-lg" />
        <Skeleton className="h-[360px] rounded-lg" />
      </div>
    </div>
  );
}
