"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Pager } from "@/components/Pager";
import { FormAlert, PrimaryButton, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { formatDateTime } from "@/lib/format";
import { useSchool } from "../school-context";

type AuditLog = Schemas["AuditLog"];

interface Filters {
  action: string;
  entity_type: string;
}

const NO_FILTERS: Filters = { action: "", entity_type: "" };

/** Audit log sekolah: hanya baca. Diisi otomatis oleh setiap perubahan penting. */
export default function AuditPage() {
  const { code, profile } = useSchool();
  const [logs, setLogs] = useState<AuditLog[] | null>(null);
  const [meta, setMeta] = useState<Schemas["PageMeta"] | null>(null);
  const [filters, setFilters] = useState<Filters>(NO_FILTERS);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const query = {
        page,
        ...(filters.action ? { action: filters.action } : {}),
        ...(filters.entity_type ? { entity_type: filters.entity_type } : {}),
      };
      const { data, error: problem } = await api.GET("/schools/{school}/audit-logs", {
        params: { path: { school: code }, query },
      });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setLogs(data.data);
        setMeta(data.meta);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, page, filters]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat data daftar
    void load();
  }, [load]);

  return (
    <>
      <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Audit</h1>

      <form
        noValidate
        className="flex flex-wrap items-end gap-3"
        onSubmit={(event) => {
          event.preventDefault();
          const form = new FormData(event.currentTarget);
          setFilters({
            action: String(form.get("action") ?? "").trim(),
            entity_type: String(form.get("entity_type") ?? "").trim(),
          });
          setPage(1);
        }}
      >
        <div className="w-56">
          <TextField label="Aksi (awalan)" name="action" placeholder="school.member" />
        </div>
        <div className="w-56">
          <TextField label="Jenis data" name="entity_type" placeholder="school_membership" />
        </div>
        <PrimaryButton type="submit">Terapkan</PrimaryButton>
      </form>

      {error ? <FormAlert>{error}</FormAlert> : null}
      {logs === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}
      {logs && logs.length === 0 ? <p className="text-zinc-600 dark:text-zinc-400">Belum ada catatan audit.</p> : null}

      {logs && logs.length > 0 ? (
        <ul className="space-y-3">
          {logs.map((log) => (
            <li
              key={log.id}
              className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
            >
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-mono text-sm font-medium text-zinc-900 dark:text-zinc-100">{log.action}</p>
                <time dateTime={log.created_at} className="text-sm text-zinc-500">
                  {formatDateTime(log.created_at, profile.timezone)}
                </time>
              </div>
              <p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                oleh {log.actor.name ?? log.actor.type}
                {log.reason ? ` · alasan: ${log.reason}` : ""}
              </p>
              {log.before || log.after ? (
                <details className="mt-2 text-sm">
                  <summary className="cursor-pointer text-emerald-700 dark:text-emerald-400">Rincian perubahan</summary>
                  <div className="mt-2 grid gap-3 sm:grid-cols-2">
                    <Snapshot title="Sebelum" value={log.before} />
                    <Snapshot title="Sesudah" value={log.after} />
                  </div>
                </details>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}

      {meta ? <Pager meta={meta} onPage={setPage} /> : null}
    </>
  );
}

function Snapshot({ title, value }: { title: string; value: Record<string, unknown> | null }) {
  return (
    <div>
      <p className="text-zinc-500">{title}</p>
      <pre className="mt-1 overflow-x-auto rounded-lg bg-zinc-100 p-3 text-xs text-zinc-800 dark:bg-zinc-950 dark:text-zinc-200">
        {value ? JSON.stringify(value, null, 2) : "-"}
      </pre>
    </div>
  );
}
