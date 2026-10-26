"use client";

import type { Schemas } from "@sentra/api-client";
import { use, useCallback, useEffect, useState } from "react";
import { Pager } from "@/components/Pager";
import { BackLink, EmptyState, FormAlert, PageHeader, PrimaryButton, RowLink, TextAreaField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { formatDateTime } from "@/lib/format";
import { useSchool } from "../school-context";
import { AttachmentLinks, AttachmentsPanel } from "./attachments-panel";

type Status = Schemas["LmsSubmissionStatus"];

const SUBMISSION_LABEL: Record<Status, string> = { submitted: "Terkumpul", returned_for_revision: "Perlu revisi", graded: "Dinilai" };

/** Memuat satu halaman daftar dengan pola yang sama untuk materi dan tugas. */
function usePagedList<T>(fetchPage: (page: number) => Promise<{ data?: { data: T[]; meta: Schemas["PageMeta"] }; error?: unknown }>) {
  const [items, setItems] = useState<T[] | null>(null);
  const [meta, setMeta] = useState<Schemas["PageMeta"] | null>(null);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetchPage(page)
      .then(({ data, error: problem }) => {
        if (cancelled) return;
        if (problem !== undefined) setError(toFormFailure(problem).message);
        else if (data) {
          setError(null);
          setItems(data.data);
          setMeta(data.meta);
        }
      })
      .catch((e) => {
        if (!cancelled) setError(toFormFailure(e).message);
      });
    return () => {
      cancelled = true;
    };
  }, [fetchPage, page]);

  return { items, meta, setPage, error };
}

/** Daftar materi untuk siswa (dan peran lihat-saja): hanya materi terbit di kelasnya. */
export function StudentMaterials() {
  const { code, profile } = useSchool();
  const [open, setOpen] = useState<string | null>(null);
  const fetchPage = useCallback((page: number) => api.GET("/schools/{school}/lms/materials", { params: { path: { school: code }, query: { page } } }), [code]);
  const { items, meta, setPage, error } = usePagedList(fetchPage);

  return (
    <>
      <PageHeader title="Materi" description="Materi pembelajaran dari guru untuk kelas Anda." />
      {error ? <FormAlert>{error}</FormAlert> : null}
      {items === null && !error ? <p role="status" className="text-zinc-600 dark:text-zinc-400">Memuat…</p> : null}
      {items && items.length === 0 ? <EmptyState title="Belum ada materi" description="Materi yang diterbitkan guru akan muncul di sini." /> : null}
      <ul className="space-y-3">
        {items?.map((m) => (
          <li key={m.id} className="rounded-2xl border border-zinc-200/70 bg-white p-4 shadow-card dark:border-zinc-800 dark:bg-zinc-900">
            <button type="button" className="w-full text-left" aria-expanded={open === m.id} onClick={() => setOpen(open === m.id ? null : m.id)}>
              <p className="font-semibold text-zinc-900 dark:text-zinc-100">{m.title}</p>
              <p className="text-sm text-zinc-600 dark:text-zinc-400">
                {m.subject.name} · {m.class.name} · {m.published_at ? formatDateTime(m.published_at, profile.timezone) : ""}
                {m.attachment_count > 0 ? ` · ${m.attachment_count} lampiran` : ""}
              </p>
            </button>
            {open === m.id ? <MaterialBody code={code} id={m.id} /> : null}
          </li>
        ))}
      </ul>
      {meta ? <Pager meta={meta} onPage={setPage} /> : null}
    </>
  );
}

function MaterialBody({ code, id }: { code: string; id: string }) {
  const [detail, setDetail] = useState<Schemas["LmsMaterialDetail"] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/schools/{school}/lms/materials/{material}", { params: { path: { school: code, material: id } } })
      .then(({ data, error: problem }) => {
        if (cancelled) return;
        if (problem !== undefined) setError(toFormFailure(problem).message);
        else if (data) setDetail(data.data);
      })
      .catch((e) => {
        if (!cancelled) setError(toFormFailure(e).message);
      });
    return () => {
      cancelled = true;
    };
  }, [code, id]);

  if (error) return <FormAlert>{error}</FormAlert>;
  if (!detail) return <p role="status" className="mt-3 text-sm text-zinc-600 dark:text-zinc-400">Memuat…</p>;
  return (
    <div className="mt-3 space-y-3 border-t border-zinc-100 pt-3 dark:border-zinc-800">
      <p className="whitespace-pre-wrap text-sm text-zinc-800 dark:text-zinc-200">{detail.body || "Tidak ada isi teks."}</p>
      <AttachmentLinks code={code} attachments={detail.attachments} />
    </div>
  );
}

