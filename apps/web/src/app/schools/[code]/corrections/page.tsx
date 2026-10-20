"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { FormAlert, PrimaryButton, SecondaryButton, SelectField, TextAreaField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { formatDate } from "@/lib/format";
import { schoolToday } from "../attendance/absence-panels";
import { useSchool } from "../school-context";

type Request = Schemas["CorrectionRequest"];
type Student = Schemas["AssistStudent"];
type Body = Schemas["CorrectionRequestBody"];

const STATUS_LABELS: Record<string, string> = {
  hadir: "Hadir",
  terlambat: "Terlambat",
  izin: "Izin",
  sakit: "Sakit",
  dispensasi: "Dispensasi",
  alpa: "Alpa",
  belum_tercatat: "Belum tercatat",
  alpa_belum_diverifikasi: "Alpa sementara",
  approved: "Disetujui",
  rejected: "Ditolak",
  pending: "Menunggu",
  cancelled: "Dibatalkan",
};
const label = (value: string) => STATUS_LABELS[value] ?? (value === "" ? "—" : value);
const CARD = "space-y-4 rounded-2xl border border-zinc-200/70 bg-white shadow-card p-6 dark:border-zinc-800 dark:bg-zinc-900";

/** Koreksi absensi: admin mengoreksi langsung, wali kelas mengajukan, admin memutuskan. */
export default function CorrectionsPage() {
  const { code, can } = useSchool();
  const direct = can("school.attendance.correct");
  const [items, setItems] = useState<Request[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/attendance/correction-requests", { params: { path: { school: code } } });
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
      <h1 className="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-50">Koreksi absensi</h1>
      {notice ? <FormAlert tone="success">{notice}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}
      <CorrectionForm
        direct={direct}
        onDone={async (message) => {
          setNotice(message);
          await load();
        }}
      />
      <section aria-labelledby="corr-list-title" className={CARD}>
        <h2 id="corr-list-title" className="text-lg font-bold text-zinc-900 dark:text-zinc-50">
          Pengajuan koreksi
        </h2>
        {items === null && !error ? (
          <p role="status" className="text-sm text-zinc-600 dark:text-zinc-400">
            Memuat…
          </p>
        ) : null}
        {items?.length === 0 ? <p className="text-sm text-zinc-600 dark:text-zinc-400">Belum ada pengajuan.</p> : null}
        <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
          {items?.map((item) => (
            <RequestRow
              key={item.id}
              item={item}
              canDecide={direct}
              onChanged={async (message) => {
                setNotice(message);
                await load();
              }}
            />
          ))}
        </ul>
      </section>
      {can("school.attendance.review") ? <HistoryPanel /> : null}
    </>
  );
}

type HistoryItem = Schemas["CorrectionHistoryItem"];

const TYPE_LABELS: Record<string, string> = {
  status_change: "Ubah status",
  check_in_time: "Jam masuk",
  check_out_time: "Jam pulang",
  early_leave_decision: "Pulang awal",
  review_resolution: "Hasil tinjauan",
};

function valueOf(v: HistoryItem["from"]): string {
  if (v === null) return "—";
  const first = v.attendance_status ?? v.early_leave_status ?? Object.values(v)[0];

  return typeof first === "string" ? label(first) : "—";
}

