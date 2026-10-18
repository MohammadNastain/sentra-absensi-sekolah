import type { Schemas } from "@sentra/api-client";
import { SecondaryButton } from "@/components/ui";

/** Paginasi sederhana (sebelumnya/berikutnya) berdasarkan `meta` dari API. */
export function Pager({ meta, onPage }: { meta: Schemas["PageMeta"]; onPage: (page: number) => void }) {
  if (meta.last_page <= 1) return null;
  return (
    <nav aria-label="Halaman" className="flex items-center justify-between gap-3 text-sm text-zinc-600 dark:text-zinc-400">
      <span>
        Halaman {meta.current_page} dari {meta.last_page} · {meta.total} data
      </span>
      <div className="flex gap-2">
        <SecondaryButton type="button" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
          Sebelumnya
        </SecondaryButton>
        <SecondaryButton
          type="button"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
        >
          Berikutnya
        </SecondaryButton>
      </div>
    </nav>
  );
}