/** Daftar tugas siswa dengan status pengumpulannya sendiri. */
export function StudentAssignments() {
  const { code, profile } = useSchool();
  const fetchPage = useCallback((page: number) => api.GET("/schools/{school}/lms/assignments", { params: { path: { school: code }, query: { page } } }), [code]);
  const { items, meta, setPage, error } = usePagedList(fetchPage);

  return (
    <>
      <PageHeader title="Tugas" description="Tugas dari guru untuk kelas Anda. Buka tugas untuk membaca instruksi dan mengumpulkan jawaban." />
      {error ? <FormAlert>{error}</FormAlert> : null}
      {items === null && !error ? <p role="status" className="text-zinc-600 dark:text-zinc-400">Memuat…</p> : null}
      {items && items.length === 0 ? <EmptyState title="Belum ada tugas" description="Tugas yang diterbitkan guru akan muncul di sini." /> : null}
      <ul className="space-y-3">
        {items?.map((a) => (
          <li key={a.id} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-zinc-200/70 bg-white p-4 shadow-card dark:border-zinc-800 dark:bg-zinc-900">
            <div>
              <p className="font-semibold text-zinc-900 dark:text-zinc-100">{a.title}</p>
              <p className="text-sm text-zinc-600 dark:text-zinc-400">
                {a.subject.name} · {a.due_at ? `tenggat ${formatDateTime(a.due_at, profile.timezone)}` : "tanpa tenggat"}
                {a.status === "closed" ? " · ditutup" : ""}
              </p>
              <p className="mt-1 text-sm font-medium text-brand-700 dark:text-brand-300">
                {a.my_submission ? `${SUBMISSION_LABEL[a.my_submission.status]}${a.my_submission.is_late ? " · terlambat" : ""}` : "Belum dikumpulkan"}
              </p>
            </div>
            <RowLink href={`/schools/${code}/lms/assignments/${a.id}`}>Buka</RowLink>
          </li>
        ))}
      </ul>
      {meta ? <Pager meta={meta} onPage={setPage} /> : null}
    </>
  );
}

/** Rincian tugas bagi siswa: instruksi, lampiran soal, dan formulir pengumpulan sendiri. */
export function StudentAssignmentPage(props: { params: Promise<{ code: string; assignment: string }> }) {
  const { assignment: id } = use(props.params);
  const { code, profile, can } = useSchool();
  const [assignment, setAssignment] = useState<Schemas["LmsAssignmentDetail"] | null>(null);
  const [submission, setSubmission] = useState<Schemas["LmsSubmission"] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const a = await api.GET("/schools/{school}/lms/assignments/{assignment}", { params: { path: { school: code, assignment: id } } });
      if (a.error !== undefined || !a.data) {
        setError(toFormFailure(a.error).message);
        return;
      }
      setAssignment(a.data.data);
      if (can("school.lms.submit")) {
        // 404 berarti belum mengumpulkan; itu keadaan normal, bukan galat.
        const s = await api.GET("/schools/{school}/lms/assignments/{assignment}/submission", { params: { path: { school: code, assignment: id } } });
        setSubmission(s.data ? s.data.data : null);
      }
      setError(null);
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, id, can]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat tugas dan pengumpulan sendiri
    void load();
  }, [load]);

  return (
    <>
      <BackLink href={`/schools/${code}/lms/assignments`}>Kembali ke tugas</BackLink>
      {error ? <FormAlert>{error}</FormAlert> : null}
      {!assignment && !error ? <p role="status" className="text-zinc-600 dark:text-zinc-400">Memuat…</p> : null}
      {assignment ? (
        <>
          <PageHeader
            title={assignment.title}
            description={`${assignment.subject.name} · ${assignment.due_at ? `tenggat ${formatDateTime(assignment.due_at, profile.timezone)}` : "tanpa tenggat"} · nilai maksimum ${assignment.max_score}${assignment.status === "closed" ? " · ditutup" : ""}`}
          />
          <div className="space-y-3 rounded-2xl border border-zinc-200/70 bg-white p-4 shadow-card dark:border-zinc-800 dark:bg-zinc-900">
            <p className="whitespace-pre-wrap text-sm text-zinc-800 dark:text-zinc-200">{assignment.instructions || "Tidak ada instruksi tertulis."}</p>
            <AttachmentLinks code={code} attachments={assignment.attachments} />
          </div>
          {can("school.lms.submit") ? <SubmissionCard code={code} assignment={assignment} submission={submission} timeZone={profile.timezone} onChanged={() => void load()} /> : null}
        </>
      ) : null}
    </>
  );
}