/** Riwayat semua koreksi pada rentang tanggal (izin tinjau). */
function HistoryPanel() {
  const { code, profile } = useSchool();
  const today = schoolToday(profile.timezone);
  const [from, setFrom] = useState(() => `${today.slice(0, 8)}01`);
  const [to, setTo] = useState(today);
  const [items, setItems] = useState<HistoryItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function load(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/attendance/corrections/history", { params: { path: { school: code }, query: { from, to } } });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) setItems(data.data);
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }

  return (
    <section aria-labelledby="corr-history-title" className={CARD}>
      <h2 id="corr-history-title" className="text-lg font-bold text-zinc-900 dark:text-zinc-50">
        Riwayat koreksi
      </h2>
      {error ? <FormAlert>{error}</FormAlert> : null}
      <form noValidate onSubmit={load} className="flex flex-wrap items-end gap-3">
        <TextField label="Dari" name="hist-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        <TextField label="Sampai" name="hist-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        <PrimaryButton type="submit">Tampilkan</PrimaryButton>
      </form>
      {items?.length === 0 ? <p className="text-sm text-zinc-600 dark:text-zinc-400">Tidak ada koreksi pada rentang ini.</p> : null}
      <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
        {items?.map((h) => (
          <li key={h.id} className="space-y-1 py-3 text-sm">
            <div className="flex flex-wrap justify-between gap-2">
              <span>
                <span className="font-medium text-zinc-900 dark:text-zinc-100">{h.student_name}</span>
                <span className="ml-2 text-zinc-500">{formatDate(h.attendance_date)}</span>
              </span>
              <span className="text-zinc-700 dark:text-zinc-300">
                {TYPE_LABELS[h.adjustment_type] ?? h.adjustment_type}: {valueOf(h.from)} → <span className="font-medium">{valueOf(h.to)}</span> · {label(h.status)}
              </span>
            </div>
            <p className="text-zinc-600 dark:text-zinc-400">
              {h.reason} <span className="text-zinc-500">(oleh {h.requested_by}{h.decided_by ? `, diputuskan ${h.decided_by}` : ""})</span>
            </p>
          </li>
        ))}
      </ul>
    </section>
  );
}

