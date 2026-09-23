// Helper penomoran surat keluar di sisi client.
// Dipakai oleh halaman "Ambil Nomor" dan form registrasi surat keluar.
// Logika utama tetap di backend (api-php/lib/OutgoingNumbering.php).

export interface ParsedLetterNumber {
  sequence: number;
  suffix: string | null;
}

// Ekstrak nomor dasar + suffix dari letter_number. Mengenal dua format sisipan:
//   '5/...'    -> { sequence: 5, suffix: null }
//   '5.a/...'  -> { sequence: 5, suffix: 'a' }
//   '5a/...'   -> { sequence: 5, suffix: 'a' }
// Data tanpa angka di depan (mis. 'TANPA-NOMOR-1') -> null (diabaikan).
export function parseLetterNumber(letterNumber: string): ParsedLetterNumber | null {
  const m = /^(\d+)(?:\.([a-z]))?(?:([a-z]))?/i.exec((letterNumber || "").trim());
  if (!m) return null;
  const suffix = m[2] || m[3] || null;
  return { sequence: parseInt(m[1], 10), suffix: suffix ? suffix.toLowerCase() : null };
}

export interface Slot {
  id: string;
  issuingUnit: string;
  letterDate: string;
  sequence: number;
  suffix: string | null;
  kode: string | null;
  letterNumber: string;
  status: string;
  reservedBy: string;
  reservedAt: string;
  updatedAt: string;
}

// Nomor dasar yang sudah terpakai untuk unit+tahun tertentu (urut naik).
export function usedSequences(letters: Array<{ issuingUnit?: string; letterDate?: string; letterNumber?: string }>, unit: string, year: number): number[] {
  const set = new Set<number>();
  for (const l of letters) {
    if (l.issuingUnit !== unit) continue;
    const y = l.letterDate ? parseInt(String(l.letterDate).slice(0, 4), 10) : null;
    if (y !== year) continue;
    const p = parseLetterNumber(l.letterNumber || "");
    if (p) set.add(p.sequence);
  }
  return [...set].sort((a, b) => a - b);
}

// Ambil bagian "kode" dari letter_number (segmen tengah antara nomor & bulan/tahun).
// '26/SPD.PPK.PA.PSW/2/2026'                       -> 'SPD.PPK.PA.PSW'
// '42/SEK.PA.W21-A7/KPA/KU1.1.3/II/2026'           -> 'SEK.PA.W21-A7/KPA/KU1.1.3'
export function extractKode(letterNumber: string): string {
  const parts = (letterNumber || "").split("/");
  if (parts.length <= 2) return "";
  return parts.slice(1, -2).join("/");
}

// Label bulan Romawi untuk tampilan (backend yang menentukan nomor final).
export function romanMonth(m: number): string {
  const romans = ["I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X", "XI", "XII"];
  return romans[m - 1] || String(m);
}
