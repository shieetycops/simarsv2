import { ReactNode, useState, useEffect } from "react";
import type React from "react";
import { useNavigate, useLocation } from "react-router-dom";
import { useAuth } from "@/src/lib/AuthContext";
import { useSettings } from "@/src/lib/useSettings";
import { 
  LayoutDashboard, 
  Send, 
  Hash, 
  GitPullRequest, 
  Users, 
  FileBarChart, 
  LogOut, 
  Menu, 
  ChevronRight,
  Bell,
  Check,
  Archive,
  ClipboardList,
  BookOpenText,
  Settings,
  MessageCircle,
  Search,
  X
} from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { ThemeToggle } from "./ThemeToggle";

interface NavItem {
  title: string;
  href: string;
  icon: any;
  roles: string[];
}

const ALL_ROLES = [
  "ADMIN", "PIMPINAN", "WAKIL_KETUA", "HAKIM", "PANITERA", "SEKRETARIS",
  "PANITERA_MUDA_PERMOHONAN", "PANITERA_MUDA_GUGATAN", "PANITERA_MUDA_HUKUM",
  "KEPALA_SUB_PTIP", "KEPALA_SUB_KEPEGAWAIAN", "KEPALA_SUB_UMUM", "STAFF",
];

const navItems: NavItem[] = [
  { title: "Dashboard", href: "/", icon: LayoutDashboard, roles: ALL_ROLES },
  // Satu pintu masuk surat: /surat-masuk (menu lama) sudah diarahkan ke halaman
  // ini supaya tidak ada lagi dua daftar surat yang isinya bisa berbeda.
  { title: "Surat Masuk", href: "/v2/surat-masuk", icon: ClipboardList, roles: ALL_ROLES },
  { title: "Buku Kendali", href: "/v2/buku-kendali", icon: BookOpenText, roles: ALL_ROLES },
  { title: "Surat Keluar", href: "/surat-keluar", icon: Send, roles: ALL_ROLES },
  { title: "Ambil Nomor", href: "/ambil-nomor", icon: Hash, roles: ALL_ROLES },
  // Disposisi: masih dipakai pimpinan untuk menulis ISI instruksi (tabel
  // dispositions dipakai bersama v2). Catatan penting: menu ini tidak
  // memindahkan current_stage - perpindahan tahap dilakukan di Buku Kendali.
  { title: "Disposisi", href: "/disposisi", icon: GitPullRequest, roles: ALL_ROLES },
  { title: "Arsip Digital", href: "/arsip", icon: Archive, roles: ALL_ROLES },
  { title: "Laporan", href: "/laporan", icon: FileBarChart, roles: ["ADMIN", "PIMPINAN", "PANITERA", "SEKRETARIS", "PANITERA_MUDA_PERMOHONAN", "PANITERA_MUDA_GUGATAN", "PANITERA_MUDA_HUKUM", "KEPALA_SUB_PTIP", "KEPALA_SUB_KEPEGAWAIAN", "KEPALA_SUB_UMUM"] },
  { title: "Audit Log", href: "/audit", icon: ClipboardList, roles: ["ADMIN"] },
  { title: "Pengguna", href: "/users", icon: Users, roles: ["ADMIN"] },
  { title: "Pengaturan", href: "/pengaturan", icon: Settings, roles: ["ADMIN"] },
  { title: "Bot WhatsApp", href: "/bot-whatsapp", icon: MessageCircle, roles: ["ADMIN"] },
];

