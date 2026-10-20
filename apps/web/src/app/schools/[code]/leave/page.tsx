"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { FormAlert, PrimaryButton, SecondaryButton, SelectField, TextAreaField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { formatDate } from "@/lib/format";
import { useSchool } from "../school-context";
import { schoolToday } from "../attendance/absence-panels";

type Leave = Schemas["LeaveRequest"];
type Student = Schemas["AssistStudent"];
type LeaveType = Schemas["CreateLeaveRequest"]["leave_type"];

const TYPE_LABELS: Record<string, string> = { izin: "Izin", sakit: "Sakit", dispensasi: "Dispensasi" };
const STATUS_LABELS: Record<string, string> = { pending: "Menunggu", approved: "Disetujui", rejected: "Ditolak", cancelled: "Dibatalkan" };
const CARD = "space-y-4 rounded-2xl border border-zinc-200/70 bg-white shadow-card p-6 dark:border-zinc-800 dark:bg-zinc-900";

/** Izin/sakit/dispensasi: pengajuan, lampiran, keputusan, dan pembatalan. */
export default function LeavePage() {
  const { code, can, profile } = useSchool();
  const canApprove = can("school.leave.approve");
  const [items, setItems] = useState<Leave[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/leave-requests", { params: { path: { school: code } } });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setItems(data.data);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat daftar awal
    void load();
  }, [load]);

  return (
    <>
      <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Izin dan sakit</h1>
      {notice ? <FormAlert tone="success">{notice}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}
      <NewLeaveForm
        timeZone={profile.timezone}
        onCreated={async () => {
          setNotice("Pengajuan tersimpan dan menunggu keputusan.");
          await load();
        }}
      />
      <section aria-labelledby="leave-list-title" className={CARD}>
        <h2 id="leave-list-title" className="text-lg font-bold text-zinc-900 dark:text-zinc-50">
          Daftar pengajuan
        </h2>
        {items === null && !error ? (
          <p role="status" className="text-sm text-zinc-600 dark:text-zinc-400">
            Memuat…
          </p>
        ) : null}
        {items?.length === 0 ? <p className="text-sm text-zinc-600 dark:text-zinc-400">Belum ada pengajuan.</p> : null}
        <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
          {items?.map((item) => (
            <LeaveRow
              key={item.id}
              item={item}
              canApprove={canApprove}
              onChanged={async (message) => {
                setNotice(message);
                await load();
              }}
            />
          ))}
        </ul>
      </section>
    </>
  );
}

