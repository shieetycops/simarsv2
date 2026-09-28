export const V2_SECURITY_LEVELS = [
  { value: "BIASA", label: "Biasa" },
  { value: "TERBATAS", label: "Terbatas" },
  { value: "RAHASIA", label: "Rahasia" },
  { value: "SANGAT_RAHASIA", label: "Sangat Rahasia" },
] as const;

export const V2_SOURCE_CHANNELS = ["POS", "KURIR", "EMAIL", "FAX", "INTERNAL", "LAINNYA"] as const;
export const V2_DOCUMENT_TYPES = ["SURAT_DINAS", "MEMORANDUM", "NOTA_DINAS", "UNDANGAN", "SURAT_PENGANTAR", "DISPOSISI", "LAPORAN", "NOTULA", "TELAAHAN_STAF"] as const;
export const V2_LETTER_CATEGORIES = ["DINAS", "PRIBADI"] as const;
export const V2_URGENCY_LEVELS = ["NORMAL", "SEGERA", "PENTING"] as const;

// Label bahasa Indonesia untuk nilai enum yang dipakai di form/dialog.
export const V2_DOCUMENT_TYPE_LABELS: Record<string, string> = {
  SURAT_DINAS: "Surat Dinas", MEMORANDUM: "Memorandum", NOTA_DINAS: "Nota Dinas",
  UNDANGAN: "Undangan", SURAT_PENGANTAR: "Surat Pengantar", DISPOSISI: "Disposisi",
  LAPORAN: "Laporan", NOTULA: "Notula", TELAAHAN_STAF: "Telaahan Staf",
};
export const V2_SOURCE_CHANNEL_LABELS: Record<string, string> = {
  POS: "Pos", KURIR: "Kurir", EMAIL: "Email", FAX: "Fax", INTERNAL: "Internal", LAINNYA: "Lainnya",
};
export const V2_URGENCY_LABELS: Record<string, string> = {
  NORMAL: "Normal", SEGERA: "Segera", PENTING: "Penting",
};

// Cermin V2Workflow::CORRECTABLE_STAGES di server: tahap yang datanya masih
// boleh dikoreksi/dihapus. UI hanya memakai ini sebagai cadangan — keputusan
// resmi tetap dari payload detail (canEdit/canDelete) yang dihitung server.
export const V2_CORRECTABLE_STAGES = [
  "DITERIMA", "VERIFIKASI_ALAMAT", "SALAH_ALAMAT", "DISORTIR",
  "MENUNGGU_PENGARAHAN", "DIBACA_PENGARAH", "TERREGISTRASI", "MENUNGGU_DISPOSISI",
] as const;

export const v2StageAllowsCorrection = (stage?: string | null) =>
  (V2_CORRECTABLE_STAGES as readonly string[]).includes(stage || "");

// Lampiran surat: cermin api-php/lib/Upload.php (magic bytes + ekstensi kanonik).
export const V2_ATTACHMENT_ACCEPT = ".pdf,.doc,.docx,.jpg,.jpeg,.png";
export const V2_ATTACHMENT_HINT = "PDF, DOC/DOCX, JPG, atau PNG · maksimal 10 MB";

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
  PERLU_VERIFIKASI: "Perlu verifikasi", ALAMAT_SESUAI: "Alamat sesuai", ALAMAT_TIDAK_SESUAI: "Alamat tidak sesuai",
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

export type V2AllowedTransition = {
  toStage: string;
  label: string;
  requiresRoute: boolean;
};

