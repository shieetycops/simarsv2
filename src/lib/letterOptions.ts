export const CLASSIFICATION_OPTIONS = [
  { value: "DINAS", label: "Dinas" },
  { value: "KEUANGAN", label: "Keuangan" },
  { value: "KEPEGAWAIAN", label: "Kepegawaian" },
  { value: "TEKNIS", label: "Teknis" },
  { value: "UMUM", label: "Umum" },
  { value: "HUKUM", label: "Hukum" },
  { value: "PENGADAAN", label: "Pengadaan" },
  { value: "KERJA_SAMA", label: "Kerja Sama" },
  { value: "HUMAS", label: "Humas" },
] as const;

export const NATURE_OPTIONS = [
  { value: "BIASA", label: "Biasa" },
  { value: "PENTING", label: "Penting" },
  { value: "SEGERA", label: "Segera" },
  { value: "RAHASIA", label: "Rahasia" },
] as const;

// Unit penerbit surat keluar — PPK, Sekretaris, dan KPA masing-masing punya
// buku agenda & penomoran sendiri di lapangan sebelum SIMARS dipakai.
export const ISSUING_UNIT_OPTIONS = [
  { value: "PPK", label: "PPK" },
  { value: "SEKRETARIS", label: "Sekretaris" },
  { value: "KPA", label: "KPA" },
] as const;
