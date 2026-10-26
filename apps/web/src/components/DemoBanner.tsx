import { IconCheck } from "@/components/icons";
import { waLink } from "@/lib/contact";

/** Penanda bagi pengunjung demo: data contoh yang direset berkala, plus ajakan mendaftarkan sekolah sendiri. */
export function DemoBanner() {
  return (
    <div role="status" className="flex flex-col gap-3 rounded-2xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900 sm:flex-row sm:items-center dark:border-brand-800 dark:bg-brand-950/60 dark:text-brand-100">
      <div className="flex min-w-0 flex-1 items-start gap-3">
        <IconCheck className="mt-0.5 shrink-0" />
        <p>
          <strong>Mode demo.</strong> Ini sekolah contoh dengan data fiktif. Silakan coba semua fitur; data direset setiap hari.
        </p>
      </div>
      <a
        href={waLink("Halo, saya sudah mencoba demo Sentra Sekolah dan ingin mendaftarkan sekolah saya.")}
        target="_blank"
        rel="noopener noreferrer"
        className="self-start rounded-xl bg-brand-700 px-3.5 py-1.5 text-sm font-semibold text-white transition hover:bg-brand-800 sm:self-auto"
      >
        Daftarkan sekolah saya
      </a>
    </div>
  );
}