export type V2ControlDetail = {
  letter: Record<string, any>;
  logs: V2ControlLog[];
  checks: V2CompletenessCheck[];
  allowedNextStages: string[];
  allowedTransitions: V2AllowedTransition[];
  dispositionRoute: string | null;
  dispositionRoutes: string[];
  dispositionRouteLabels: Record<string, string>;
  ratificationNotice: string;
  // Revisi SOP/AS/04: rekomendasi Kasubag vs keputusan final Sekretaris,
  // arahan pimpinan, unit tujuan, dan hak peran.
  rekomendasiRoute?: string | null;
  rekomendasiNotes?: string | null;
  arahanPimpinan?: string | null;
  unitTujuan?: string | null;
  unitTujuanList?: string[];
  canGiveRekomendasi?: boolean;
  canSetFinalRoute?: boolean;
  canMarkArchived?: boolean;
  isUnitHead?: boolean;
  // Fase 0/1: pelaksana surat, pemegang tahap, dan hak penarikan kembali —
  // semuanya dihitung server supaya tombol yang tampil = yang divalidasi server.
  stageLabel?: string;
  stageOwnerRoles?: string[];
  assignee?: { id: string; name: string; role: string } | null;
  canReopen?: boolean;
  // K3 (revisi kedua): rencana TOLAK/RECALL/koreksi ADMIN dihitung server.
  rejectMode?: "TOLAK" | "RECALL" | "ADMIN_REOPEN" | null;
  rejectTarget?: string;
  rejectTargetLabel?: string;
  rejectLabel?: string;
  rejectMessage?: string;
  reopenTarget?: string;
  reopenTargetLabel?: string;
  reopenLabel?: string;
  reopenReasonMin?: number;
  // K7 (revisi kedua): serah-terima lembar 1 disposisi (SOP/AS/04 langkah 17).
  lembar1?: {
    diserahkanOleh?: string | null;
    diserahkanOlehName?: string | null;
    diterimaOleh?: string | null;
    diterimaOlehName?: string | null;
    diserahkanAt?: string | null;
  };
  canRecordLembar1?: boolean;
  lembar1Wajib?: boolean;
  // Dihitung server (role + tahap + disposisi), dipakai untuk menampilkan tombol Edit/Hapus.
  canCorrectStage?: boolean;
  correctableStages?: string[];
  hasDispositions?: boolean;
  canEdit?: boolean;
  canDelete?: boolean;
};

// Rute keputusan Sekretaris/Panitera (SOP/AS/04 langkah 13) dan tujuannya.
// Dipakai UI agar tombol yang tampil sama persis dengan yang divalidasi server.
export const V2_DECISION_POINT_ROLES = ["ADMIN", "SEKRETARIS", "PANITERA"] as const;
export const V2_REKOMENDASI_ROLES = ["ADMIN", "SEKRETARIS", "PANITERA", "KEPALA_SUB_UMUM"] as const;
export const V2_FINAL_ROUTE_ROLES = ["ADMIN", "SEKRETARIS", "PANITERA"] as const;
export const V2_ARSIPARIS_ROLES = ["ADMIN", "ARSIPARIS"] as const;
export const V2_UNIT_TUJUAN_LIST = [
  "KASUBAG_UMUM", "KASUBAG_KEPEGAWAIAN", "KASUBAG_PTIP",
  "PANMUD_PERMOHONAN", "PANMUD_GUGATAN", "PANMUD_HUKUM",
] as const;
export const V2_UNIT_TUJUAN_LABELS: Record<string, string> = {
  KASUBAG_UMUM: "Kasubag Umum",
  KASUBAG_KEPEGAWAIAN: "Kasubag Kepegawaian",
  KASUBAG_PTIP: "Kasubag PTIP",
  PANMUD_PERMOHONAN: "Panmud Permohonan",
  PANMUD_GUGATAN: "Panmud Gugatan",
  PANMUD_HUKUM: "Panmud Hukum",
};
export const V2_DISPOSITION_ROUTES = ["KEBIJAKAN", "LANGSUNG"] as const;
export const V2_DISPOSITION_ROUTE_LABELS: Record<string, string> = {
  KEBIJAKAN: "Perlu kebijakan pimpinan",
  LANGSUNG: "Langsung ke unit pelaksana",
};
export const V2_DISPOSITION_ROUTE_TARGETS: Record<string, string> = {
  KEBIJAKAN: "MENUNGGU_KEBIJAKAN_PIMPINAN",
  LANGSUNG: "DITERUSKAN_KE_PELAKSANA",
};

