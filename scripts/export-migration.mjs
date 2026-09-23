// Script sekali-pakai (jalankan lokal): baca prisma/dev.db via Prisma Client,
// tulis migration-data.sql (INSERT untuk tabel MySQL Fase 1, camelCase -> snake_case).
//   node scripts/export-migration.mjs
import { PrismaClient } from "@prisma/client";
import { writeFileSync } from "fs";

const prisma = new PrismaClient();

const snake = (s) => s.replace(/[A-Z]/g, (c) => "_" + c.toLowerCase());

function fmt(v) {
  if (v === null || v === undefined) return "NULL";
  if (v instanceof Date) return `'${v.toISOString().slice(0, 19).replace("T", " ")}'`;
  if (typeof v === "boolean") return v ? "1" : "0";
  if (typeof v === "number") return String(v);
  return `'${String(v).replace(/\\/g, "\\\\").replace(/'/g, "\\'")}'`;
}

// Urutan menghormati FK: users dulu, settings terakhir. auth_tokens tak dimigrasi (tabel baru).
// rename = pemetaan field khusus yang bukan sekadar snake_case (mis. Baileys -> Fonnte).
const TABLES = [
  { model: "user", table: "users" },
  { model: "incomingLetter", table: "incoming_letters" },
  { model: "outgoingLetter", table: "outgoing_letters" },
  { model: "disposition", table: "dispositions" },
  { model: "notification", table: "notifications" },
  { model: "attachment", table: "attachments" },
  { model: "activityLog", table: "activity_logs" },
  { model: "appSetting", table: "app_settings" },
  // groupJid (JID grup Baileys) -> group_target (ID grup Fonnte). fonnte_token diisi manual nanti.
  { model: "whatsappSetting", table: "whatsapp_settings", rename: { groupJid: "group_target" } },
];

let sql = "SET FOREIGN_KEY_CHECKS=0;\n";

for (const { model, table, rename = {} } of TABLES) {
  const rows = await prisma[model].findMany();
  for (const row of rows) {
    const cols = Object.keys(row).map((k) => rename[k] || snake(k));
    const vals = Object.values(row).map(fmt);
    sql += `INSERT INTO \`${table}\` (\`${cols.join("`, `")}\`) VALUES (${vals.join(", ")});\n`;
  }
  console.log(`${table}: ${rows.length} baris`);
}

sql += "SET FOREIGN_KEY_CHECKS=1;\n";
writeFileSync("migration-data.sql", sql);
await prisma.$disconnect();
console.log("Tulis migration-data.sql selesai.");
