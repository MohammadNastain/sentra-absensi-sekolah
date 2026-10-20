"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { Pager } from "@/components/Pager";
import { RoleCheckboxes } from "@/components/RoleCheckboxes";
import { FormAlert, PrimaryButton, SecondaryButton, SelectField, TextAreaField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { formatDateTime } from "@/lib/format";
import { roleLabel } from "@/lib/school-options";
import { useSchool } from "../school-context";

type Member = Schemas["Member"];
type Role = Schemas["Role"];
type StatusFilter = Schemas["MembershipStatus"] | "";

type PendingAction =
  | { kind: "roles"; member: Member }
  | { kind: "suspend"; member: Member }
  | { kind: "end"; member: Member }
  | { kind: "reset"; member: Member }
  | { kind: "reactivate"; member: Member };

const STATUS_LABELS: Record<Schemas["MembershipStatus"], string> = {
  active: "Aktif",
  suspended: "Ditangguhkan",
  ended: "Berakhir",
};

const ACTION_TITLES: Record<PendingAction["kind"], string> = {
  roles: "Ubah peran",
  suspend: "Tangguhkan anggota",
  end: "Akhiri keanggotaan",
  reset: "Kirim tautan reset password",
  reactivate: "Aktifkan kembali anggota",
};

export default function MembersPage() {
  const { code, profile, meId, can } = useSchool();
  const canManage = can("school.members.manage");
  const [members, setMembers] = useState<Member[] | null>(null);
  const [meta, setMeta] = useState<Schemas["PageMeta"] | null>(null);
  const [status, setStatus] = useState<StatusFilter>("");
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [action, setAction] = useState<PendingAction | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/members", {
        params: { path: { school: code }, query: { page, ...(status ? { status } : {}) } },
      });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setMembers(data.data);
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

  return (
    <>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Anggota</h1>
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
            <option value="active">Aktif</option>
            <option value="suspended">Ditangguhkan</option>
            <option value="ended">Berakhir</option>
          </SelectField>
        </div>
      </div>

      {error ? <FormAlert>{error}</FormAlert> : null}
      {notice ? (
        <p role="status" className="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
          {notice}
        </p>
      ) : null}
      {members === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}
      {members && members.length === 0 ? <p className="text-zinc-600 dark:text-zinc-400">Tidak ada anggota.</p> : null}

      {members && members.length > 0 ? (
        <div className="overflow-x-auto rounded-2xl border border-zinc-200/70 bg-white shadow-card dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 bg-zinc-50/70 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-semibold">Nama</th>
                <th scope="col" className="px-4 py-3 font-semibold">Peran</th>
                <th scope="col" className="px-4 py-3 font-semibold">Status</th>
                <th scope="col" className="px-4 py-3 font-semibold">Bergabung</th>
                {canManage ? <th scope="col" className="px-4 py-3 font-semibold">Aksi</th> : null}
              </tr>
            </thead>
            <tbody>
              {members.map((member) => (
                <tr key={member.id} className="border-b border-zinc-100 align-top last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3">
                    <p className="font-medium text-zinc-900 dark:text-zinc-100">
                      {member.user.name}
                      {member.user.id === meId ? <span className="ml-2 text-xs text-zinc-500">(Anda)</span> : null}
                    </p>
                    <p className="text-zinc-600 dark:text-zinc-400">{member.user.email}</p>
                  </td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{member.roles.map(roleLabel).join(", ")}</td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {STATUS_LABELS[member.status]}
                    {member.status_reason ? (
                      <p className="text-xs text-zinc-500">Alasan: {member.status_reason}</p>
                    ) : null}
                  </td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {formatDateTime(member.joined_at, profile.timezone)}
                  </td>
                  {canManage ? (
                    <td className="px-4 py-3">
                      <RowActions member={member} isSelf={member.user.id === meId} onAction={setAction} />
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {meta ? <Pager meta={meta} onPage={setPage} /> : null}

      <Dialog open={action !== null} title={action ? ACTION_TITLES[action.kind] : ""} onClose={() => setAction(null)}>
        {action ? (
          <ActionForm
            key={`${action.kind}:${action.member.id}`}
            action={action}
            code={code}
            onCancel={() => setAction(null)}
            onDone={(updated) => {
              if (updated) {
                setNotice(null);
                setMembers((current) => (current ?? []).map((m) => (m.id === updated.id ? updated : m)));
              } else {
                setNotice(`Tautan reset password dikirim ke ${action.member.user.email}.`);
              }
              setAction(null);
            }}
          />
        ) : null}
      </Dialog>
    </>
  );
}

function RowActions({
  member,
  isSelf,
  onAction,
}: {
  member: Member;
  isSelf: boolean;
  onAction: (action: PendingAction) => void;
}) {
  if (member.status === "ended") return <span className="text-zinc-400">-</span>;
  const link = "font-medium text-emerald-700 hover:underline dark:text-emerald-400";
  return (
    <div className="flex flex-wrap gap-x-3 gap-y-1">
      {!isSelf ? (
        <button type="button" className={link} onClick={() => onAction({ kind: "roles", member })}>
          Ubah peran
        </button>
      ) : null}
      {member.status === "active" && !isSelf ? (
        <button type="button" className={link} onClick={() => onAction({ kind: "reset", member })}>
          Kirim reset password
        </button>
      ) : null}
      {member.status === "active" ? (
        <button type="button" className={link} onClick={() => onAction({ kind: "suspend", member })}>
          Tangguhkan
        </button>
      ) : (
        <button type="button" className={link} onClick={() => onAction({ kind: "reactivate", member })}>
          Aktifkan
        </button>
      )}
      <button
        type="button"
        className="font-medium text-red-700 hover:underline dark:text-red-400"
        onClick={() => onAction({ kind: "end", member })}
      >
        Akhiri
      </button>
    </div>
  );
}

function ActionForm({
  action,
  code,
  onCancel,
  onDone,
}: {
  action: PendingAction;
  code: string;
  onCancel: () => void;
  onDone: (member: Member | null) => void;
}) {
  const { member, kind } = action;
  const [roles, setRoles] = useState<Role[]>(member.roles);
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const reason = String(new FormData(event.currentTarget).get("reason") ?? "").trim();
    setPending(true);
    setFailure(null);

    const path = { params: { path: { school: code, membership: member.id } } };
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      switch (kind) {
        case "roles":
          return api.PUT("/schools/{school}/members/{membership}/roles", { ...path, body: { roles, reason } });
        case "suspend":
          return api.POST("/schools/{school}/members/{membership}/suspend", { ...path, body: { reason } });
        case "end":
          return api.POST("/schools/{school}/members/{membership}/end", { ...path, body: { reason } });
        case "reset":
          return api.POST("/schools/{school}/members/{membership}/password-reset", { ...path, body: { reason } });
        case "reactivate":
          return api.POST("/schools/{school}/members/{membership}/reactivate", {
            ...path,
            body: { reason: reason || null },
          });
      }
    });
    setPending(false);

    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    if (kind === "reset") onDone(null);
    else if (outcome.result.data && "data" in outcome.result.data) onDone(outcome.result.data.data);
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      <p className="text-sm text-zinc-600 dark:text-zinc-400">
        {member.user.name} · {member.user.email}
      </p>
      {kind === "reset" ? (
        <p className="text-sm text-zinc-700 dark:text-zinc-300">
          Tautan dikirim ke email anggota; hanya pemilik akun yang dapat menetapkan password baru. Anda tidak akan melihat password.
        </p>
      ) : null}
      {kind === "end" ? (
        <FormAlert>Keanggotaan yang diakhiri tidak dapat dibuka kembali; user harus diundang ulang.</FormAlert>
      ) : null}
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      {kind === "roles" ? <RoleCheckboxes value={roles} onChange={setRoles} error={failure?.fields.roles} /> : null}
      <TextAreaField
        label={kind === "reactivate" ? "Alasan (opsional)" : "Alasan"}
        name="reason"
        error={failure?.fields.reason}
        hint={kind === "reactivate" ? undefined : "Minimal 5 karakter. Tercatat di audit."}
      />
      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onCancel}>
          Batal
        </SecondaryButton>
        <PrimaryButton type="submit" pending={pending}>
          {kind === "reset" ? "Kirim tautan" : "Simpan"}
        </PrimaryButton>
      </div>
    </form>
  );
}