function CorrectionForm({ direct, onDone }: { direct: boolean; onDone: (message: string) => Promise<void> }) {
  const { code, profile } = useSchool();
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<Student[] | null>(null);
  const [student, setStudent] = useState<Student | null>(null);
  const [date, setDate] = useState(() => schoolToday(profile.timezone));
  const [type, setType] = useState<Body["type"]>("status_change");
  const [value, setValue] = useState<string>("hadir");
  const [reason, setReason] = useState("");
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
    const body = { student_id: student.id, date, type, value: value as Body["value"], reason: reason.trim() };
    try {
      const { error: problem } = direct
        ? await api.POST("/schools/{school}/attendance/corrections", { params: { path: { school: code } }, body })
        : await api.POST("/schools/{school}/attendance/correction-requests", { params: { path: { school: code } }, body });
      if (problem !== undefined) {
        const failure = toFormFailure(problem);
        setError(failure.message);
        setFields(failure.fields);
        return;
      }
      setStudent(null);
      setResults(null);
      setQuery("");
      setReason("");
      await onDone(direct ? "Koreksi diterapkan." : "Pengajuan koreksi terkirim dan menunggu keputusan.");
    } catch (e) {
      setError(toFormFailure(e).message);
    } finally {
      setPending(false);
    }
  }

  return (
    <section aria-labelledby="corr-new-title" className={CARD}>
      <div>
        <h2 id="corr-new-title" className="text-lg font-bold text-zinc-900 dark:text-zinc-50">
          {direct ? "Koreksi langsung" : "Ajukan koreksi"}
        </h2>
        <p className="text-sm text-zinc-600 dark:text-zinc-400">
          {direct ? "Berlaku seketika dan tercatat di riwayat serta audit." : "Admin akan memutuskan. Absensi baru berubah setelah disetujui."} Hanya untuk hari yang sudah berjalan.
        </p>
      </div>
      {error ? <FormAlert>{error}</FormAlert> : null}
      {student === null ? (
        <>
          <form noValidate onSubmit={search} className="flex flex-wrap items-end gap-3">
            <div className="w-72">
              <TextField label="Cari siswa" name="corr-q" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Nama atau nomor induk" autoComplete="off" />
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
          <TextField label="Tanggal" name="date" type="date" value={date} onChange={(e) => setDate(e.target.value)} error={fields.date} />
          <SelectField
            label="Jenis koreksi"
            name="type"
            value={type}
            onChange={(e) => {
              const next = e.target.value as Body["type"];
              setType(next);
              setValue(next === "status_change" ? "hadir" : next === "early_leave_decision" ? "approved" : "");
            }}
          >
            <option value="status_change">Ubah status kehadiran</option>
            <option value="early_leave_decision">Keputusan pulang awal</option>
            <option value="check_in_time">Koreksi jam masuk</option>
            <option value="check_out_time">Koreksi jam pulang</option>
          </SelectField>
          {type === "check_in_time" || type === "check_out_time" ? (
            <TextField label={type === "check_in_time" ? "Jam masuk baru" : "Jam pulang baru"} name="value" type="time" value={value} onChange={(e) => setValue(e.target.value)} error={fields.value} />
          ) : (
            <SelectField label={type === "status_change" ? "Status baru" : "Keputusan"} name="value" value={value} onChange={(e) => setValue(e.target.value)} error={fields.value}>
              {(type === "status_change" ? ["hadir", "terlambat", "izin", "sakit", "dispensasi", "alpa"] : ["approved", "rejected"]).map((v) => (
                <option key={v} value={v}>
                  {label(v)}
                </option>
              ))}
            </SelectField>
          )}
          <TextAreaField label="Alasan (min. 10 karakter)" name="reason" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} error={fields.reason} />
          <div className="flex gap-3">
            <PrimaryButton type="submit" pending={pending} disabled={reason.trim().length < 10 || value === ""}>
              {direct ? "Terapkan koreksi" : "Kirim pengajuan"}
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

function RequestRow({ item, canDecide, onChanged }: { item: Request; canDecide: boolean; onChanged: (message: string) => Promise<void> }) {
  const { code } = useSchool();
  const [action, setAction] = useState<Action | null>(null);
  const [note, setNote] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const path = { school: code, correction: item.id };

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (action === null) return;
    setPending(true);
    setError(null);
    try {
      const text = note.trim();
      const { error: problem } =
        action === "cancel"
          ? await api.POST("/schools/{school}/attendance/correction-requests/{correction}/cancel", { params: { path }, body: { reason: text } })
          : await api.POST("/schools/{school}/attendance/correction-requests/{correction}/decision", { params: { path }, body: { decision: action, note: text === "" ? null : text } });
      if (problem !== undefined) {
        setError(toFormFailure(problem).message);
        return;
      }
      setAction(null);
      setNote("");
      await onChanged(action === "approve" ? "Koreksi disetujui dan diterapkan." : action === "reject" ? "Pengajuan ditolak." : "Pengajuan dibatalkan.");
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
          {formatDate(item.attendance_date)} · {label(item.current_value)} → <span className="font-medium">{label(item.requested_value)}</span> · {label(item.status)}
        </span>
      </div>
      <p className="text-zinc-600 dark:text-zinc-400">
        {item.reason} <span className="text-zinc-500">(diajukan {item.requested_by})</span>
      </p>
      {item.decision_note ? <p className="text-zinc-500">Catatan keputusan: {item.decision_note}</p> : null}

      {item.status === "pending" && action === null ? (
        <div className="flex flex-wrap gap-3">
          {canDecide ? (
            <>
              <PrimaryButton type="button" onClick={() => setAction("approve")}>
                Setujui
              </PrimaryButton>
              <SecondaryButton type="button" onClick={() => setAction("reject")}>
                Tolak
              </SecondaryButton>
            </>
          ) : null}
          <SecondaryButton type="button" onClick={() => setAction("cancel")}>
            Batalkan
          </SecondaryButton>
        </div>
      ) : null}

      {action !== null ? (
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
              {action === "approve" ? "Setujui koreksi" : action === "reject" ? "Tolak pengajuan" : "Batalkan pengajuan"}
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
      ) : null}
    </li>
  );
}
