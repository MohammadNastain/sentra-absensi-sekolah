import Link from "next/link";
import type { ReactNode } from "react";
import { BrandMark } from "@/components/AppShell";
import { IconBook, IconCalendar, IconCheck, IconChart, IconClock, IconDevice, IconDoc, IconGrad, IconMail, IconShield, IconUsers } from "@/components/icons";
import { LogoStage } from "@/components/LogoStage";
import { ThemeToggle } from "@/components/ThemeToggle";
import { HeroCta, NavCta } from "./landing-cta";

type Icon = (props: { width?: number; height?: number }) => ReactNode;

const FEATURES: { icon: Icon; title: string; text: string; soon?: boolean }[] = [
  { icon: IconClock, title: "Absensi QR dan RFID", text: "Siswa cukup memindai kartu di gerbang. Kehadiran tercatat real-time, lengkap dengan terlambat dan alpa otomatis." },
  { icon: IconUsers, title: "Data siswa dan guru", text: "Impor dari CSV, penempatan kelas, kenaikan kelas, dan kelulusan dalam satu tempat yang rapi." },
  { icon: IconCalendar, title: "Jadwal dan kalender", text: "Jadwal mengajar berversi, hari libur, dan kalender akademik yang langsung dipakai aturan absensi." },
  { icon: IconDoc, title: "Izin, sakit, dan koreksi", text: "Pengajuan dengan lampiran, persetujuan, dan koreksi kehadiran yang selalu tercatat siapa dan kapan." },
  { icon: IconChart, title: "Laporan dan rekap", text: "Rekap harian sampai bulanan, tren kehadiran, dan ekspor CSV untuk kebutuhan administrasi sekolah." },
  { icon: IconBook, title: "Pembelajaran (LMS)", text: "Guru membagikan materi dan tugas, siswa mengumpulkan jawaban, guru menilai atau meminta revisi.", soon: true },
];

const STEPS = [
  { title: "Sekolah didaftarkan", text: "Admin Sentra membuat sekolah Anda dan mengundang Admin Sekolah pertama lewat surel." },
  { title: "Data disiapkan", text: "Impor siswa dan guru, susun kelas dan jadwal, lalu terbitkan kartu atau pasang terminal di gerbang." },
  { title: "Sekolah berjalan", text: "Absensi harian, izin, laporan, dan pembelajaran dipakai bersama oleh admin, guru, wali kelas, dan siswa." },
];

const ROLES = [
  { role: "Admin Sekolah", text: "Mengelola anggota, data akademik, perangkat, dan laporan." },
  { role: "Kepala Sekolah", text: "Memantau kehadiran, laporan, dan audit tanpa mengubah data." },
  { role: "Guru dan Wali Kelas", text: "Melihat kelasnya, menyetujui izin, serta mengelola materi dan tugas." },
  { role: "Siswa", text: "Membaca materi, mengumpulkan tugas, dan melihat nilai miliknya." },
];

const TRUST = [
  { icon: IconShield, title: "Data tiap sekolah terisolasi", text: "Pemisahan di tingkat basis data, bukan hanya di tampilan. Sekolah lain tidak pernah melihat data Anda." },
  { icon: IconDoc, title: "Jejak audit lengkap", text: "Koreksi, keputusan izin, dan perubahan penting tercatat dan tidak dapat diubah." },
  { icon: IconDevice, title: "Terminal yang aman", text: "Perangkat dipasangkan satu per satu dan dapat dicabut kapan saja bila hilang." },
  { icon: IconGrad, title: "Peran dan izin jelas", text: "Setiap orang hanya melihat dan mengubah apa yang menjadi tugasnya." },
];

