"use client";

import type { Schemas } from "@sentra/api-client";
import { useCallback, useEffect, useState } from "react";
import { FormAlert, PrimaryButton, SecondaryButton, SelectField, TextAreaField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { formatDate } from "@/lib/format";
import { useSchool } from "../school-context";
import { AbsenceSettingsPanel, AbsenceVerificationPanel, schoolToday } from "./absence-panels";

type AssistStudent = Schemas["AssistStudent"];
type ReviewItem = Schemas["ReviewQueueItem"];
type AssistReport = Schemas["AssistReportEnvelope"]["data"];
type Reason = Schemas["AssistReason"];

const REASON_LABELS: Record<string, string> = {
  card_forgotten: "Kartu tertinggal",
  card_damaged: "Kartu rusak",
  early_leave: "Pulang awal",
  other: "Lainnya",
};

/** Operasi harian: pencatatan mode bantuan, antrean tinjauan, dan laporan anomali bantuan. */
export default function AttendanceOperationsPage() {
  const { can } = useSchool();
  const canAssist = can("school.attendance.assist");
  const canReview = can("school.attendance.review");
  const canVerify = can("school.attendance.verify");
  const canConfigure = can("school.profile.update");

  return (
    <>
      <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Kehadiran</h1>
      {canAssist ? <AssistPanel /> : null}
      {canReview ? <ReviewQueuePanel /> : null}
      {canVerify ? <AbsenceVerificationPanel canHold={canReview} /> : null}
      {canReview ? <AssistReportPanel /> : null}
      {canConfigure ? <AbsenceSettingsPanel /> : null}
    </>
  );
}

const CARD = "space-y-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900";

function AssistPanel() {
  const { code } = useSchool();
  const [query, setQuery] = useState("");
  const [students, setStudents] = useState<AssistStudent[] | null>(null);
  const [selected, setSelected] = useState<AssistStudent | null>(null);
  const [reason, setReason] = useState<Reason>("card_forgotten");
  const [note, setNote] = useState("");
  const [verified, setVerified] = useState(false);
  // Satu kunci per percobaan pencatatan: pengiriman ulang (klik ganda, jaringan) tidak membuat catatan ganda.
  const [key, setKey] = useState(() => crypto.randomUUID());
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [done, setDone] = useState<string | null>(null);

  async function search(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setDone(null);
    if (query.trim().length < 2) {
      setError("Ketik minimal 2 karakter nama atau nomor induk.");
      return;
    }
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/attendance/assist/students", {
        params: { path: { school: code }, query: { q: query.trim() } },
      });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) setStudents(data.data);
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!selected) return;
    setPending(true);
    setError(null);
    setFieldErrors({});
    try {
      const { data, error: problem } = await api.POST("/schools/{school}/attendance/assisted", {
        params: { path: { school: code } },
        body: {
          student_id: selected.id,
          reason_code: reason,
          note: note.trim() === "" ? null : note.trim(),
          verified: true,
          idempotency_key: key,
        },
      });
      if (problem !== undefined) {
        const failure = toFormFailure(problem);
        setError(failure.message);
        setFieldErrors(failure.fields);
      } else if (data) {
        setDone(`${selected.full_name}: ${data.data.message} (${data.data.recorded_at_local})`);
        setSelected(null);
        setStudents(null);
        setQuery("");
        setNote("");
        setVerified(false);
        setKey(crypto.randomUUID());
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    } finally {
      setPending(false);
    }
  }

  return (
    <section aria-labelledby="assist-title" className={CARD}>
      <div>
        <h2 id="assist-title" className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
          Pencatatan mode bantuan
        </h2>
        <p className="text-sm text-zinc-600 dark:text-zinc-400">
          Untuk siswa yang kartunya tertinggal atau rusak, atau pulang lebih awal. Setiap pencatatan wajib verifikasi visual dan masuk audit.
        </p>
      </div>

      {done ? <FormAlert tone="success">{done}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}

      {selected === null ? (
        <>
          <form noValidate onSubmit={search} className="flex flex-wrap items-end gap-3">
            <div className="w-72">
              <TextField label="Cari siswa" name="assist-q" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Nama atau nomor induk" autoComplete="off" />
            </div>
            <PrimaryButton type="submit">Cari</PrimaryButton>
          </form>
          {students !== null && students.length === 0 ? <p className="text-sm text-zinc-600 dark:text-zinc-400">Tidak ada siswa aktif yang cocok.</p> : null}
          {students !== null && students.length > 0 ? (
            <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {students.map((s) => (
                <li key={s.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                  <span>
                    <span className="font-medium text-zinc-900 dark:text-zinc-100">{s.full_name}</span>
                    <span className="ml-2 text-zinc-500">
                      {s.student_number} · {s.class_name}
                    </span>
                  </span>
                  <SecondaryButton type="button" onClick={() => setSelected(s)}>
                    Pilih
                  </SecondaryButton>
                </li>
              ))}
            </ul>
          ) : null}
        </>
      ) : (
        <form noValidate onSubmit={submit} className="max-w-lg space-y-4">
          <p className="text-sm">
            <span className="font-medium text-zinc-900 dark:text-zinc-100">{selected.full_name}</span>
            <span className="ml-2 text-zinc-500">
              {selected.student_number} · {selected.class_name}
            </span>
          </p>
          <SelectField label="Alasan" name="reason_code" value={reason} onChange={(e) => setReason(e.target.value as Reason)} error={fieldErrors.reason_code}>
            {Object.entries(REASON_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </SelectField>
          <TextAreaField
            label={reason === "other" ? "Catatan (wajib)" : "Catatan (opsional)"}
            name="note"
            value={note}
            onChange={(e) => setNote(e.target.value)}
            maxLength={500}
            error={fieldErrors.note}
          />
          <label className="flex items-start gap-2 text-sm text-zinc-800 dark:text-zinc-200">
            <input type="checkbox" checked={verified} onChange={(e) => setVerified(e.target.checked)} className="mt-1" />
            <span>Saya telah memverifikasi identitas siswa ini secara langsung (visual).</span>
          </label>
          <div className="flex gap-3">
            <PrimaryButton type="submit" pending={pending} disabled={!verified || (reason === "other" && note.trim() === "")}>
              Catat kehadiran
            </PrimaryButton>
            <SecondaryButton type="button" onClick={() => setSelected(null)}>
              Batal
            </SecondaryButton>
          </div>
        </form>
      )}
    </section>
  );
}

function ReviewQueuePanel() {
  const { code } = useSchool();
  const [items, setItems] = useState<ReviewItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [active, setActive] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const [pending, setPending] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/attendance/review-queue", { params: { path: { school: code } } });
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
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat antrean awal
    void load();
  }, [load]);

  async function resolve(eventId: string, decision: "accept" | "reject") {
    setPending(true);
    setFormError(null);
    try {
      const { error: problem } = await api.POST("/schools/{school}/attendance/review-queue/{event}/resolve", {
        params: { path: { school: code, event: eventId } },
        body: { decision, reason: reason.trim() },
      });
      if (problem !== undefined) {
        setFormError(toFormFailure(problem).message);
        return;
      }
      setActive(null);
      setReason("");
      await load();
    } catch (e) {
      setFormError(toFormFailure(e).message);
    } finally {
      setPending(false);
    }
  }

  return (
    <section aria-labelledby="review-title" className={CARD}>
      <div>
        <h2 id="review-title" className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
          Antrean tinjauan
        </h2>
        <p className="text-sm text-zinc-600 dark:text-zinc-400">Scan yang perlu keputusan (pulang tanpa catatan masuk, atau masuk pada siswa yang sudah dinyatakan alpa). Terima untuk mencatat pulang, atau tolak untuk mengabaikan; keduanya wajib beralasan.</p>
      </div>
      {error ? <FormAlert>{error}</FormAlert> : null}
      {items === null && !error ? <p role="status" className="text-sm text-zinc-600 dark:text-zinc-400">Memuat antrean…</p> : null}
      {items?.length === 0 ? <p className="text-sm text-zinc-600 dark:text-zinc-400">Tidak ada item yang perlu ditinjau.</p> : null}
      {items && items.length > 0 ? (
        <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
          {items.map((item) => (
            <li key={item.event_id} className="space-y-3 py-3 text-sm">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <span>
                  <span className="font-medium text-zinc-900 dark:text-zinc-100">{item.student_name}</span>
                  <span className="ml-2 text-zinc-500">{item.class_name ?? "—"}</span>
                </span>
                <span className="text-zinc-700 dark:text-zinc-300">
                  {formatDate(item.attendance_date)} · <span className="font-mono">{item.recorded_at_local}</span> · {item.event_type === "check_out" ? "Pulang" : "Masuk"}
                  {item.reason_code ? ` · ${REASON_LABELS[item.reason_code] ?? item.reason_code}` : ""}
                </span>
                {active !== item.event_id ? (
                  <SecondaryButton
                    type="button"
                    onClick={() => {
                      setActive(item.event_id);
                      setReason("");
                      setFormError(null);
                    }}
                  >
                    Tinjau
                  </SecondaryButton>
                ) : null}
              </div>
              {active === item.event_id ? (
                <div className="max-w-lg space-y-3">
                  {formError ? <FormAlert>{formError}</FormAlert> : null}
                  <TextAreaField label="Alasan (min. 10 karakter)" name={`reason-${item.event_id}`} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
                  <div className="flex gap-3">
                    <PrimaryButton type="button" pending={pending} disabled={reason.trim().length < 10} onClick={() => void resolve(item.event_id, "accept")}>
                      Terima (catat scan)
                    </PrimaryButton>
                    <SecondaryButton type="button" disabled={pending || reason.trim().length < 10} onClick={() => void resolve(item.event_id, "reject")}>
                      Tolak
                    </SecondaryButton>
                    <SecondaryButton type="button" onClick={() => setActive(null)}>
                      Batal
                    </SecondaryButton>
                  </div>
                </div>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}

function AssistReportPanel() {
  const { code, profile } = useSchool();
  const [range, setRange] = useState(() => ({ from: schoolToday(profile.timezone, -29), to: schoolToday(profile.timezone) }));
  const [report, setReport] = useState<AssistReport | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, error: problem } = await api.GET("/schools/{school}/attendance/assist-report", {
        params: { path: { school: code }, query: range },
      });
      if (problem !== undefined) setError(toFormFailure(problem).message);
      else if (data) {
        setError(null);
        setReport(data.data);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, range]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat laporan untuk rentang terpilih
    void load();
  }, [load]);

  return (
    <section aria-labelledby="report-title" className={CARD}>
      <div>
        <h2 id="report-title" className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
          Laporan anomali mode bantuan
        </h2>
        <p className="text-sm text-zinc-600 dark:text-zinc-400">Petugas atau siswa yang berulang dapat menandakan penyalahgunaan mode bantuan.</p>
      </div>
      <form
        noValidate
        className="flex flex-wrap items-end gap-3"
        onSubmit={(event) => {
          event.preventDefault();
          const form = new FormData(event.currentTarget);
          setRange({ from: String(form.get("from")), to: String(form.get("to")) });
        }}
      >
        <TextField label="Dari" name="from" type="date" defaultValue={range.from} />
        <TextField label="Sampai" name="to" type="date" defaultValue={range.to} />
        <PrimaryButton type="submit">Terapkan</PrimaryButton>
      </form>
      {error ? <FormAlert>{error}</FormAlert> : null}
      {report ? (
        <div className="space-y-4 text-sm">
          <p className="text-zinc-700 dark:text-zinc-300">
            Total pencatatan bantuan: <span className="font-semibold tabular-nums">{report.total}</span>
          </p>
          <div className="grid gap-4 md:grid-cols-3">
            <ReportList title="Per petugas" rows={report.by_officer.map((r) => [r.officer_name, `${r.total} (pulang: ${r.check_outs})`])} />
            <ReportList title="Per siswa" rows={report.by_student.map((r) => [r.student_name, String(r.total)])} />
            <ReportList title="Per alasan" rows={report.by_reason.map((r) => [REASON_LABELS[r.reason_code] ?? r.reason_code, String(r.total)])} />
          </div>
        </div>
      ) : null}
    </section>
  );
}

function ReportList({ title, rows }: { title: string; rows: [string, string][] }) {
  return (
    <div>
      <h3 className="mb-2 font-medium text-zinc-700 dark:text-zinc-300">{title}</h3>
      {rows.length === 0 ? (
        <p className="text-zinc-500">Tidak ada data.</p>
      ) : (
        <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
          {rows.map(([label, value], index) => (
            <li key={index} className="flex justify-between gap-2 py-1.5">
              <span className="text-zinc-900 dark:text-zinc-100">{label}</span>
              <span className="tabular-nums text-zinc-700 dark:text-zinc-300">{value}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
