// Membuat CSV guru dan jadwal mengajar fiktif untuk 12 kelas (7A-9D), cocok dengan siswa-dummy.csv (hanya DEV).
// Jadwal dijamin tanpa bentrok: tidak ada guru atau kelas yang dobel pada jam beririsan.
// Pemakaian: node scripts/generate-guru-jadwal-dummy.mjs [berkas-guru=guru-dummy.csv] [berkas-jadwal=jadwal-dummy.csv]
// Urutan impor: guru dulu, baru jadwal (kolom `guru` pada jadwal dicocokkan dengan nomor pegawai).
import { writeFileSync } from "node:fs";

const guruFile = process.argv[2] ?? "guru-dummy.csv";
const jadwalFile = process.argv[3] ?? "jadwal-dummy.csv";

const classes = ["7A", "7B", "7C", "7D", "8A", "8B", "8C", "8D", "9A", "9B", "9C", "9D"];
const days = ["Senin", "Selasa", "Rabu", "Kamis", "Jumat"];
// 6 jam pelajaran per hari, masing-masing 40 menit dengan istirahat 20 menit setelah jam ke-3.
const periods = [
  ["07:00", "07:40"], ["07:40", "08:20"], ["08:20", "09:00"],
  ["09:20", "10:00"], ["10:00", "10:40"], ["10:40", "11:20"],
];
// [mapel, jam per minggu per kelas, jumlah guru]; total 30 jam = 5 hari x 6 jam.
const subjects = [
  ["Matematika", 4, 4], ["Bahasa Indonesia", 4, 4], ["Bahasa Inggris", 3, 3], ["IPA", 4, 4], ["IPS", 3, 3],
  ["PPKn", 2, 2], ["Pendidikan Agama", 3, 3], ["PJOK", 2, 2], ["Seni Budaya", 2, 2], ["Informatika", 2, 2], ["Prakarya", 1, 1],
];

const first = ["Siti", "Budi", "Agus", "Dewi", "Rina", "Eko", "Lestari", "Hendra", "Wulan", "Fajar", "Maya", "Joko", "Nur", "Ahmad", "Putri", "Dedi", "Ani", "Yusuf", "Rahmat", "Sri", "Taufik", "Indah", "Bambang", "Yuni", "Hasan", "Fitri", "Andi", "Ratna", "Irwan", "Lina", "Surya", "Tina", "Wawan", "Mega", "Gunawan", "Kartika"];
const last = ["Rahma", "Santoso", "Wijaya", "Hidayat", "Lestari", "Pratama", "Nugroho", "Saputra", "Kusuma", "Wibowo", "Siregar", "Lubis", "Utami", "Susanto", "Hakim", "Fauzi", "Munir", "Anggraini", "Setiawan", "Handayani"];

let state = 20261022;
function rand() {
  state = (state + 0x6d2b79f5) | 0;
  let t = Math.imul(state ^ (state >>> 15), 1 | state);
  t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
}
const shuffle = (arr) => {
  for (let i = arr.length - 1; i > 0; i -= 1) {
    const j = Math.floor(rand() * (i + 1));
    [arr[i], arr[j]] = [arr[j], arr[i]];
  }
  return arr;
};

// Guru: tiap mapel punya beberapa guru; nama unik.
const teachers = [];
const usedNames = new Set();
subjects.forEach(([subject, , count]) => {
  for (let i = 0; i < count; i += 1) {
    let name;
    do {
      name = `${first[Math.floor(rand() * first.length)]} ${last[Math.floor(rand() * last.length)]}`;
    } while (usedNames.has(name));
    usedNames.add(name);
    const number = `1980${String(teachers.length + 1).padStart(4, "0")}0120050${String(10 + teachers.length).padStart(2, "0")}1`;
    teachers.push({ number, name, subject, email: `${name.toLowerCase().replace(/ /g, ".")}@sekolah.test` });
  }
});

// Setiap kelas mendapat satu guru per mapel (dibagi rata di antara guru mapel itu).
const teacherOf = {};
subjects.forEach(([subject]) => {
  const pool = teachers.filter((t) => t.subject === subject);
  classes.forEach((c, i) => {
    teacherOf[`${c}|${subject}`] = pool[i % pool.length];
  });
});

function build() {
  const classBusy = new Set(); // "kelas|hari|jam"
  const teacherBusy = new Set(); // "nomor|hari|jam"
  const rows = [];
  // Mapel dengan jam terbanyak ditempatkan lebih dulu agar slot tidak habis.
  const jobs = [];
  classes.forEach((c) => subjects.forEach(([subject, hours]) => { for (let h = 0; h < hours; h += 1) jobs.push({ c, subject, hours }); }));
  jobs.sort((a, b) => b.hours - a.hours || rand() - 0.5);

  for (const { c, subject } of jobs) {
    const teacher = teacherOf[`${c}|${subject}`];
    const options = [];
    days.forEach((_, d) => periods.forEach((__, p) => {
      if (classBusy.has(`${c}|${d}|${p}`) || teacherBusy.has(`${teacher.number}|${d}|${p}`)) return;
      // Hindari mapel yang sama dua kali pada hari yang sama untuk satu kelas.
      const sameDay = rows.some((r) => r.c === c && r.subject === subject && r.d === d);
      options.push({ d, p, penalty: sameDay ? 1 : 0 });
    }));
    if (options.length === 0) return null;
    shuffle(options).sort((a, b) => a.penalty - b.penalty);
    const { d, p } = options[0];
    classBusy.add(`${c}|${d}|${p}`);
    teacherBusy.add(`${teacher.number}|${d}|${p}`);
    rows.push({ c, subject, d, p, teacher });
  }
  return rows;
}

let rows = null;
for (let attempt = 0; attempt < 200 && rows === null; attempt += 1) rows = build();
if (rows === null) throw new Error("Gagal menyusun jadwal tanpa bentrok; ubah jumlah guru per mapel.");

const guruCsv = ["nomor_pegawai,nama_lengkap,mapel,email", ...teachers.map((t) => [t.number, t.name, t.subject, t.email].join(","))];
writeFileSync(guruFile, guruCsv.join("\n") + "\n", "utf8");

rows.sort((a, b) => a.c.localeCompare(b.c) || a.d - b.d || a.p - b.p);
const jadwalCsv = ["hari,jam_mulai,jam_selesai,kelas,mapel,guru", ...rows.map((r) => [days[r.d], periods[r.p][0], periods[r.p][1], r.c, r.subject, r.teacher.number].join(","))];
writeFileSync(jadwalFile, jadwalCsv.join("\n") + "\n", "utf8");

console.log(`${teachers.length} guru -> ${guruFile}; ${rows.length} jam mengajar (${classes.length} kelas) -> ${jadwalFile}`);
