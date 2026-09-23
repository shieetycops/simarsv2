export const V2_SECURITY_LEVELS = [
  { value: "BIASA", label: "Biasa" },
  { value: "TERBATAS", label: "Terbatas" },
  { value: "RAHASIA", label: "Rahasia" },
  { value: "SANGAT_RAHASIA", label: "Sangat Rahasia" },
] as const;

export const V2_SOURCE_CHANNELS = ["POS", "KURIR", "EMAIL", "FAX", "INTERNAL", "LAINNYA"] as const;
export const V2_DOCUMENT_TYPES = ["SURAT_DINAS", "MEMORANDUM", "NOTA_DINAS", "UNDANGAN", "SURAT_PENGANTAR", "DISPOSISI", "LAPORAN", "NOTULA", "TELAAHAN_STAF"] as const;
export const V2_STAGES = [
  "DITERIMA", "VERIFIKASI_ALAMAT", "SALAH_ALAMAT", "DISORTIR", "MENUNGGU_PENGARAHAN", "DIBACA_PENGARAH", "TERREGISTRASI", "MENUNGGU_DISPOSISI", "DIDISPOSISIKAN", "DITERUSKAN_KE_KASUBAG", "DITERUSKAN_KE_SEKRETARIS_PANITERA", "MENUNGGU_KEBIJAKAN_PIMPINAN", "DITERUSKAN_KE_PELAKSANA", "DALAM_TINDAK_LANJUT", "SELESAI_DITINDAKLANJUTI", "MENUNGGU_PENGARSIPAN", "DIARSIPKAN",
] as const;

export const V2_STAGE_LABELS: Record<string, string> = {
  DITERIMA: "Diterima", VERIFIKASI_ALAMAT: "Verifikasi alamat", SALAH_ALAMAT: "Salah alamat", DISORTIR: "Disortir", MENUNGGU_PENGARAHAN: "Menunggu pengarahan", DIBACA_PENGARAH: "Dibaca pengarah", TERREGISTRASI: "Terregistrasi", MENUNGGU_DISPOSISI: "Menunggu disposisi", DIDISPOSISIKAN: "Didisposisikan", DITERUSKAN_KE_KASUBAG: "Ke Kasubag Umum", DITERUSKAN_KE_SEKRETARIS_PANITERA: "Ke Sekretaris/Panitera", MENUNGGU_KEBIJAKAN_PIMPINAN: "Menunggu kebijakan pimpinan", DITERUSKAN_KE_PELAKSANA: "Ke pelaksana", DALAM_TINDAK_LANJUT: "Dalam tindak lanjut", SELESAI_DITINDAKLANJUTI: "Selesai ditindaklanjuti", MENUNGGU_PENGARSIPAN: "Menunggu pengarsipan", DIARSIPKAN: "Diarsipkan",
};

// 13 kategori primer resmi Lampiran I SK Sekretaris MA 627/2023 (sumber:
// Ringkasan_Tata_Naskah_Dinas_dan_Klasifikasi_Arsip_MA.md). Dipakai sebagai
// fallback bila API master belum terisi; versi resmi diambil dari /api/control/classifications.
export const V2_ARCHIVE_PRIMARIES: Record<string, string> = {
  HK: "Hukum", HM: "Humas dan Protokol", KA: "Kearsipan", KP: "Kepegawaian", PL: "Perlengkapan", PS: "Perpustakaan", PW: "Pengawasan", RT: "Rumah Tangga", TI: "Teknologi Informasi", DL: "Pendidikan dan Pelatihan", RA: "Perencanaan Anggaran", KU: "Keuangan", OT: "Organisasi Tatalaksana",
};

export const V2_ADDRESS_STATUS_LABELS: Record<string, string> = {
  PERLU_VERIFIKASI: "Perlu verifikasi", ALAMAT_SESAI: "Alamat sesai", ALAMAT_TIDAK_SESAI: "Alamat tidak sesai",
};

export const V2_COMPLETENESS_LABELS: Record<string, string> = {
  BELUM_DIPERIKSA: "Belum diperiksa", LENGKAP: "Lengkap", TIDAK_LENGKAP: "Tidak lengkap",
};

export type V2Classification = {
  code: string;
  name: string;
  primaryCode: string;
  securityLevel: string;
  minimumRole: string | null;
  validationStatus: string;
};

export type V2ControlLog = {
  id: string;
  action: string;
  fromStage: string | null;
  toStage: string | null;
  notes: string | null;
  createdAt: string;
  actorName: string | null;
  actorRole: string | null;
};

export type V2CompletenessCheck = {
  id: string;
  addressCorrect: number;
  numberPresent: number;
  datePresent: number;
  subjectPresent: number;
  attachmentComplete: number;
  signaturePresent: number;
  notes: string | null;
  createdAt: string;
  checkedByName: string | null;
};

export type V2ControlDetail = {
  letter: Record<string, any>;
  logs: V2ControlLog[];
  checks: V2CompletenessCheck[];
  allowedNextStages: string[];
};

// Kelas warna badge level keamanan agar RAHASIA/SANGAT_RAHASIA menonjol.
export const V2_SECURITY_BADGE_CLASS: Record<string, string> = {
  BIASA: "border-slate-200 bg-slate-50 text-slate-700",
  TERBATAS: "border-amber-200 bg-amber-50 text-amber-800",
  RAHASIA: "border-rose-200 bg-rose-50 text-rose-800",
  SANGAT_RAHASIA: "border-rose-700 bg-rose-700 text-white",
};