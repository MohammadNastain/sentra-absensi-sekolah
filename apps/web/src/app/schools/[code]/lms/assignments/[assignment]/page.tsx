"use client";

import type { Schemas } from "@sentra/api-client";
import { use, useCallback, useEffect, useState } from "react";
import { Dialog } from "@/components/Dialog";
import { BackLink, EmptyState, FormAlert, PageHeader, PrimaryButton, RowButton, SecondaryButton, TextAreaField, TextField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, toFormFailure, type FormFailure } from "@/lib/errors";
import { formatDateTime } from "@/lib/format";
import { useSchool } from "../../../school-context";
import { AttachmentLinks } from "../../attachments-panel";
import { StudentAssignmentPage } from "../../student-views";

type Row = { student: Schemas["LmsStudentRef"]; submission: Schemas["LmsSubmission"] | null };
type SubmissionDetail = Schemas["LmsSubmission"] & { student: Schemas["LmsStudentRef"] };

function statusText(row: Row, max: number): { label: string; tone: string } {
  const s = row.submission;
  if (!s) return { label: "Belum mengumpulkan", tone: "text-zinc-500" };
  const late = s.is_late ? " · terlambat" : "";
  if (s.status === "graded") return { label: `Dinilai ${s.score ?? "-"}/${max}${late}`, tone: "text-emerald-700 dark:text-emerald-400" };
  if (s.status === "returned_for_revision") return { label: `Menunggu revisi${late}`, tone: "text-amber-700 dark:text-amber-400" };
  return { label: `Perlu dinilai${late}`, tone: "text-brand-700 dark:text-brand-300" };
}

type PageProps = { params: Promise<{ code: string; assignment: string }> };

/** Penilai melihat layar penilaian; siswa melihat rincian tugas dan formulir pengumpulannya. */
export default function AssignmentRoute(props: PageProps) {
  const { can } = useSchool();
  return can("school.lms.grade") ? <GradingPage {...props} /> : <StudentAssignmentPage {...props} />;
}

