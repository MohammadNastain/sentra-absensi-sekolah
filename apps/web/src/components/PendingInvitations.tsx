"use client";

import type { Schemas } from "@sentra/api-client";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { FormAlert } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt } from "@/lib/errors";
import { roleLabel } from "@/lib/school-options";

type MyInvitation = Schemas["MyInvitation"];

/** Undangan tertunda untuk email akun yang sedang login: terima atau tolak tanpa membuka email. */
export function PendingInvitations() {
  const router = useRouter();
  const [items, setItems] = useState<MyInvitation[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/me/invitations")
      .then(({ data }) => {
        if (!cancelled) setItems(data?.data ?? []);
      })
      .catch(() => {
        if (!cancelled) setItems([]);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  async function accept(invitation: MyInvitation) {
    setBusyId(invitation.id);
    setError(null);
    const outcome = await attempt(() =>
      api.POST("/me/invitations/{invitation}/accept", { params: { path: { invitation: invitation.id } } }),
    );
    setBusyId(null);

    if (!outcome.ok) {
      setError(outcome.failure.message);
      return;
    }
    const code = outcome.result.data?.data.school.code;
    router.replace(code ? `/schools/${code}` : "/");
  }

  async function decline(invitation: MyInvitation) {
    setBusyId(invitation.id);
    setError(null);
    const outcome = await attempt(() =>
      api.POST("/me/invitations/{invitation}/decline", { params: { path: { invitation: invitation.id } } }),
    );
    setBusyId(null);

    if (!outcome.ok) {
      setError(outcome.failure.message);
      return;
    }
    setItems((current) => (current ?? []).filter((i) => i.id !== invitation.id));
  }

  if (!items || items.length === 0) return null;

  return (
    <section aria-labelledby="pending-title" className="space-y-3">
      <h2 id="pending-title" className="text-lg font-bold text-zinc-900 dark:text-zinc-50">
        Undangan untuk Anda
      </h2>
      {error ? <FormAlert>{error}</FormAlert> : null}
      <ul className="space-y-3">
        {items.map((invitation) => (
          <li
            key={invitation.id}
            className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-zinc-200/70 bg-white shadow-card p-4 dark:border-zinc-800 dark:bg-zinc-900"
          >
            <div>
              <p className="font-medium text-zinc-900 dark:text-zinc-50">{invitation.school.name ?? "Sekolah"}</p>
              <p className="text-sm text-zinc-600 dark:text-zinc-400">
                {invitation.roles.map(roleLabel).join(", ")}
              </p>
            </div>
            <div className="flex gap-2">
              <button
                type="button"
                disabled={busyId === invitation.id}
                onClick={() => decline(invitation)}
                className="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
              >
                Tolak
              </button>
              <button
                type="button"
                disabled={busyId === invitation.id}
                onClick={() => accept(invitation)}
                className="rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-800 disabled:opacity-60"
              >
                Terima
              </button>
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}
