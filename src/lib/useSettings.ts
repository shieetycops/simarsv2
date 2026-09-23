import { useState, useEffect } from "react";

export interface AppSettings {
  id: string;
  name: string;
  shortName: string;
  address: string;
  phone: string;
  email: string;
  logoUrl: string | null;
  updatedAt: string;
}

const defaultSettings: AppSettings = {
  id: "app_settings",
  name: "Pengadilan Agama Pasarwajo",
  shortName: "PA Pasarwajo",
  address: "Jl. Poros Pasarwajo, Kab. Buton, Sulawesi Tenggara",
  phone: "-",
  email: "-",
  logoUrl: null,
  updatedAt: "",
};

let cachedSettings: AppSettings | null = null;
let fetchPromise: Promise<AppSettings> | null = null;

async function fetchSettings(): Promise<AppSettings> {
  try {
    const res = await fetch("/api/settings");
    if (!res.ok) return defaultSettings;
    const data = await res.json();
    cachedSettings = data;
    return data;
  } catch {
    return defaultSettings;
  }
}

export function useSettings() {
  const [settings, setSettings] = useState<AppSettings>(cachedSettings || defaultSettings);
  const [loading, setLoading] = useState(!cachedSettings);

  useEffect(() => {
    if (cachedSettings) {
      setSettings(cachedSettings);
      setLoading(false);
      return;
    }

    if (!fetchPromise) {
      fetchPromise = fetchSettings();
    }

    fetchPromise.then((data) => {
      setSettings(data);
      setLoading(false);
    });
  }, []);

  const refetch = async () => {
    setLoading(true);
    fetchPromise = null;
    cachedSettings = null;
    const data = await fetchSettings();
    setSettings(data);
    setLoading(false);
  };

  return { settings, loading, refetch };
}
