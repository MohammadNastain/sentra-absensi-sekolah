"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { FormAlert, PrimaryButton, SecondaryButton, TextAreaField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { formatDateTime } from "@/lib/format";
import { useSchool } from "../school-context";

type Device = Schemas["Device"];

type PendingAction =
  | { kind: "create" }
  | { kind: "rename"; device: Device }
  | { kind: "revoke"; device: Device }
  | { kind: "code"; device: Device; code: string };

const STATUS_LABEL: Record<string, string> = { pending_pairing: "Menunggu pairing", active: "Terpasang", revoked: "Dicabut" };

function formatCode(code: string): string {
  return `${code.slice(0, 4)}-${code.slice(4)}`;
}

export default function DevicesPage() {
  const { code, can, profile } = useSchool();
  const canManage = can("school.devices.manage");
  const [devices, setDevices] = useState<Device[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [action, setAction] = useState<PendingAction | null>(null);
  const [renewing, setRenewing] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/devices", { params: { path: { school: code } } });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setDevices(data.data);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat data daftar
    void load();
    // Status online berubah tanpa aksi pengguna; segarkan pelan-pelan.
    const timer = setInterval(() => void load(), 30_000);
    return () => clearInterval(timer);
  }, [load]);

  async function renew(device: Device) {
    setRenewing(device.id);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/schools/{school}/devices/{device}/pairing-code", { params: { path: { school: code, device: device.id } } });
    });
    setRenewing(null);
    if (!outcome.ok) setError(outcome.failure.message);
    else if (outcome.result.data) {
      setError(null);
      setAction({ kind: "code", device: outcome.result.data.data.device, code: outcome.result.data.data.pairing_code });
      void load();
    }
  }

  const close = () => setAction(null);
  // Menutup dialog satu jenis memicu event close secara asinkron; jangan sampai menimpa dialog jenis lain yang baru dibuka.
  const closeKind = (kind: PendingAction["kind"]) => () => setAction((current) => (current?.kind === kind ? null : current));
  const done = () => {
    setAction(null);
    void load();
  };

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Perangkat terminal</h1>
        {canManage ? <PrimaryButton onClick={() => setAction({ kind: "create" })}>Daftarkan perangkat</PrimaryButton> : null}
      </div>
      <p className="text-sm text-zinc-600 dark:text-zinc-400">
        Terminal absensi di {profile.name}. Daftarkan perangkat, lalu buka <code className="rounded bg-zinc-100 px-1 dark:bg-zinc-800">/terminal</code> di
        perangkat itu dan masukkan kode pairing.
      </p>

      {error ? <FormAlert>{error}</FormAlert> : null}
      {devices === null && !error ? (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      ) : null}
      {devices && devices.length === 0 ? <p className="text-zinc-600 dark:text-zinc-400">Belum ada perangkat terdaftar.</p> : null}

      {devices && devices.length > 0 ? (
        <div className="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-zinc-200 text-zinc-600 dark:border-zinc-800 dark:text-zinc-400">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">Nama</th>
                <th scope="col" className="px-4 py-3 font-medium">Status</th>
                <th scope="col" className="px-4 py-3 font-medium">Terakhir terlihat</th>
                {canManage ? <th scope="col" className="px-4 py-3 font-medium">Aksi</th> : null}
              </tr>
            </thead>
            <tbody>
              {devices.map((device) => (
                <tr key={device.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                  <td className="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{device.name}</td>
                  <td className="px-4 py-3">
                    <StatusPill device={device} />
                    {device.status === "revoked" && device.revoked_reason ? (
                      <p className="mt-1 text-xs text-zinc-500">{device.revoked_reason}</p>
                    ) : null}
                  </td>
                  <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                    {device.last_seen_at ? formatDateTime(device.last_seen_at, profile.timezone) : "—"}
                  </td>
                  {canManage ? (
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-3">
                        {device.status !== "revoked" ? (
                          <button
                            type="button"
                            className="font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                            onClick={() => setAction({ kind: "rename", device })}
                          >
                            Ubah nama
                          </button>
                        ) : null}
                        {device.status === "pending_pairing" ? (
                          <button
                            type="button"
                            disabled={renewing === device.id}
                            className="font-medium text-emerald-700 hover:underline disabled:opacity-60 dark:text-emerald-400"
                            onClick={() => void renew(device)}
                          >
                            Kode baru
                          </button>
                        ) : null}
                        {device.status !== "revoked" ? (
                          <button
                            type="button"
                            className="font-medium text-red-700 hover:underline dark:text-red-400"
                            onClick={() => setAction({ kind: "revoke", device })}
                          >
                            Cabut
                          </button>
                        ) : null}
                      </div>
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      <Dialog open={action?.kind === "create"} title="Daftarkan perangkat" onClose={closeKind("create")}>
        {action?.kind === "create" ? (
          <NameForm
            code={code}
            device={null}
            onCancel={close}
            onDone={(result) => {
              if (result) setAction({ kind: "code", device: result.device, code: result.pairing_code });
              void load();
            }}
          />
        ) : null}
      </Dialog>

      <Dialog open={action?.kind === "rename"} title="Ubah nama perangkat" onClose={closeKind("rename")}>
        {action?.kind === "rename" ? <NameForm code={code} device={action.device} onCancel={close} onDone={done} /> : null}
      </Dialog>

      <Dialog open={action?.kind === "revoke"} title="Cabut perangkat" onClose={closeKind("revoke")}>
        {action?.kind === "revoke" ? <RevokeForm code={code} device={action.device} onCancel={close} onDone={done} /> : null}
      </Dialog>

      <Dialog open={action?.kind === "code"} title="Kode pairing" onClose={closeKind("code")}>
        {action?.kind === "code" ? (
          <div className="space-y-4">
            <p className="text-zinc-700 dark:text-zinc-300">
              Masukkan kode ini di <code>/terminal</code> pada perangkat <strong>{action.device.name}</strong> dalam 10 menit. Kode hanya tampil sekali.
            </p>
            <p data-testid="pairing-code" className="rounded-lg bg-zinc-100 py-4 text-center font-mono text-3xl tracking-widest text-zinc-900 dark:bg-zinc-800 dark:text-zinc-50">
              {formatCode(action.code)}
            </p>
            <div className="flex justify-end">
              <PrimaryButton type="button" onClick={done}>
                Selesai
              </PrimaryButton>
            </div>
          </div>
        ) : null}
      </Dialog>
    </>
  );
}

function StatusPill({ device }: { device: Device }) {
  const tone =
    device.status === "revoked"
      ? "bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
      : device.status === "pending_pairing"
        ? "bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300"
        : device.online
          ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
          : "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300";
  const label = device.status === "active" ? (device.online ? "Online" : "Offline") : STATUS_LABEL[device.status];

  return <span className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-medium ${tone}`}>{label}</span>;
}

function NameForm({
  device,
  code,
  onCancel,
  onDone,
}: {
  device: Device | null;
  code: string;
  onCancel: () => void;
  onDone: (created?: { device: Device; pairing_code: string }) => void;
}) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const name = String(new FormData(event.currentTarget).get("name") ?? "").trim();
    setPending(true);
    setFailure(null);

    if (device) {
      const outcome = await attempt(async () => {
        await api.ensureCsrfCookie();
        return api.PATCH("/schools/{school}/devices/{device}", { params: { path: { school: code, device: device.id } }, body: { name } });
      });
      setPending(false);
      if (!outcome.ok) setFailure(outcome.failure);
      else onDone();
      return;
    }

    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/schools/{school}/devices", { params: { path: { school: code } }, body: { name } });
    });
    setPending(false);
    if (!outcome.ok) setFailure(outcome.failure);
    else onDone(outcome.result.data?.data);
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <TextField label="Nama perangkat" name="name" defaultValue={device?.name} error={failure?.fields.name} required maxLength={100} />
      <p className="text-sm text-zinc-600 dark:text-zinc-400">Contoh: Gerbang utama. Nama membantu membedakan perangkat di daftar dan di jejak audit.</p>
      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onCancel}>
          Batal
        </SecondaryButton>
        <PrimaryButton type="submit" pending={pending}>
          {device ? "Simpan" : "Daftarkan"}
        </PrimaryButton>
      </div>
    </form>
  );
}

function RevokeForm({ device, code, onCancel, onDone }: { device: Device; code: string; onCancel: () => void; onDone: () => void }) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const reason = String(new FormData(event.currentTarget).get("reason") ?? "").trim();
    setPending(true);
    setFailure(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/schools/{school}/devices/{device}/revoke", { params: { path: { school: code, device: device.id } }, body: { reason } });
    });
    setPending(false);
    if (!outcome.ok) setFailure(outcome.failure);
    else onDone();
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <p className="text-zinc-700 dark:text-zinc-300">
        Cabut <strong>{device.name}</strong>? Terminal ini langsung tidak bisa mencatat absensi dan harus didaftarkan ulang.
      </p>
      <TextAreaField label="Alasan" name="reason" error={failure?.fields.reason} required />
      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onCancel}>
          Batal
        </SecondaryButton>
        <PrimaryButton type="submit" pending={pending}>
          Cabut
        </PrimaryButton>
      </div>
    </form>
  );
}
