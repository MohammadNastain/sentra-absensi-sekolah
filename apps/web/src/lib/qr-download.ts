import QRCode from "qrcode";

export type DownloadableCard = { studentName: string; studentNumber: string; className: string | null; token: string };

const W = 720;
const H = 960;

function roundedRect(ctx: CanvasRenderingContext2D, x: number, y: number, w: number, h: number, r: number): void {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

/** Teks dipotong dengan elipsis bila lebih lebar dari `max`. */
function fit(ctx: CanvasRenderingContext2D, text: string, max: number): string {
  if (ctx.measureText(text).width <= max) return text;
  let cut = text;
  while (cut.length > 1 && ctx.measureText(`${cut}…`).width > max) cut = cut.slice(0, -1);

  return `${cut}…`;
}

/** Menggambar kartu siswa (nama sekolah, QR, nama, kelas dan NIS) menjadi gambar PNG yang siap dibagikan. */
export async function renderCardPng(card: DownloadableCard, schoolName: string): Promise<Blob> {
  const qr = document.createElement("canvas");
  await QRCode.toCanvas(qr, card.token, { errorCorrectionLevel: "M", margin: 1, width: 520 });

  const canvas = document.createElement("canvas");
  canvas.width = W;
  canvas.height = H;
  const ctx = canvas.getContext("2d");
  if (!ctx) throw new Error("canvas");

  ctx.fillStyle = "#ffffff";
  ctx.fillRect(0, 0, W, H);

  // Pita atas bergradien navy dengan nama sekolah.
  const band = ctx.createLinearGradient(0, 0, W, 0);
  band.addColorStop(0, "#17356a");
  band.addColorStop(1, "#2858c0");
  ctx.save();
  roundedRect(ctx, 24, 24, W - 48, H - 48, 36);
  ctx.clip();
  ctx.fillStyle = band;
  ctx.fillRect(0, 0, W, 190);
  ctx.restore();

  ctx.strokeStyle = "#d8e1ec";
  ctx.lineWidth = 3;
  roundedRect(ctx, 24, 24, W - 48, H - 48, 36);
  ctx.stroke();

  ctx.textAlign = "center";
  ctx.fillStyle = "#ffffff";
  ctx.font = "600 26px system-ui, sans-serif";
  ctx.fillText("KARTU ABSENSI", W / 2, 84);
  ctx.font = "700 40px system-ui, sans-serif";
  ctx.fillText(fit(ctx, schoolName, W - 140), W / 2, 142);

  // QR di atas kotak putih dengan bayangan lembut.
  ctx.save();
  ctx.shadowColor = "rgb(23 53 106 / 0.18)";
  ctx.shadowBlur = 28;
  ctx.shadowOffsetY = 10;
  ctx.fillStyle = "#ffffff";
  roundedRect(ctx, 80, 220, W - 160, W - 160, 28);
  ctx.fill();
  ctx.restore();
  ctx.drawImage(qr, 100, 240, W - 200, W - 200);

  ctx.fillStyle = "#17202c";
  ctx.font = "700 44px system-ui, sans-serif";
  ctx.fillText(fit(ctx, card.studentName, W - 100), W / 2, 830);
  ctx.fillStyle = "#4d5e74";
  ctx.font = "500 30px system-ui, sans-serif";
  ctx.fillText(`${card.className ? `${card.className} · ` : ""}${card.studentNumber}`, W / 2, 884);

  return new Promise((resolve, reject) => canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error("png"))), "image/png"));
}

/** Nama berkas aman: huruf, angka, dan tanda hubung. */
export function cardFileName(card: DownloadableCard): string {
  const slug = (value: string) =>
    value
      .normalize("NFKD")
      .replace(/[^\w\s-]/g, "")
      .trim()
      .replace(/\s+/g, "-")
      .toLowerCase();

  return `kartu-qr-${slug(card.studentNumber)}-${slug(card.studentName)}.png`;
}

export function saveBlob(blob: Blob, name: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = name;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}

export async function downloadCard(card: DownloadableCard, schoolName: string): Promise<void> {
  saveBlob(await renderCardPng(card, schoolName), cardFileName(card));
}

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c >>> 0;
  }

  return table;
})();

function crc32(data: Uint8Array): number {
  let crc = 0xffffffff;
  for (const byte of data) crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);

  return (crc ^ 0xffffffff) >>> 0;
}

/** ZIP tanpa kompresi (PNG sudah terkompresi), cukup untuk menggabungkan banyak kartu menjadi satu unduhan. */
export function buildZip(files: { name: string; data: Uint8Array }[]): Blob {
  const encoder = new TextEncoder();
  const parts: BlobPart[] = [];
  const central: BlobPart[] = [];
  let offset = 0;

  for (const file of files) {
    const name = encoder.encode(file.name);
    const crc = crc32(file.data);
    const local = new DataView(new ArrayBuffer(30));
    local.setUint32(0, 0x04034b50, true);
    local.setUint16(4, 20, true);
    local.setUint16(6, 0x0800, true); // nama berkas UTF-8
    local.setUint32(14, crc, true);
    local.setUint32(18, file.data.length, true);
    local.setUint32(22, file.data.length, true);
    local.setUint16(26, name.length, true);
    parts.push(local.buffer, name, file.data as BlobPart);

    const entry = new DataView(new ArrayBuffer(46));
    entry.setUint32(0, 0x02014b50, true);
    entry.setUint16(4, 20, true);
    entry.setUint16(6, 20, true);
    entry.setUint16(8, 0x0800, true);
    entry.setUint32(16, crc, true);
    entry.setUint32(20, file.data.length, true);
    entry.setUint32(24, file.data.length, true);
    entry.setUint16(28, name.length, true);
    entry.setUint32(42, offset, true);
    central.push(entry.buffer, name);

    offset += 30 + name.length + file.data.length;
  }

  const centralSize = central.reduce((sum, part) => sum + (part instanceof ArrayBuffer ? part.byteLength : (part as Uint8Array).length), 0);
  const end = new DataView(new ArrayBuffer(22));
  end.setUint32(0, 0x06054b50, true);
  end.setUint16(8, files.length, true);
  end.setUint16(10, files.length, true);
  end.setUint32(12, centralSize, true);
  end.setUint32(16, offset, true);

  return new Blob([...parts, ...central, end.buffer], { type: "application/zip" });
}

/** Semua kartu satu kelas menjadi satu berkas ZIP berisi PNG per siswa. */
export async function downloadCardsZip(cards: DownloadableCard[], schoolName: string, zipName: string): Promise<void> {
  const files: { name: string; data: Uint8Array }[] = [];
  const used = new Set<string>();

  for (const card of cards) {
    const png = await renderCardPng(card, schoolName);
    let name = cardFileName(card);
    for (let i = 2; used.has(name); i++) name = cardFileName(card).replace(/\.png$/, `-${i}.png`);
    used.add(name);
    files.push({ name, data: new Uint8Array(await png.arrayBuffer()) });
  }

  saveBlob(buildZip(files), zipName);
}