function SubmissionCard({
  code,
  assignment,
  submission,
  timeZone,
  onChanged,
}: {
  code: string;
  assignment: Schemas["LmsAssignmentDetail"];
  submission: Schemas["LmsSubmission"] | null;
  timeZone: string;
  onChanged: () => void;
}) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const [saved, setSaved] = useState(false);

  const locked = submission?.status === "graded";
  const closed = assignment.status === "closed";
  const overdue = assignment.due_at !== null && new Date(assignment.due_at) < new Date() && !assignment.accept_late && submission?.status !== "returned_for_revision";
  const readOnly = locked || closed || overdue;

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const body = String(new FormData(event.currentTarget).get("body") ?? "");
    setPending(true);
    setFailure(null);
    setSaved(false);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.PUT("/schools/{school}/lms/assignments/{assignment}/submission", { params: { path: { school: code, assignment: assignment.id } }, body: { body } });
    });
    setPending(false);
    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    setSaved(true);
    onChanged();
  }

  return (
    <div className="space-y-4 rounded-2xl border border-zinc-200/70 bg-white p-4 shadow-card dark:border-zinc-800 dark:bg-zinc-900">
      <h2 className="font-semibold text-zinc-900 dark:text-zinc-100">Pengumpulan Anda</h2>
      {submission ? (
        <p className="text-sm text-zinc-600 dark:text-zinc-400">
          {SUBMISSION_LABEL[submission.status]} · dikirim {formatDateTime(submission.submitted_at, timeZone)}
          {submission.is_late ? " · terlambat" : ""}
        </p>
      ) : (
        <p className="text-sm text-zinc-600 dark:text-zinc-400">Belum dikumpulkan.</p>
      )}
      {submission?.status === "graded" ? (
        <FormAlert tone="success">
          Nilai Anda: {submission.score}/{assignment.max_score}
          {submission.feedback ? ` — ${submission.feedback}` : ""}
        </FormAlert>
      ) : null}
      {submission?.status === "returned_for_revision" ? (
        <FormAlert>Guru meminta revisi: {submission.feedback}. Perbaiki jawaban lalu kirim ulang.</FormAlert>
      ) : null}
      {closed ? <p className="text-sm text-zinc-600 dark:text-zinc-400">Tugas ini sudah ditutup.</p> : null}
      {overdue && !closed && !locked ? <p className="text-sm text-zinc-600 dark:text-zinc-400">Tenggat sudah lewat dan tugas ini tidak menerima jawaban terlambat.</p> : null}

      <form onSubmit={submit} className="space-y-4" noValidate>
        {failure ? <FormAlert>{failure.message}</FormAlert> : null}
        <TextAreaField label="Jawaban" name="body" rows={6} defaultValue={submission?.body ?? ""} error={failure?.fields.body} readOnly={readOnly} />
        {!readOnly ? (
          <div className="flex items-center justify-end gap-3">
            {saved ? <span role="status" className="text-sm text-zinc-600 dark:text-zinc-400">Tersimpan.</span> : null}
            <PrimaryButton type="submit" pending={pending}>{submission ? "Kirim ulang" : "Kumpulkan"}</PrimaryButton>
          </div>
        ) : null}
      </form>

      {submission ? (
        <AttachmentsPanel code={code} kind="submissions" ownerId={submission.id} attachments={submission.attachments ?? []} canManage={!readOnly} onChanged={onChanged} />
      ) : (
        <p className="text-sm text-zinc-500">Lampiran dapat ditambahkan setelah jawaban dikumpulkan.</p>
      )}
    </div>
  );
}