// ---------- Fase 0-2: pemegang surat per tahap + peristiwa disposisi ----------
// Cermin V2Workflow::STAGE_OWNER_ROLES / DISPOSITION_EVENT_STAGES di server. UI
// memakainya hanya untuk MENJELASKAN siapa yang sedang menunggu; keputusan resmi
// tetap dari payload detail (allowedTransitions/canReopen) yang dihitung server.
export const V2_STAGE_OWNER_ROLES: Record<string, string[]> = {
  // P2 (revisi kedua): tahap awal = meja Kasubag Umum (pengarah surat);
  // Sekretaris menjadi pemegang mulai langkah 13.
  MENUNGGU_PENGARAHAN: ["KEPALA_SUB_UMUM"],
  DIBACA_PENGARAH: ["KEPALA_SUB_UMUM"],
  MENUNGGU_DISPOSISI: ["SEKRETARIS", "PANITERA"],
  DIDISPOSISIKAN: ["SEKRETARIS", "PANITERA"],
  DITERUSKAN_KE_KASUBAG: ["KEPALA_SUB_UMUM"],
  DITERUSKAN_KE_SEKRETARIS_PANITERA: ["SEKRETARIS", "PANITERA"],
  MENUNGGU_KEBIJAKAN_PIMPINAN: ["PIMPINAN", "WAKIL_KETUA"],
  SELESAI_DITINDAKLANJUTI: ["SEKRETARIS", "PANITERA", "KEPALA_SUB_UMUM"],
  // P8 (revisi kedua): Arsiparis ikut memegang MENUNGGU_PENGARSIPAN.
  MENUNGGU_PENGARSIPAN: ["SEKRETARIS", "PANITERA", "ARSIPARIS"],
};

export const V2_ROLE_LABELS: Record<string, string> = {
  ADMIN: "Admin", PIMPINAN: "Pimpinan", WAKIL_KETUA: "Wakil Ketua", SEKRETARIS: "Sekretaris",
  PANITERA: "Panitera", KEPALA_SUB_UMUM: "Kasubag Umum", KEPALA_SUB_PTIP: "Kasubag PTIP",
  KEPALA_SUB_KEPEGAWAIAN: "Kasubag Kepegawaian", PANITERA_MUDA_PERMOHONAN: "Panmud Permohonan",
  PANITERA_MUDA_GUGATAN: "Panmud Gugatan", PANITERA_MUDA_HUKUM: "Panmud Hukum", STAFF: "Staf",
  ARSIPARIS: "Arsiparis",
};

export const v2StageOwnerRoles = (stage?: string | null) => V2_STAGE_OWNER_ROLES[stage || ""] || [];

export const v2StageOwnerText = (stage?: string | null) =>
  v2StageOwnerRoles(stage).map((r) => V2_ROLE_LABELS[r] || r).join(" / ");

// Laporan pelaksana (web/WA) -> tahap surat yang otomatis dituju (Fase 1).
export const V2_DISPOSITION_EVENT_STAGES: Record<string, string> = {
  PROSES: "DALAM_TINDAK_LANJUT",
  SELESAI: "SELESAI_DITINDAKLANJUTI",
};

// ---------- Fase 1: penarikan kembali (rollback) oleh Kasubag Umum ----------
// Kasubag memegang rantai pelaksanaan; ia boleh menarik surat kembali ke mejanya
// bila disposisi ke pelaksana keliru. Alasan wajib (tercatat sebagai STAGE_REOPEN
// di riwayat Buku Kendali). Cermin V2Workflow::REOPEN_* di server.
export const V2_REOPEN_TARGET_STAGE = "DITERUSKAN_KE_KASUBAG";
export const V2_REOPEN_REASON_MIN = 10;
export const V2_REOPENABLE_STAGES = ["DITERUSKAN_KE_PELAKSANA", "DALAM_TINDAK_LANJUT", "SELESAI_DITINDAKLANJUTI"];
export const V2_REOPEN_ADMIN_STAGES = [
  ...V2_REOPENABLE_STAGES, "MENUNGGU_KEBIJAKAN_PIMPINAN", "MENUNGGU_PENGARSIPAN",
];

export const v2ReopenableStagesFor = (role?: string | null) =>
  role === "ADMIN" ? V2_REOPEN_ADMIN_STAGES : role === "KEPALA_SUB_UMUM" ? V2_REOPENABLE_STAGES : [];

export const v2CanReopenLetter = (role: string | null | undefined, stage?: string | null) =>
  v2ReopenableStagesFor(role).includes(stage || "");

// Kelas warna badge level keamanan agar RAHASIA/SANGAT_RAHASIA menonjol.
export const V2_SECURITY_BADGE_CLASS: Record<string, string> = {
  BIASA: "border-slate-200 bg-slate-50 text-slate-700",
  TERBATAS: "border-amber-200 bg-amber-50 text-amber-800",
  RAHASIA: "border-rose-200 bg-rose-50 text-rose-800",
  SANGAT_RAHASIA: "border-rose-700 bg-rose-700 text-white",
};