function NewLeaveForm({ timeZone, onCreated }: { timeZone: string; onCreated: () => Promise<void> }) {
  const { code } = useSchool();
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<Student[] | null>(null);
  const [student, setStudent] = useState<Student | null>(null);
  const [type, setType] = useState<LeaveType>("sakit");
  const [start, setStart] = useState(() => schoolToday(timeZone));
  const [end, setEnd] = useState(() => schoolToday(timeZone));
  const [reason, setReason] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});

  async function search(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    if (query.trim().length < 2) {
      setError("Ketik minimal 2 karakter nama atau nomor induk.");
      return;
    }
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/leave-requests/students", { params: { path: { school: code }, query: { q: query.trim() } } });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) setResults(data.data);
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!student) return;
    setPending(true);
    setError(null);
    setFields({});
    try {
      const { data, error: problem } = await api.POST("/schools/{school}/leave-requests", {
        params: { path: { school: code } },
        body: { student_id: student.id, leave_type: type, start_date: start, end_date: end, reason: reason.trim() },
      });
      if (problem !== undefined) {
        const failure = toFormFailure(problem);
        setError(failure.message);
        setFields(failure.fields);
        return;
      }
      if (file && data) {
        const upload = await api.POST("/schools/{school}/leave-requests/{leave}/attachment", {
          params: { path: { school: code, leave: data.data.id } },
          body: { file: "" },
          bodySerializer: () => {
            const form = new FormData();
            form.append("file", file);
            return form;
          },
        });
        if (upload.error !== undefined) {
          setError(`Pengajuan tersimpan, tetapi lampiran gagal: ${toFormFailure(upload.error).message}`);
        }
      }
      setStudent(null);
      setResults(null);
      setQuery("");
      setReason("");
      setFile(null);
      await onCreated();
    } catch (e) {
      setError(toFormFailure(e).message);
    } finally {
      setPending(false);
    }
  }

  return (
    <section aria-labelledby="leave-new-title" className={CARD}>
      <h2 id="leave-new-title" className="text-lg font-bold text-zinc-900 dark:text-zinc-50">
        Ajukan izin
      </h2>
      {error ? <FormAlert>{error}</FormAlert> : null}
      {student === null ? (
        <>
          <form noValidate onSubmit={search} className="flex flex-wrap items-end gap-3">
            <div className="w-72">
              <TextField label="Cari siswa" name="leave-q" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Nama atau nomor induk" autoComplete="off" />
            </div>
            <PrimaryButton type="submit">Cari</PrimaryButton>
          </form>
          {results !== null && results.length === 0 ? <p className="text-sm text-zinc-600 dark:text-zinc-400">Tidak ada siswa aktif yang cocok.</p> : null}
          <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {results?.map((s) => (
              <li key={s.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                <span>
                  <span className="font-medium text-zinc-900 dark:text-zinc-100">{s.full_name}</span>
                  <span className="ml-2 text-zinc-500">
                    {s.student_number} · {s.class_name}
                  </span>
                </span>
                <SecondaryButton type="button" onClick={() => setStudent(s)}>
                  Pilih
                </SecondaryButton>
              </li>
            ))}
          </ul>
        </>
      ) : (
        <form noValidate onSubmit={submit} className="max-w-lg space-y-4">
          <p className="text-sm">
            <span className="font-medium text-zinc-900 dark:text-zinc-100">{student.full_name}</span>
            <span className="ml-2 text-zinc-500">
              {student.student_number} · {student.class_name}
            </span>
          </p>
          <SelectField label="Jenis" name="leave_type" value={type} onChange={(e) => setType(e.target.value as LeaveType)} error={fields.leave_type}>
            {Object.entries(TYPE_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </SelectField>
          <div className="flex gap-3">
            <TextField label="Mulai" name="start_date" type="date" value={start} onChange={(e) => setStart(e.target.value)} error={fields.start_date} />
            <TextField label="Sampai" name="end_date" type="date" value={end} onChange={(e) => setEnd(e.target.value)} error={fields.end_date} />
          </div>
          <TextAreaField label="Alasan" name="reason" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} error={fields.reason} />
          <div>
            <label htmlFor="leave-file" className="block text-sm font-medium text-zinc-800 dark:text-zinc-200">
              Lampiran (opsional; PDF, JPG, PNG maks. 5 MB)
            </label>
            <input id="leave-file" type="file" accept="application/pdf,image/jpeg,image/png" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="mt-1 block text-sm" />
          </div>
          <div className="flex gap-3">
            <PrimaryButton type="submit" pending={pending} disabled={reason.trim().length < 5}>
              Ajukan
            </PrimaryButton>
            <SecondaryButton type="button" onClick={() => setStudent(null)}>
              Batal
            </SecondaryButton>
          </div>
        </form>
      )}
    </section>
  );
}

type Action = "approve" | "reject" | "cancel";

function LeaveRow({ item, canApprove, onChanged }: { item: Leave; canApprove: boolean; onChanged: (message: string) => Promise<void> }) {
  const { code } = useSchool();
  const [action, setAction] = useState<Action | null>(null);
  const [note, setNote] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const path = { school: code, leave: item.id };
  const canCancel = item.status === "pending" || (item.status === "approved" && canApprove);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (action === null) return;
    setPending(true);
    setError(null);
    try {
      const text = note.trim();
      const { error: problem } =
        action === "cancel"
          ? await api.POST("/schools/{school}/leave-requests/{leave}/cancel", { params: { path }, body: { reason: text } })
          : await api.POST("/schools/{school}/leave-requests/{leave}/decision", { params: { path }, body: { decision: action, note: text === "" ? null : text } });
      if (problem !== undefined) {
        setError(toFormFailure(problem).message);
        return;
      }
      setAction(null);
      setNote("");
      await onChanged(action === "approve" ? "Izin disetujui." : action === "reject" ? "Izin ditolak." : "Izin dibatalkan.");
    } catch (e) {
      setError(toFormFailure(e).message);
    } finally {
      setPending(false);
    }
  }

  return (
    <li className="space-y-3 py-3 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <span>
          <span className="font-medium text-zinc-900 dark:text-zinc-100">{item.student_name}</span>
          <span className="ml-2 text-zinc-500">{item.class_name ?? "—"}</span>
        </span>
        <span className="text-zinc-700 dark:text-zinc-300">
          {TYPE_LABELS[item.leave_type]} · {formatDate(item.start_date)}
          {item.end_date !== item.start_date ? ` – ${formatDate(item.end_date)}` : ""} · <span className="font-medium">{STATUS_LABELS[item.status]}</span>
        </span>
      </div>
      <p className="text-zinc-600 dark:text-zinc-400">{item.reason}</p>
      {item.decision_note ? <p className="text-zinc-500">Catatan keputusan: {item.decision_note}</p> : null}
      {item.has_attachment ? (
        <a className="text-brand-700 underline dark:text-brand-400" href={`/api/v1/schools/${code}/leave-requests/${item.id}/attachment`}>
          Unduh lampiran{item.attachment_name ? ` (${item.attachment_name})` : ""}
        </a>
      ) : null}

      {action === null ? (
        <div className="flex flex-wrap gap-3">
          {item.status === "pending" && canApprove ? (
            <>
              <PrimaryButton type="button" onClick={() => setAction("approve")}>
                Setujui
              </PrimaryButton>
              <SecondaryButton type="button" onClick={() => setAction("reject")}>
                Tolak
              </SecondaryButton>
            </>
          ) : null}
          {canCancel ? (
            <SecondaryButton type="button" onClick={() => setAction("cancel")}>
              Batalkan
            </SecondaryButton>
          ) : null}
        </div>
      ) : (
        <form noValidate onSubmit={submit} className="max-w-lg space-y-3">
          {error ? <FormAlert>{error}</FormAlert> : null}
          <TextAreaField
            label={action === "approve" ? "Catatan (opsional)" : "Alasan (min. 10 karakter)"}
            name={`note-${item.id}`}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            maxLength={500}
          />
          <div className="flex gap-3">
            <PrimaryButton type="submit" pending={pending} disabled={action !== "approve" && note.trim().length < 10}>
              {action === "approve" ? "Setujui izin" : action === "reject" ? "Tolak izin" : "Batalkan izin"}
            </PrimaryButton>
            <SecondaryButton
              type="button"
              onClick={() => {
                setAction(null);
                setNote("");
                setError(null);
              }}
            >
              Kembali
            </SecondaryButton>
          </div>
        </form>
      )}
    </li>
  );
}