const PLANS: { name: string; tagline: string; items: string[]; soon?: string[]; highlight?: boolean }[] = [
  { name: "Basic", tagline: "Absensi harian yang andal", items: ["Data siswa", "Absensi QR dan RFID", "Laporan kehadiran"] },
  {
    name: "Standard",
    tagline: "Operasional sekolah lengkap",
    items: ["Semua fitur Basic", "Izin, sakit, dan koreksi", "Data guru dan akademik", "Jadwal mengajar dan kalender"],
    highlight: true,
  },
  { name: "Premium", tagline: "Plus pembelajaran daring", items: ["Semua fitur Standard"], soon: ["Materi dan lampiran", "Tugas, pengumpulan, dan penilaian"] },
];

const FAQ = [
  { q: "Apakah perlu membeli perangkat khusus?", a: "Absensi QR berjalan dari perangkat dengan kamera. Untuk kartu RFID Anda memerlukan pembaca kartu yang kami sesuaikan lewat profil perangkat." },
  { q: "Bagaimana kalau internet sekolah mati?", a: "Mode bantuan dan penutupan hari manual tersedia agar petugas tetap dapat mencatat kehadiran. Prosedurnya ada di panduan petugas." },
  { q: "Siapa yang dapat melihat data siswa?", a: "Hanya anggota sekolah itu sendiri sesuai perannya. Data setiap sekolah terisolasi, dan akses penting tercatat di audit." },
  { q: "Bagaimana siswa masuk ke LMS?", a: "Admin membuat akun dari NIS. Siswa masuk dengan kode sekolah dan NIS, lalu wajib mengganti sandi awalnya." },
  { q: "Bagaimana cara mendaftarkan sekolah kami?", a: "Hubungi kami. Kami membuat akun sekolah dan mengundang Admin Sekolah pertama lewat surel." },
];

const NAV = [
  { href: "#fitur", label: "Fitur" },
  { href: "#alur", label: "Cara kerja" },
  { href: "#paket", label: "Paket" },
  { href: "#faq", label: "FAQ" },
];

const CONTACT_EMAIL = process.env.NEXT_PUBLIC_CONTACT_EMAIL;

function SectionTitle({ eyebrow, title, text }: { eyebrow: string; title: ReactNode; text?: string }) {
  return (
    <div className="mx-auto max-w-2xl text-center">
      <p className="text-xs font-bold uppercase tracking-[0.16em] text-brand-700 dark:text-brand-300">{eyebrow}</p>
      <h2 className="mt-2 text-3xl font-extrabold leading-[1.1] tracking-[-0.03em] text-zinc-900 sm:text-4xl dark:text-white">{title}</h2>
      {text ? <p className="mt-3 text-base leading-relaxed text-zinc-600 dark:text-zinc-300">{text}</p> : null}
    </div>
  );
}

const CARD = "rounded-3xl border border-white/70 bg-white/95 shadow-card md:bg-white/80 md:backdrop-blur-xl dark:border-zinc-700/60 dark:bg-zinc-900/90 md:dark:bg-zinc-900/70";
const ICON_BOX = "flex size-11 shrink-0 items-center justify-center rounded-2xl bg-brand-100 text-brand-700 ring-1 ring-brand-200/70 dark:bg-white/10 dark:text-brand-200 dark:ring-white/15";

