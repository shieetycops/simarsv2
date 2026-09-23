/**
 * Script sekali-pakai: baca data existing dari prisma/dev.db (SQLite, via Prisma Client)
 * lalu generate deploy/migration-data.sql berisi INSERT INTO ... untuk MySQL,
 * siap diimport lewat phpMyAdmin SETELAH deploy/schema.sql.
 *
 * Jalankan lokal:  npx tsx scripts/generate-mysql-migration.ts
 *
 * Catatan:
 * - Field camelCase Prisma dipetakan manual ke kolom snake_case MySQL.
 * - Hash password bcryptjs ($2a$/$2b$) kompatibel dengan password_verify() PHP — tidak di-rehash.
 * - whatsapp_settings TIDAK membawa groupJid lama (itu JID Baileys, tidak berlaku untuk Fonnte);
 *   isi group_target & fonnte_token lewat menu Pengaturan WhatsApp setelah deploy.
 * - auth_tokens tidak dimigrasi (data sesi, bukan data aplikasi).
 */
import { PrismaClient } from "@prisma/client";
import fs from "node:fs";
import path from "node:path";

const prisma = new PrismaClient();
const OUT_FILE = path.resolve(process.cwd(), "deploy/migration-data.sql");

function escStr(s: string): string {
  return s
    .replace(/\\/g, "\\\\")
    .replace(/'/g, "\\'")
    .replace(/\r/g, "\\r")
    .replace(/\n/g, "\\n")
    .replace(/\0/g, "");
}

function sqlValue(v: unknown, dateOnly = false): string {
  if (v === null || v === undefined) return "NULL";
  if (typeof v === "boolean") return v ? "1" : "0";
  if (typeof v === "number") return String(v);
  if (v instanceof Date) {
    const iso = v.toISOString(); // UTC, konsisten dengan penyimpanan Prisma
    return dateOnly ? `'${iso.slice(0, 10)}'` : `'${iso.slice(0, 19).replace("T", " ")}'`;
  }
  return `'${escStr(String(v))}'`;
}

// [kolom MySQL, field Prisma, dateOnly?]
type ColMap = [string, string, boolean?];

function insertStatements(table: string, rows: Record<string, unknown>[], cols: ColMap[]): string {
  if (rows.length === 0) return `-- ${table}: tidak ada data\n\n`;
  const colList = cols.map(([c]) => `\`${c}\``).join(", ");
  let out = `-- ${table} (${rows.length} baris)\n`;
  for (const row of rows) {
    const vals = cols.map(([, field, dateOnly]) => sqlValue(row[field], dateOnly ?? false)).join(", ");
    out += `INSERT INTO \`${table}\` (${colList}) VALUES (${vals});\n`;
  }
  return out + "\n";
}

async function main() {
  const [users, incoming, outgoing, dispositions, notifications, attachments, activityLogs, appSettings, waSettings] =
    await Promise.all([
      prisma.user.findMany(),
      prisma.incomingLetter.findMany(),
      prisma.outgoingLetter.findMany(),
      prisma.disposition.findMany(),
      prisma.notification.findMany(),
      prisma.attachment.findMany(),
      prisma.activityLog.findMany(),
      prisma.appSetting.findMany(),
      prisma.whatsappSetting.findMany(),
    ]);

  let sql = `-- =====================================================================
-- SIMARS - migrasi data existing SQLite (prisma/dev.db) -> MySQL
-- Generated: ${new Date().toISOString()}
-- Import SETELAH schema.sql. Seluruh timestamp dalam UTC.
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

`;

  sql += insertStatements("users", users, [
    ["id", "id"],
    ["username", "username"],
    ["password", "password"],
    ["name", "name"],
    ["role", "role"],
    ["avatar", "avatar"],
    ["wa_number", "waNumber"],
    ["supervisor_id", "supervisorId"],
    ["is_active", "isActive"],
    ["created_at", "createdAt"],
    ["updated_at", "updatedAt"],
  ]);

  sql += insertStatements("incoming_letters", incoming, [
    ["id", "id"],
    ["agenda_number", "agendaNumber"],
    ["letter_number", "letterNumber"],
    ["letter_date", "letterDate", true],
    ["received_date", "receivedDate", true],
    ["sender", "sender"],
    ["subject", "subject"],
    ["classification", "classification"],
    ["nature", "nature"],
    ["description", "description"],
    ["file_path", "filePath"],
    ["created_at", "createdAt"],
    ["updated_at", "updatedAt"],
  ]);

  sql += insertStatements("outgoing_letters", outgoing, [
    ["id", "id"],
    ["letter_number", "letterNumber"],
    ["letter_date", "letterDate", true],
    ["destination", "destination"],
    ["subject", "subject"],
    ["signer", "signer"],
    ["description", "description"],
    ["file_path", "filePath"],
    ["created_at", "createdAt"],
    ["updated_at", "updatedAt"],
  ]);

  sql += insertStatements("dispositions", dispositions, [
    ["id", "id"],
    ["incoming_letter_id", "incomingLetterId"],
    ["from_user_id", "fromUserId"],
    ["to_user_id", "toUserId"],
    ["notes", "notes"],
    ["instruction", "instruction"],
    ["deadline", "deadline"],
    ["status", "status"],
    ["created_at", "createdAt"],
    ["updated_at", "updatedAt"],
  ]);

  sql += insertStatements("notifications", notifications, [
    ["id", "id"],
    ["user_id", "userId"],
    ["title", "title"],
    ["message", "message"],
    ["link", "link"],
    ["is_read", "isRead"],
    ["created_at", "createdAt"],
  ]);

  sql += insertStatements("attachments", attachments, [
    ["id", "id"],
    ["file_name", "fileName"],
    ["file_path", "filePath"],
    ["file_size", "fileSize"],
    ["file_type", "fileType"],
    ["incoming_letter_id", "incomingLetterId"],
    ["outgoing_letter_id", "outgoingLetterId"],
    ["created_at", "createdAt"],
  ]);

  sql += insertStatements("activity_logs", activityLogs, [
    ["id", "id"],
    ["user_id", "userId"],
    ["action", "action"],
    ["entity", "entity"],
    ["entity_id", "entityId"],
    ["details", "details"],
    ["ip_address", "ipAddress"],
    ["created_at", "createdAt"],
  ]);

  // Baris tunggal pengaturan: REPLACE agar menimpa baris default dari schema.sql
  const app = appSettings[0];
  if (app) {
    sql += `-- app_settings\nREPLACE INTO \`app_settings\` (\`id\`, \`name\`, \`short_name\`, \`address\`, \`phone\`, \`email\`, \`logo_url\`) VALUES (${sqlValue(app.id)}, ${sqlValue(app.name)}, ${sqlValue(app.shortName)}, ${sqlValue(app.address)}, ${sqlValue(app.phone)}, ${sqlValue(app.email)}, ${sqlValue(app.logoUrl)});\n\n`;
  }
  const wa = waSettings[0];
  sql += `-- whatsapp_settings: group_target & fonnte_token sengaja NULL — isi lewat menu Pengaturan WhatsApp (groupJid Baileys lama tidak berlaku untuk Fonnte)\nREPLACE INTO \`whatsapp_settings\` (\`id\`, \`group_target\`, \`fonnte_token\`, \`is_enabled\`) VALUES ('wa_settings', NULL, NULL, ${wa ? sqlValue(wa.isEnabled) : "1"});\n\n`;

  sql += "SET FOREIGN_KEY_CHECKS=1;\n";

  fs.writeFileSync(OUT_FILE, sql, "utf8");
  console.log(`OK -> ${OUT_FILE}`);
  console.log(
    `  users=${users.length} incoming=${incoming.length} outgoing=${outgoing.length} dispositions=${dispositions.length}`
  );
  console.log(
    `  notifications=${notifications.length} attachments=${attachments.length} activity_logs=${activityLogs.length}`
  );
}

main()
  .then(() => prisma.$disconnect())
  .catch(async (e) => {
    console.error(e);
    await prisma.$disconnect();
    process.exit(1);
  });
