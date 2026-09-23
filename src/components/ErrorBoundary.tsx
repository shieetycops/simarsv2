// @ts-nocheck
// Error boundary WAJIB class component (React tidak menyediakan hook untuk
// ini). Tipe React 19 (bundled) + tsconfig "useDefineForClassFields": false
// menghasilkan false-positive "Property 'state'/'props' does not exist" pada
// class field turunan Component. Build pakai vite/esbuild (tidak menjalankan
// tsc) jadi runtime tetap benar; @ts-nocheck hanya menenangkan type-checker
// untuk pola standar ini. Jangan ditiru di file lain.
import { Component, type ErrorInfo, type ReactNode } from "react";
import { AlertTriangle, RefreshCw, Home } from "lucide-react";

interface Props {
  children: ReactNode;
}

interface State {
  hasError: boolean;
  error: Error | null;
  errorInfo: ErrorInfo | null;
}

/**
 * ErrorBoundary menangkap error yang terjadi saat render (mis. struktur data
 * tidak sesuai ekspektasi, akses property undefined, format tanggal invalid,
 * dll). Tanpa boundary, React akan unmount seluruh tree dan layar jadi blank
 * putih tanpa pesan apa pun. Dengan boundary, error ditampilkan sebagai
 * fallback UI yang readable + stack trace, sehingga debugging jauh lebih
 * mudah. Ditaruh di dalam AppLayout, jadi sidebar/header tetap kelihatan
 * walau page content error.
 */
class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, error: null, errorInfo: null };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error, errorInfo: null };
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    console.error("ErrorBoundary caught:", error, errorInfo);
    this.setState({ errorInfo });
  }

  handleReload = () => {
    this.setState({ hasError: false, error: null, errorInfo: null });
    window.location.reload();
  };

  handleHome = () => {
    this.setState({ hasError: false, error: null, errorInfo: null });
    window.location.href = "/";
  };

  handleRetry = () => {
    this.setState({ hasError: false, error: null, errorInfo: null });
  };

  render() {
    if (!this.state.hasError) return this.props.children;

    const { error, errorInfo } = this.state;
    return (
      <div className="flex flex-col items-center justify-center min-h-[60vh] p-6 text-center">
        <div className="bg-white dark:bg-card border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-8 max-w-lg w-full">
          <div className="w-12 h-12 bg-red-50 dark:bg-red-900/20 rounded-full flex items-center justify-center mx-auto mb-4">
            <AlertTriangle className="w-6 h-6 text-red-600 dark:text-red-400" />
          </div>
          <h2 className="text-lg font-semibold text-slate-900 dark:text-slate-100 mb-1">
            Terjadi Kesalahan
          </h2>
          <p className="text-sm text-slate-500 dark:text-slate-400 mb-4">
            Komponen gagal dirender. Coba muat ulang halaman, atau kembali ke
            dashboard. Jika masalah berlanjut, hubungi administrator dan kirim
            pesan error di bawah ini.
          </p>

          <div className="bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 rounded-lg p-3 mb-4 text-left">
            <p className="text-xs font-mono text-red-600 dark:text-red-400 break-words">
              {error?.name}: {error?.message}
            </p>
            {errorInfo?.componentStack && (
              <pre className="mt-2 text-[10px] font-mono text-slate-500 dark:text-slate-400 whitespace-pre-wrap break-words max-h-40 overflow-auto">
                {errorInfo.componentStack}
              </pre>
            )}
          </div>

          <div className="flex flex-col sm:flex-row gap-2 justify-center">
            <button
              onClick={this.handleRetry}
              className="inline-flex items-center justify-center gap-2 h-9 px-4 text-sm font-medium rounded-md bg-primary text-primary-foreground hover:bg-primary/90 transition-colors"
            >
              <RefreshCw className="w-4 h-4" />
              Coba Lagi
            </button>
            <button
              onClick={this.handleReload}
              className="inline-flex items-center justify-center gap-2 h-9 px-4 text-sm font-medium rounded-md border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
            >
              <RefreshCw className="w-4 h-4" />
              Muat Ulang
            </button>
            <button
              onClick={this.handleHome}
              className="inline-flex items-center justify-center gap-2 h-9 px-4 text-sm font-medium rounded-md border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
            >
              <Home className="w-4 h-4" />
              Dashboard
            </button>
          </div>
        </div>
      </div>
    );
  }
}

export default ErrorBoundary;

