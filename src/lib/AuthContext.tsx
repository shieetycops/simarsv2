import { createContext, useContext, useState, useEffect, useCallback, useRef, ReactNode } from 'react';
import { toast } from "sonner";

interface User {
  id: string;
  username: string;
  name: string;
  role: string;
  avatar?: string;
}

interface AuthContextType {
  user: User | null;
  token: string | null;
  login: (token: string, user: User) => void;
  logout: () => void;
  isLoading: boolean;
  fetchApi: (url: string, options?: RequestInit) => Promise<Response>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const savedToken = localStorage.getItem('token');
    const savedUser = localStorage.getItem('user');

    if (savedToken && savedUser) {
      // Validate token by making a test request
      fetch("/api/dashboard/stats", {
        headers: { Authorization: `Bearer ${savedToken}` }
      }).then(res => {
        if (res.ok) {
          setToken(savedToken);
          setUser(JSON.parse(savedUser));
        } else {
          // Token is invalid/expired, clear it
          localStorage.removeItem('token');
          localStorage.removeItem('user');
        }
      }).catch(() => {
        // Network error, still try to use saved credentials
        setToken(savedToken);
        setUser(JSON.parse(savedUser));
      }).finally(() => {
        setIsLoading(false);
      });
    } else {
      setIsLoading(false);
    }
  }, []);

  const login = (newToken: string, newUser: User) => {
    setToken(newToken);
    setUser(newUser);
    localStorage.setItem('token', newToken);
    localStorage.setItem('user', JSON.stringify(newUser));
  };

  const logout = useCallback(() => {
    setToken(null);
    setUser(null);
    localStorage.removeItem('token');
    localStorage.removeItem('user');
  }, []);

  // Inactivity timeout (30 minutes)
  const INACTIVITY_TIMEOUT = 30 * 60 * 1000;
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const resetInactivityTimer = useCallback(() => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
    }
    const currentToken = localStorage.getItem('token');
    if (!currentToken) return;
    timeoutRef.current = setTimeout(() => {
      toast.error("Sesi berakhir karena tidak aktif");
      logout();
      window.location.href = "/login";
    }, INACTIVITY_TIMEOUT);
  }, [logout]);

  useEffect(() => {
    if (!token) return;

    const events = ["mousemove", "keypress", "click", "scroll", "touchstart"];
    const handleActivity = () => resetInactivityTimer();

    events.forEach(event => window.addEventListener(event, handleActivity));
    resetInactivityTimer();

    return () => {
      events.forEach(event => window.removeEventListener(event, handleActivity));
      if (timeoutRef.current) clearTimeout(timeoutRef.current);
    };
  }, [token, resetInactivityTimer]);

  const fetchApi = useCallback(async (url: string, options: RequestInit = {}) => {
    const headers: Record<string, string> = {
      ...(options.headers as Record<string, string> || {}),
    };
    
    const currentToken = localStorage.getItem('token');
    if (currentToken) {
      headers['Authorization'] = `Bearer ${currentToken}`;
    }

    const res = await fetch(url, { ...options, headers });

    // Auto-logout on expired/invalid token
    if (res.status === 401 || res.status === 403) {
      logout();
    }

    return res;
  }, [logout]);

  return (
    <AuthContext.Provider value={{ user, token, login, logout, isLoading, fetchApi }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
