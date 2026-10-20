"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Pager } from "@/components/Pager";
import { RoleCheckboxes } from "@/components/RoleCheckboxes";
import { FormAlert, PrimaryButton, SelectField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { formatDateTime } from "@/lib/format";
import { roleLabel } from "@/lib/school-options";
import { useSchool } from "../school-context";

type Invitation = Schemas["Invitation"];
type Role = Schemas["Role"];
type StatusFilter = Schemas["InvitationStatus"] | "";

const STATUS_LABELS: Record<Schemas["InvitationStatus"], string> = {
  pending: "Menunggu",
  accepted: "Diterima",
  revoked: "Dicabut",
  expired: "Kedaluwarsa",
};

const DELIVERY_LABELS: Record<Invitation["delivery_status"], string> = {
  queued: "Menunggu dikirim",
  handed_off: "Diserahkan ke layanan email",
  failed: "Gagal dikirim",
};

export default function InvitationsPage() {
  const { code, profile } = useSchool();
  const [items, setItems] = useState<Invitation[] | null>(null);
  const [meta, setMeta] = useState<Schemas["PageMeta"] | null>(null);
  const [status, setStatus] = useState<StatusFilter>("");
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [rowError, setRowError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/invitations", {
        params: { path: { school: code }, query: { page, ...(status ? { status } : {}) } },
      });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setItems(data.data);
        setMeta(data.meta);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, page, status]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat data daftar
    void load();
  }, [load]);

  async function rowAction(invitation: Invitation, action: "resend" | "revoke") {
    setBusyId(invitation.id);
    setRowError(null);
    const params = { params: { path: { school: code, invitation: invitation.id } } };
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return action === "resend"
        ? api.POST("/schools/{school}/invitations/{invitation}/resend", params)
        : api.POST("/schools/{school}/invitations/{invitation}/revoke", params);
    });
    setBusyId(null);

    if (!outcome.ok) {
      setRowError(outcome.failure.message);
      return;
    }
    const updated = outcome.result.data?.data;
    if (updated) setItems((current) => (current ?? []).map((i) => (i.id === updated.id ? updated : i)));
  }

  return (
    <>
      <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Undangan</h1>

      <InviteForm
        code={code}
        onCreated={(created) => {
          setItems((current) => [created, ...(current ?? [])]);
          setMeta((current) => (current ? { ...current, total: current.total + 1 } : current));
        }}
      />

      <div className="flex flex-wrap items-end justify-between gap-3">
        <h2 className="text-lg font-bold text-zinc-900 dark:text-zinc-50">Daftar undangan</h2>
        <div className="w-48">
          <SelectField
            label="Status"
            name="status"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as StatusFilter);
              setPage(1);
            }}
          >
            <option value="">Semua</option>
            <option value="pending">Menunggu</option>
            <option value="accepted">Diterima</option>
            <option value="revoked">Dicabut</option>
            <option value="expired">Kedaluwarsa</option>
          </SelectField>
        </div>
      </div>

      {error ? <FormAlert>{error}</FormAlert> : null}
      {rowError ? <FormAlert>{rowError}</FormAlert> : null}
      {items === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}
      {items && items.length === 0 ? <p className="text-zinc-600 dark:text-zinc-400">Belum ada undangan.</p> : null}

      {items && items.length > 0 ? (
        <div className="overflow-x-auto rounded-2xl border border-zinc-200/70 bg-white shadow-card dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 bg-zinc-50/70 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-semibold">Email</th>
                <th scope="col" className="px-4 py-3 font-semibold">Peran</th>
                <th scope="col" className="px-4 py-3 font-semibold">Status</th>
                <th scope="col" className="px-4 py-3 font-semibold">Berlaku sampai</th>
                <th scope="col" className="px-4 py-3 font-semibold">Aksi</th>
              </tr>
            </thead>
            <tbody>
              {items.map((invitation) => (
                <tr key={invitation.id} className="border-b border-zinc-100 align-top last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3 text-zinc-900 dark:text-zinc-100">{invitation.email}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {invitation.roles.map(roleLabel).join(", ")}
                  </td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {STATUS_LABELS[invitation.status]}
                    {invitation.status === "pending" ? (
                      <p className="text-xs text-zinc-500">Email: {DELIVERY_LABELS[invitation.delivery_status]}</p>
                    ) : null}
                    {invitation.delivery_error ? (
                      <p className="text-xs text-red-700 dark:text-red-400">{invitation.delivery_error}</p>
                    ) : null}
                  </td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {formatDateTime(invitation.expires_at, profile.timezone)}
                  </td>
                  <td className="px-4 py-3">
                    {invitation.status === "pending" ? (
                      <div className="flex gap-3">
                        <button
                          type="button"
                          disabled={busyId === invitation.id}
                          onClick={() => rowAction(invitation, "resend")}
                          className="font-medium text-brand-700 hover:underline disabled:opacity-60 dark:text-brand-400"
                        >
                          Kirim ulang
                        </button>
                        <button
                          type="button"
                          disabled={busyId === invitation.id}
                          onClick={() => rowAction(invitation, "revoke")}
                          className="font-medium text-red-700 hover:underline disabled:opacity-60 dark:text-red-400"
                        >
                          Cabut
                        </button>
                      </div>
                    ) : (
                      <span className="text-zinc-400">-</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {meta ? <Pager meta={meta} onPage={setPage} /> : null}
    </>
  );
}

function InviteForm({ code, onCreated }: { code: string; onCreated: (invitation: Invitation) => void }) {
  const [roles, setRoles] = useState<Role[]>([]);
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const [sent, setSent] = useState<Invitation | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const email = String(new FormData(form).get("email") ?? "").trim();
    setPending(true);
    setFailure(null);
    setSent(null);

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/schools/{school}/invitations", { params: { path: { school: code } }, body: { email, roles } });
    });
    setPending(false);

    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    const created = outcome.result.data?.data;
    if (created) {
      setSent(created);
      onCreated(created);
    }
    form.reset();
    setRoles([]);
  }

  return (
    <section className="rounded-2xl border border-zinc-200/70 bg-white shadow-card p-6 dark:border-zinc-800 dark:bg-zinc-900">
      <h2 className="text-lg font-bold text-zinc-900 dark:text-zinc-50">Undang anggota baru</h2>
      <form onSubmit={submit} noValidate className="mt-4 max-w-xl space-y-4">
        {failure ? <FormAlert>{failure.message}</FormAlert> : null}
        {sent ? (
          <FormAlert tone="success">
            Undangan untuk {sent.email} dibuat. Status pengiriman: {DELIVERY_LABELS[sent.delivery_status]}.
          </FormAlert>
        ) : null}
        <TextField label="Email" name="email" type="email" autoComplete="off" error={failure?.fields.email} required />
        <RoleCheckboxes value={roles} onChange={setRoles} error={failure?.fields.roles ?? failure?.fields["roles.0"]} />
        <PrimaryButton type="submit" pending={pending}>
          Kirim undangan
        </PrimaryButton>
      </form>
    </section>
  );
}