/** Beranda publik: perkenalan produk. Tombol masuk mengarah ke /login (atau dashboard bila sudah masuk). */
export default function Home() {
  return (
    <div className="relative flex-1 overflow-x-clip bg-gradient-to-br from-brand-50 via-white to-brand-100/80 text-zinc-900 dark:from-brand-950 dark:via-zinc-950 dark:to-brand-900 dark:text-zinc-100">
      <div aria-hidden="true" className="pointer-events-none absolute inset-x-0 top-0 h-[48rem] overflow-hidden [mask-image:linear-gradient(to_bottom,black_65%,transparent)]">
        <div className="absolute -right-24 -top-24 hidden size-[30rem] animate-float md:block rounded-full bg-brand-300/30 blur-3xl dark:bg-brand-500/15" />
        <div className="absolute -left-24 top-72 hidden size-[26rem] animate-float md:block rounded-full bg-gold-400/15 blur-3xl [animation-delay:-3s] dark:bg-gold-400/10" />
        <svg className="absolute inset-0 size-full text-brand-700 opacity-[0.05] dark:text-white dark:opacity-[0.04]" xmlns="http://www.w3.org/2000/svg">
          <defs>
            <pattern id="landing-grid" width="44" height="44" patternUnits="userSpaceOnUse">
              <path d="M44 0H0V44" fill="none" stroke="currentColor" strokeWidth="1" />
            </pattern>
          </defs>
          <rect width="100%" height="100%" fill="url(#landing-grid)" />
        </svg>
      </div>

      <header className="sticky top-0 z-30 border-b border-white/60 bg-white/90 md:bg-white/70 md:backdrop-blur-xl dark:border-zinc-800/70 dark:bg-zinc-950/90 md:dark:bg-zinc-950/60">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-5 py-3 sm:px-8">
          <Link href="/" className="flex items-center gap-3">
            <BrandMark size={40} />
            <span className="leading-tight">
              <span className="block text-lg font-extrabold tracking-tight">Sentra</span>
              <span className="block text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-700 dark:text-brand-200/80">Manajemen Sekolah</span>
            </span>
          </Link>
          <nav aria-label="Bagian halaman" className="hidden items-center gap-7 text-sm font-semibold text-zinc-700 md:flex dark:text-zinc-300">
            {NAV.map((item) => (
              <a key={item.href} href={item.href} className="transition hover:text-brand-700 dark:hover:text-brand-300">
                {item.label}
              </a>
            ))}
          </nav>
          <div className="flex items-center gap-2">
            <ThemeToggle />
            <NavCta />
          </div>
        </div>
      </header>

      <main>
        <section className="relative mx-auto grid max-w-6xl grid-cols-1 items-center gap-4 px-5 pb-8 pt-8 sm:px-8 sm:pb-16 sm:pt-10 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)] lg:pb-24 lg:pt-16">
          <div className="min-w-0 text-center lg:text-left">
            <p className="inline-flex animate-rise items-center gap-2 rounded-full border border-brand-200/80 bg-white/70 px-3 py-1 text-xs font-bold text-brand-800 dark:border-brand-700/50 dark:bg-white/5 dark:text-brand-200">
              <IconCheck width={14} height={14} /> Absensi, administrasi, dan pembelajaran
            </p>
            <h1 className="mt-5 animate-rise text-4xl font-black leading-[1.05] tracking-[-0.035em] sm:text-5xl lg:text-6xl">
              Satu platform untuk sekolah yang <span className="text-brand-600 dark:text-brand-400">rapi dan terpercaya.</span>
            </h1>
            <p className="mx-auto mt-5 max-w-xl animate-rise text-base leading-relaxed text-zinc-600 sm:text-lg lg:mx-0 dark:text-zinc-300 [animation-delay:100ms]">
              Dari absensi di gerbang, data siswa dan guru, sampai jadwal, laporan, dan pembelajaran. Satu sistem untuk admin, guru, wali kelas, kepala sekolah, dan siswa.
            </p>
            <div className="mt-8 flex animate-rise flex-wrap items-center justify-center gap-3 lg:justify-start [animation-delay:200ms]">
              <HeroCta />
              <a
                href="#kontak"
                className="inline-flex items-center justify-center rounded-2xl border border-zinc-300/80 bg-white/70 px-5 py-3 text-sm font-bold text-zinc-800 transition hover:bg-white dark:border-zinc-700 dark:bg-white/5 dark:text-zinc-100 dark:hover:bg-white/10"
              >
                Hubungi kami
              </a>
            </div>
          </div>
          <div className="relative -mx-5 min-w-0 overflow-hidden sm:mx-0 [&_canvas]:!h-full [&_canvas]:!w-full">
            <LogoStage className="h-64 w-full sm:h-80 lg:h-[28rem]" />
          </div>
        </section>

        <section id="fitur" className="mx-auto max-w-6xl scroll-mt-20 px-5 py-10 sm:px-8 sm:py-16">
          <SectionTitle eyebrow="Fitur" title="Yang dibutuhkan sekolah sehari-hari" text="Modul yang saling terhubung, dari gerbang sekolah sampai ruang kelas." />
          <div className="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {FEATURES.map(({ icon: Icon, title, text, soon }) => (
              <article key={title} className={`${CARD} p-6`}>
                <div className={ICON_BOX}>
                  <Icon width={22} height={22} />
                </div>
                <h3 className="mt-4 flex flex-wrap items-center gap-2 text-lg font-extrabold tracking-tight">
                  {title}
                  {soon ? <span className="rounded-full bg-gold-400/25 px-2 py-0.5 text-[11px] font-bold text-zinc-800 dark:text-gold-400">Segera hadir</span> : null}
                </h3>
                <p className="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">{text}</p>
              </article>
            ))}
          </div>
        </section>

        <section id="alur" className="mx-auto max-w-6xl scroll-mt-20 px-5 py-10 sm:px-8 sm:py-16">
          <SectionTitle eyebrow="Cara kerja" title="Mulai dalam tiga langkah" />
          <ol className="mt-10 grid grid-cols-1 gap-5 md:grid-cols-3">
            {STEPS.map((step, i) => (
              <li key={step.title} className={`${CARD} p-6`}>
                <span className="flex size-10 items-center justify-center rounded-full bg-brand-700 text-base font-black text-white">{i + 1}</span>
                <h3 className="mt-4 text-lg font-extrabold tracking-tight">{step.title}</h3>
                <p className="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">{step.text}</p>
              </li>
            ))}
          </ol>
        </section>

        <section className="mx-auto max-w-6xl px-5 py-10 sm:px-8 sm:py-16">
          <SectionTitle eyebrow="Untuk siapa" title="Setiap peran punya tampilannya sendiri" />
          <div className="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {ROLES.map((r) => (
              <article key={r.role} className={`${CARD} p-6`}>
                <h3 className="text-base font-extrabold tracking-tight text-brand-800 dark:text-brand-200">{r.role}</h3>
                <p className="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">{r.text}</p>
              </article>
            ))}
          </div>
        </section>

        <section className="mx-auto max-w-6xl px-5 py-10 sm:px-8 sm:py-16">
          <SectionTitle eyebrow="Keamanan" title="Data sekolah dijaga dengan serius" text="Data siswa adalah data pribadi anak. Karena itu keamanan menjadi bagian dari rancangan, bukan tambahan." />
          <div className="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-2">
            {TRUST.map(({ icon: Icon, title, text }) => (
              <article key={title} className={`${CARD} flex gap-4 p-6`}>
                <div className={ICON_BOX}>
                  <Icon width={22} height={22} />
                </div>
                <div>
                  <h3 className="text-base font-extrabold tracking-tight">{title}</h3>
                  <p className="mt-1.5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">{text}</p>
                </div>
              </article>
            ))}
          </div>
        </section>

        <section id="paket" className="mx-auto max-w-6xl scroll-mt-20 px-5 py-10 sm:px-8 sm:py-16">
          <SectionTitle eyebrow="Paket" title="Pilih sesuai kebutuhan sekolah" text="Fitur dibuka per paket. Hubungi kami untuk penawaran yang sesuai jumlah siswa Anda." />
          <div className="mt-10 grid grid-cols-1 gap-5 lg:grid-cols-3">
            {PLANS.map((plan) => (
              <article
                key={plan.name}
                className={`${CARD} flex flex-col p-6 ${plan.highlight ? "ring-2 ring-brand-500/70" : ""}`}
              >
                <h3 className="text-xl font-extrabold tracking-tight">{plan.name}</h3>
                <p className="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{plan.tagline}</p>
                <ul className="mt-5 flex-1 space-y-2.5 text-sm text-zinc-800 dark:text-zinc-200">
                  {plan.items.map((item) => (
                    <li key={item} className="flex items-start gap-2.5">
                      <span className="mt-0.5 text-brand-600 dark:text-brand-400">
                        <IconCheck width={16} height={16} />
                      </span>
                      {item}
                    </li>
                  ))}
                  {plan.soon?.map((item) => (
                    <li key={item} className="flex items-start gap-2.5 text-zinc-500 dark:text-zinc-400">
                      <span className="mt-0.5">
                        <IconCheck width={16} height={16} />
                      </span>
                      <span>
                        {item} <span className="text-xs font-bold">(segera hadir)</span>
                      </span>
                    </li>
                  ))}
                </ul>
                <a
                  href="#kontak"
                  className="mt-6 inline-flex items-center justify-center rounded-2xl border border-zinc-300/80 bg-white/80 px-4 py-2.5 text-sm font-bold text-zinc-800 transition hover:bg-white dark:border-zinc-700 dark:bg-white/5 dark:text-zinc-100 dark:hover:bg-white/10"
                >
                  Tanyakan harga
                </a>
              </article>
            ))}
          </div>
        </section>

        <section id="faq" className="mx-auto max-w-3xl scroll-mt-20 px-5 py-10 sm:px-8 sm:py-16">
          <SectionTitle eyebrow="FAQ" title="Pertanyaan yang sering muncul" />
          <div className="mt-10 space-y-3">
            {FAQ.map((item) => (
              <details key={item.q} className={`${CARD} group p-5`}>
                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 text-base font-bold tracking-tight [&::-webkit-details-marker]:hidden">
                  {item.q}
                  <span aria-hidden="true" className="text-xl leading-none text-brand-600 transition group-open:rotate-45 dark:text-brand-400">
                    +
                  </span>
                </summary>
                <p className="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">{item.a}</p>
              </details>
            ))}
          </div>
        </section>

        <section id="kontak" className="mx-auto max-w-6xl scroll-mt-20 px-5 pb-14 pt-6 sm:px-8 sm:pb-20 sm:pt-10">
          <div className="rounded-[2rem] bg-gradient-to-br from-brand-700 via-brand-800 to-brand-950 px-6 py-12 text-center text-white shadow-lift sm:px-12">
            <h2 className="text-3xl font-extrabold leading-[1.1] tracking-[-0.03em] sm:text-4xl">Siap merapikan sekolah Anda?</h2>
            <p className="mx-auto mt-3 max-w-xl text-base leading-relaxed text-brand-100/85">
              Hubungi kami untuk mendaftarkan sekolah, melihat demo, atau menanyakan paket. Sudah punya akun? Masuk dari tombol di bawah.
            </p>
            <div className="mt-7 flex flex-wrap items-center justify-center gap-3">
              {CONTACT_EMAIL ? (
                <a
                  href={`mailto:${CONTACT_EMAIL}`}
                  className="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-5 py-3 text-sm font-bold text-brand-900 transition hover:bg-brand-50"
                >
                  <IconMail width={18} height={18} /> {CONTACT_EMAIL}
                </a>
              ) : null}
              <Link
                href="/login"
                className="inline-flex items-center justify-center rounded-2xl border border-white/40 px-5 py-3 text-sm font-bold text-white transition hover:bg-white/10"
              >
                Masuk ke akun
              </Link>
            </div>
          </div>
        </section>
      </main>

      <footer className="border-t border-white/60 py-8 text-center text-xs text-zinc-500 dark:border-zinc-800/70 dark:text-zinc-400">
        © {new Date().getFullYear()} Sentra Sekolah. Platform manajemen sekolah.
      </footer>
    </div>
  );
}