export default function AppLayout({ children }: { children: ReactNode }) {
  const { user, logout, token } = useAuth();
  const { settings } = useSettings();
  const navigate = useNavigate();
  const location = useLocation();
  const [isSidebarOpen, setSidebarOpen] = useState(true);
  const [isMobileSidebarOpen, setMobileSidebarOpen] = useState(false);
  const [notifications, setNotifications] = useState<any[]>([]);
  const [showNotifs, setShowNotifs] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");

  const currentPath = location.pathname;
  const filteredNavItems = navItems.filter(item => user && item.roles.includes(user.role));
  const unreadCount = notifications.filter(n => !n.isRead).length;

  const handleSearchSubmit = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter" && searchQuery.trim()) {
      navigate(`/arsip?search=${encodeURIComponent(searchQuery.trim())}`);
      setSearchQuery("");
    }
  };

  const fetchNotifications = async () => {
    const currentToken = localStorage.getItem('token');
    if (!currentToken) return;
    try {
      const res = await fetch("/api/dashboard/notifications", {
        headers: { Authorization: `Bearer ${currentToken}` }
      });
      if (res.ok) {
        const data = await res.json();
        setNotifications(Array.isArray(data) ? data : []);
      }
    } catch (err) {
      // silent
    }
  };

  const markAllRead = async () => {
    const currentToken = localStorage.getItem('token');
    if (!currentToken) return;
    try {
      await fetch("/api/dashboard/notifications/read-all", {
        method: "PATCH",
        headers: { Authorization: `Bearer ${currentToken}` }
      });
      setNotifications(prev => prev.map(n => ({ ...n, isRead: true })));
    } catch (err) {
      // silent
    }
  };

  useEffect(() => {
    fetchNotifications();
    // Check deadline reminders on load
    const currentToken = localStorage.getItem('token');
    if (currentToken) {
      fetch("/api/dashboard/deadline-check", {
        headers: { Authorization: `Bearer ${currentToken}` }
      }).then(res => {
        if (!res.ok) return;
        return res.json();
      }).then(data => {
        if (data && data.created > 0) {
          fetchNotifications();
        }
      }).catch(() => {});
    }
    const interval = setInterval(fetchNotifications, 15000);
    return () => clearInterval(interval);
  }, []);

  const handleLogout = () => {
    logout();
    navigate("/login");
  };

  return (
    <div className="flex h-screen bg-background">
      {/* Mobile Backdrop */}
      {isMobileSidebarOpen && (
        <div
          className="fixed inset-0 bg-black/50 z-40 md:hidden"
          onClick={() => setMobileSidebarOpen(false)}
        />
      )}

      {/* Sidebar */}
      <aside 
        className={cn(
          "bg-[#1B5E20] dark:bg-[#141c1f] text-white flex flex-col flex-shrink-0 transition-all duration-200 border-r border-[#145218] dark:border-[#1e2a2e]",
          "fixed md:relative z-50 h-full",
          isMobileSidebarOpen ? "translate-x-0" : "-translate-x-full md:translate-x-0",
          isSidebarOpen ? "w-60" : "w-60 md:w-16"
        )}
      >
        {/* Logo */}
        <div className="h-16 px-4 flex items-center gap-3 border-b border-[#145218] dark:border-[#1e2a2e]">
          {settings.logoUrl ? (
            <img src={settings.logoUrl} alt="Logo" className="w-8 h-8 rounded-lg object-contain shrink-0" />
          ) : (
            <div className="w-8 h-8 bg-[#C9A84C] rounded-lg flex items-center justify-center text-[#1B5E20] font-bold text-sm shrink-0">
              S
            </div>
          )}
          {isSidebarOpen && (
            <div>
              <p className="text-sm font-semibold leading-none">SIMARS</p>
              <p className="text-[10px] text-green-200 dark:text-slate-500 mt-0.5">{settings.shortName}</p>
            </div>
          )}
        </div>

        {/* Navigation */}
        <nav className="flex-1 p-3 space-y-1 overflow-y-auto">
          {filteredNavItems.map((item) => (
            <button
              key={item.title}
              onClick={() => { navigate(item.href); setMobileSidebarOpen(false); }}
              className={cn(
                "w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors",
                currentPath === item.href 
                  ? "bg-[#C9A84C] text-[#1B5E20] dark:bg-[#C9A84C]/15 dark:text-[#C9A84C] font-medium" 
                  : "text-green-100 dark:text-slate-400 hover:text-white hover:bg-[#2E7D32] dark:hover:bg-[#1e2a2e]"
              )}
            >
              <item.icon className="w-[18px] h-[18px] shrink-0" />
              {isSidebarOpen && <span>{item.title}</span>}
            </button>
          ))}
        </nav>

        {/* User Footer */}
        <div className="p-3 border-t border-[#145218] dark:border-[#1e2a2e]">
          <div className="flex items-center gap-3 px-3 py-2 mb-2">
            <div className="w-8 h-8 rounded-full bg-[#2E7D32] dark:bg-[#1e2a2e] flex items-center justify-center text-xs font-medium shrink-0">
              {user?.name.charAt(0)}
            </div>
            {isSidebarOpen && (
              <div className="overflow-hidden">
                <p className="text-sm font-medium truncate">{user?.name}</p>
                <p className="text-[11px] text-green-200 dark:text-slate-500">{user?.role}</p>
              </div>
            )}
          </div>
          <button
            onClick={handleLogout}
            className="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-green-100 dark:text-slate-400 hover:text-red-300 hover:bg-[#2E7D32] dark:hover:bg-[#1e2a2e] transition-colors"
          >
            <LogOut className="w-[18px] h-[18px] shrink-0" />
            {isSidebarOpen && <span>Keluar</span>}
          </button>
        </div>
      </aside>

      {/* Main Content */}
      <main className="flex-1 flex flex-col min-w-0">
        {/* Header */}
        <header className="h-16 bg-white dark:bg-card border-b px-6 flex items-center justify-between flex-shrink-0 sticky top-0 z-30">
          <div className="flex items-center gap-4">
            <Button variant="ghost" size="icon" className="h-9 w-9 text-slate-500 hidden md:inline-flex" onClick={() => setSidebarOpen(!isSidebarOpen)}>
              <Menu className="h-5 w-5" />
            </Button>
            <Button variant="ghost" size="icon" className="h-9 w-9 text-slate-500 md:hidden" onClick={() => setMobileSidebarOpen(!isMobileSidebarOpen)}>
              <Menu className="h-5 w-5" />
            </Button>
            <div className="hidden sm:flex items-center text-sm text-slate-500">
              <span>SIMARS</span>
              <ChevronRight className="mx-2 h-3.5 w-3.5" />
              <span className="text-slate-900 dark:text-slate-100 font-medium">
                {navItems.find(n => n.href === currentPath)?.title || "Dashboard"}
              </span>
            </div>
          </div>

          <div className="flex items-center gap-2">
            {/* Global Search */}
            <div className="relative hidden sm:block">
              <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-slate-400" />
              <Input
                placeholder="Cari surat..."
                className="pl-8 h-9 w-48 text-sm rounded-md"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                onKeyDown={handleSearchSubmit}
              />
            </div>

            <ThemeToggle />

            <div className="relative">
              <Button variant="ghost" size="icon" className="h-9 w-9 text-slate-500 relative" onClick={() => setShowNotifs(!showNotifs)}>
                <Bell className="h-[18px] w-[18px]" />
                {unreadCount > 0 && (
                  <span className="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-[10px] font-medium rounded-full flex items-center justify-center">
                    {unreadCount > 9 ? "9+" : unreadCount}
                  </span>
                )}
              </Button>
              {showNotifs && (
                <div className="absolute right-0 top-11 w-80 bg-white dark:bg-card border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg z-50">
                  <div className="flex items-center justify-between px-4 py-3 border-b border-slate-100 dark:border-slate-700">
                    <p className="text-sm font-medium text-slate-900 dark:text-slate-100">Notifikasi</p>
                    {unreadCount > 0 && (
                      <button onClick={markAllRead} className="text-xs text-primary hover:text-primary/80 font-medium flex items-center gap-1">
                        <Check className="w-3 h-3" /> Tandai dibaca
                      </button>
                    )}
                  </div>
                  <div className="max-h-72 overflow-y-auto">
                    {notifications.length === 0 ? (
                      <p className="text-sm text-slate-400 text-center py-8">Tidak ada notifikasi</p>
                    ) : (
                      notifications.slice(0, 10).map((notif) => (
                        <div
                          key={notif.id}
                          onClick={() => { if (notif.link) navigate(notif.link); setShowNotifs(false); }}
                          className={cn(
                            "px-4 py-3 border-b border-slate-50 dark:border-slate-700 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors",
                            !notif.isRead && "bg-blue-50/50 dark:bg-blue-900/20"
                          )}
                        >
                          <p className="text-sm font-medium text-slate-900 dark:text-slate-100">{notif.title}</p>
                          <p className="text-xs text-slate-500 mt-0.5 line-clamp-2">{notif.message}</p>
                        </div>
                      ))
                    )}
                  </div>
                </div>
              )}
            </div>
            <div className="hidden md:flex items-center gap-2 pl-3 border-l dark:border-slate-700">
              <div className="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-700 flex items-center justify-center text-xs font-medium text-slate-700 dark:text-slate-200">
                {user?.name.charAt(0)}
              </div>
              <div>
                <p className="text-sm font-medium text-slate-900 dark:text-slate-100">{user?.name}</p>
                <p className="text-[11px] text-slate-500 dark:text-slate-400">{user?.role}</p>
              </div>
            </div>
          </div>
        </header>

        {/* Page Content */}
        <div className="flex-1 overflow-y-auto">
          <div className="p-6 max-w-[1400px] mx-auto">
            {children}
          </div>
        </div>

        {/* Footer */}
        <footer className="h-10 bg-white dark:bg-card border-t px-6 flex items-center justify-between text-xs text-slate-400 flex-shrink-0">
          <span>{settings.name} Kelas II</span>
          <span>&copy; {new Date().getFullYear()} SIMARS v2 · mode pembanding</span>
        </footer>
      </main>
    </div>
  );
}