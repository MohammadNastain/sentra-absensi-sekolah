// Membuat CSV siswa fiktif untuk uji impor/beban (hanya DEV). Hasilnya deterministik (seed tetap).
// Pemakaian: node scripts/generate-siswa-dummy.mjs [jumlah=500] [berkas=siswa-dummy.csv]
import { writeFileSync } from "node:fs";

const total = Number(process.argv[2] ?? 500);
const outFile = process.argv[3] ?? "siswa-dummy.csv";

const classes = ["7A", "7B", "7C", "7D", "8A", "8B", "8C", "8D", "9A", "9B", "9C", "9D"];
const birthYears = { 7: [2013, 2014], 8: [2012, 2013], 9: [2011, 2012] };

const male = ["Ahmad", "Budi", "Cahyo", "Dimas", "Eko", "Fajar", "Gilang", "Hendra", "Irfan", "Joko", "Kevin", "Lukman", "Muhammad", "Naufal", "Oki", "Putra", "Rizky", "Surya", "Taufik", "Umar", "Wahyu", "Yoga", "Zaki", "Andika", "Bagas", "Rangga", "Farhan", "Galih", "Bayu", "Daffa", "Hasan", "Ilham", "Reza", "Sandi", "Yusuf", "Ardi", "Bintang", "Candra", "Raka", "Tegar"];
const female = ["Aisyah", "Bunga", "Citra", "Dewi", "Eka", "Fitri", "Gita", "Hana", "Indah", "Jasmine", "Khairunnisa", "Lestari", "Maya", "Nadia", "Olivia", "Putri", "Rina", "Salsabila", "Tiara", "Umi", "Vina", "Wulan", "Yuni", "Zahra", "Anggun", "Bella", "Cantika", "Dinda", "Elsa", "Febi", "Ghina", "Intan", "Kirana", "Laila", "Mutiara", "Nabila", "Rahma", "Sekar", "Talita", "Ayu"];
const family = ["Pratama", "Saputra", "Wijaya", "Hidayat", "Nugroho", "Santoso", "Lubis", "Siregar", "Nasution", "Simanjuntak", "Harahap", "Hutapea", "Sihombing", "Gunawan", "Kurniawan", "Setiawan", "Permana", "Ramadhan", "Maulana", "Firmansyah", "Susanto", "Rahmawati", "Utami", "Wulandari", "Handayani", "Anggraini", "Lestari", "Puspita", "Sari", "Dewanti", "Suryani", "Hakim", "Fauzi", "Munir", "Alamsyah", "Pangestu", "Halim", "Tanujaya", "Salim", "Kusuma", "Mahendra", "Pradana", "Suwandi", "Yulianto", "Tampubolon", "Situmorang", "Batubara", "Daulay", "Rangkuti", "Wibowo"];

// PRNG mulberry32 dengan seed tetap
let state = 20261021;
function rand() {
  state = (state + 0x6d2b79f5) | 0;
  let t = Math.imul(state ^ (state >>> 15), 1 | state);
  t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
}
const pick = (arr) => arr[Math.floor(rand() * arr.length)];
const int = (min, max) => min + Math.floor(rand() * (max - min + 1));

const usedNames = new Set();
function makeName() {
  for (;;) {
    const first = rand() < 0.5 ? pick(male) : pick(female);
    const parts = [first];
    const extra = rand();
    if (extra < 0.45) parts.push(pick(rand() < 0.5 ? male : female));
    if (extra > 0.15) parts.push(pick(family));
    const name = parts.join(" ");
    if (!usedNames.has(name)) {
      usedNames.add(name);
      return name;
    }
  }
}

const usedNisn = new Set();
function makeNisn() {
  for (;;) {
    const nisn = String(int(1, 9)) + String(int(0, 999999999)).padStart(9, "0");
    if (!usedNisn.has(nisn)) {
      usedNisn.add(nisn);
      return nisn;
    }
  }
}

const pad = (n) => String(n).padStart(2, "0");
const rows = ["nomor_induk,nisn,nama_lengkap,tanggal_lahir,kelas"];
for (let i = 0; i < total; i++) {
  const className = classes[i % classes.length];
  const [y1, y2] = birthYears[className[0]];
  const date = `${rand() < 0.5 ? y1 : y2}-${pad(int(1, 12))}-${pad(int(1, 28))}`;
  const nisn = rand() < 0.05 ? "" : makeNisn();
  rows.push([`2026${String(i + 1).padStart(4, "0")}`, nisn, makeName(), date, className].join(","));
}

writeFileSync(outFile, rows.join("\n") + "\n", "utf8");
console.log(`${total} siswa ditulis ke ${outFile} (${classes.length} kelas: ${classes.join(", ")})`);