function GradingPage(props: PageProps) {
  const { assignment: assignmentId } = use(props.params);
  const { code, profile } = useSchool();
  const [assignment, setAssignment] = useState<Schemas["LmsAssignmentDetail"] | null>(null);
  const [rows, setRows] = useState<Row[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [a, r] = await Promise.all([
        api.GET("/schools/{school}/lms/assignments/{assignment}", { params: { path: { school: code, assignment: assignmentId } } }),
        api.GET("/schools/{school}/lms/assignments/{assignment}/submissions", { params: { path: { school: code, assignment: assignmentId } } }),
      ]);
      if (a.error !== undefined) setError(toFormFailure(a.error).message);
      else if (r.error !== undefined) setError(toFormFailure(r.error).message);
      else if (a.data && r.data) {
        setError(null);
        setAssignment(a.data.data);
        setRows(r.data.data);
      }
    } catch (e) {
      setError(toFormFailure(e).message);
    }
  }, [code, assignmentId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- memuat tugas dan daftar pengumpulan
    void load();
  }, [load]);

  const submitted = rows?.filter((r) => r.submission).length ?? 0;
  const graded = rows?.filter((r) => r.submission?.status === "graded").length ?? 0;

  return (
    <>
      <BackLink href={`/schools/${code}/lms/assignments`}>Kembali ke tugas</BackLink>
      <PageHeader
        title={assignment ? `Penilaian: ${assignment.title}` : "Penilaian"}
        description={
          assignment
            ? `${assignment.class.name} · ${assignment.subject.name} · ${assignment.due_at ? `tenggat ${formatDateTime(assignment.due_at, profile.timezone)}` : "tanpa tenggat"} · nilai maksimum ${assignment.max_score}`
            : undefined
        }
      />
      {error ? <FormAlert>{error}</FormAlert> : null}
      {rows === null && !error ? <p role="status" className="text-zinc-600 dark:text-zinc-400">Memuat…</p> : null}
      {rows && rows.length === 0 ? <EmptyState title="Belum ada siswa di kelas ini" /> : null}

      {assignment && rows && rows.length > 0 ? (
        <>
          <p className="text-sm text-zinc-600 dark:text-zinc-400">
            {submitted} dari {rows.length} siswa sudah mengumpulkan · {graded} dinilai
          </p>
          <div className="overflow-x-auto rounded-2xl border border-zinc-200/70 bg-white shadow-card dark:border-zinc-800 dark:bg-zinc-900">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-zinc-200 bg-zinc-50/70 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
                <tr>
                  <th scope="col" className="px-4 py-3 font-semibold">Siswa</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Nomor induk</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Status</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Dikumpulkan</th>
                  <th scope="col" className="px-4 py-3 font-semibold">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const st = statusText(row, assignment.max_score);
                  return (
                    <tr key={row.student.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                      <td className="px-4 py-3 font-semibold text-zinc-900 dark:text-zinc-100">{row.student.full_name}</td>
                      <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{row.student.student_number}</td>
                      <td className={`px-4 py-3 font-medium ${st.tone}`}>{st.label}</td>
                      <td className="px-4 py-3 text-zinc-700 dark:text-zinc-300">{row.submission ? formatDateTime(row.submission.submitted_at, profile.timezone) : "-"}</td>
                      <td className="px-4 py-3">
                        {row.submission ? <RowButton onClick={() => setOpen(row.submission?.id ?? null)}>Buka</RowButton> : null}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </>
      ) : null}

      <Dialog open={open !== null} title="Pekerjaan siswa" onClose={() => setOpen(null)}>
        {open && assignment ? (
          <GradeForm
            key={open}
            code={code}
            submissionId={open}
            maxScore={assignment.max_score}
            timeZone={profile.timezone}
            onClose={() => setOpen(null)}
            onDone={() => {
              setOpen(null);
              void load();
            }}
          />
        ) : null}
      </Dialog>
    </>
  );
}

function GradeForm({
  code,
  submissionId,
  maxScore,
  timeZone,
  onClose,
  onDone,
}: {
  code: string;
  submissionId: string;
  maxScore: number;
  timeZone: string;
  onClose: () => void;
  onDone: () => void;
}) {
  const [detail, setDetail] = useState<SubmissionDetail | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [pending, setPending] = useState<"grade" | "return" | null>(null);
  const [failure, setFailure] = useState<FormFailure | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/schools/{school}/lms/submissions/{submission}", { params: { path: { school: code, submission: submissionId } } })
      .then(({ data, error }) => {
        if (cancelled) return;
        if (error !== undefined) setLoadError(toFormFailure(error).message);
        else if (data) setDetail(data.data as SubmissionDetail);
      })
      .catch((e) => {
        if (!cancelled) setLoadError(toFormFailure(e).message);
      });
    return () => {
      cancelled = true;
    };
  }, [code, submissionId]);

  if (!detail) return loadError ? <FormAlert>{loadError}</FormAlert> : <p role="status" className="text-zinc-600 dark:text-zinc-400">Memuat…</p>;

  async function act(kind: "grade" | "return", form: HTMLFormElement) {
    const data = new FormData(form);
    const feedback = String(data.get("feedback") ?? "");
    setPending(kind);
    setFailure(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return kind === "grade"
        ? api.POST("/schools/{school}/lms/submissions/{submission}/grade", {
            params: { path: { school: code, submission: submissionId } },
            body: { score: Number(data.get("score")), feedback },
          })
        : api.POST("/schools/{school}/lms/submissions/{submission}/return", { params: { path: { school: code, submission: submissionId } }, body: { feedback } });
    });
    setPending(null);
    if (!outcome.ok) {
      setFailure(outcome.failure);
      return;
    }
    onDone();
  }

  const waiting = detail.status === "returned_for_revision";

  return (
    <form
      className="space-y-4"
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        void act("grade", event.currentTarget);
      }}
    >
      <div>
        <p className="font-semibold text-zinc-900 dark:text-zinc-100">{detail.student.full_name}</p>
        <p className="text-sm text-zinc-600 dark:text-zinc-400">
          Dikumpulkan {formatDateTime(detail.submitted_at, timeZone)}
          {detail.is_late ? " · terlambat" : ""}
        </p>
      </div>
      <div>
        <p className="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Jawaban</p>
        <p className="mt-1 max-h-48 overflow-auto whitespace-pre-wrap rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-950/40">
          {detail.body || <span className="text-zinc-500">Tidak ada teks jawaban.</span>}
        </p>
      </div>
      <div>
        <p className="mb-1 text-sm font-semibold text-zinc-800 dark:text-zinc-200">Lampiran siswa</p>
        <AttachmentLinks code={code} attachments={detail.attachments ?? []} />
      </div>

      {waiting ? (
        <FormAlert tone="success">Menunggu revisi siswa. Komentar Anda: “{detail.feedback}”. Nilai dapat diberikan setelah siswa mengirim ulang.</FormAlert>
      ) : (
        <>
          {failure ? <FormAlert>{failure.message}</FormAlert> : null}
          <TextField
            label={`Nilai (0–${maxScore})`}
            name="score"
            type="number"
            min={0}
            max={maxScore}
            step="any"
            defaultValue={detail.score ?? ""}
            error={failure?.fields.score}
          />
          <TextAreaField
            label="Komentar"
            name="feedback"
            rows={4}
            defaultValue={detail.feedback ?? ""}
            error={failure?.fields.feedback}
            hint="Wajib bila meminta revisi; opsional saat memberi nilai."
          />
          <div className="flex flex-wrap justify-end gap-3">
            <SecondaryButton type="button" onClick={onClose}>Tutup</SecondaryButton>
            <SecondaryButton
              type="button"
              disabled={pending !== null}
              onClick={(event) => {
                const form = event.currentTarget.form;
                if (form) void act("return", form);
              }}
            >
              {pending === "return" ? "Memproses…" : "Minta revisi"}
            </SecondaryButton>
            <PrimaryButton type="submit" pending={pending === "grade"}>Simpan nilai</PrimaryButton>
          </div>
        </>
      )}
      {waiting ? (
        <div className="flex justify-end">
          <SecondaryButton type="button" onClick={onClose}>Tutup</SecondaryButton>
        </div>
      ) : null}
    </form>
  );
}
