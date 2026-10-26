"use client";

import type { Schemas } from "@sentra/api-client";
import { useState } from "react";
import { IconDownload, IconTrash } from "@/components/icons";
import { FormAlert, RowButton } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt } from "@/lib/errors";
import { formatBytes } from "@/lib/lms";

type Attachment = Schemas["LmsAttachment"];

const ACCEPT = ".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.jpg,.jpeg,.png";

/**
 * Daftar lampiran sebuah materi atau tugas, beserta unggah dan hapus bagi pengelola. Batas dan jenis berkas ditegakkan server
 * (10 MB, 5 berkas, allowlist); `accept` di sini hanya membantu memilih berkas. Unduhan lewat endpoint API yang memeriksa hak.
 */
export function AttachmentsPanel({
  code,
  kind,
  ownerId,
  attachments,
  canManage,
  onChanged,
}: {
  code: string;
  kind: "materials" | "assignments" | "submissions";
  ownerId: string;
  attachments: Attachment[];
  canManage: boolean;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function upload(file: File) {
    setBusy(true);
    setError(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      const form = new FormData();
      form.append("file", file);
      // Multipart: nilai `body.file` hanya memenuhi tipe, isi sebenarnya dari FormData.
      if (kind === "submissions") {
        return api.POST("/schools/{school}/lms/submissions/{submission}/attachments", { params: { path: { school: code, submission: ownerId } }, body: { file: "" }, bodySerializer: () => form });
      }
      return kind === "materials"
        ? api.POST("/schools/{school}/lms/materials/{material}/attachments", { params: { path: { school: code, material: ownerId } }, body: { file: "" }, bodySerializer: () => form })
        : api.POST("/schools/{school}/lms/assignments/{assignment}/attachments", { params: { path: { school: code, assignment: ownerId } }, body: { file: "" }, bodySerializer: () => form });
    });
    setBusy(false);
    if (!outcome.ok) {
      setError(outcome.failure.fields.file ?? outcome.failure.message);
      return;
    }
    onChanged();
  }

  async function remove(attachment: Attachment) {
    setBusy(true);
    setError(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      if (kind === "submissions") {
        return api.DELETE("/schools/{school}/lms/submissions/{submission}/attachments/{attachment}", { params: { path: { school: code, submission: ownerId, attachment: attachment.id } } });
      }
      return api.DELETE("/schools/{school}/lms/attachments/{attachment}", { params: { path: { school: code, attachment: attachment.id } } });
    });
    setBusy(false);
    if (!outcome.ok) {
      setError(outcome.failure.message);
      return;
    }
    onChanged();
  }

  return (
    <div className="space-y-2">
      <p className="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Lampiran</p>
      {error ? <FormAlert>{error}</FormAlert> : null}
      {attachments.length === 0 ? <p className="text-sm text-zinc-500">Belum ada lampiran.</p> : null}
      <ul className="space-y-1.5">
        {attachments.map((a) => (
          <li key={a.id} className="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-800">
            <span className="min-w-0 truncate">
              {a.name} <span className="text-zinc-500">· {formatBytes(a.size)}</span>
            </span>
            <span className="flex shrink-0 items-center gap-2">
              <a
                className="inline-flex items-center gap-1 font-semibold text-brand-700 hover:underline dark:text-brand-300"
                href={`/api/v1/schools/${code}/lms/attachments/${a.id}/download`}
                download
              >
                <IconDownload width={14} height={14} /> Unduh
              </a>
              {canManage ? (
                <RowButton tone="danger" icon={<IconTrash width={14} height={14} />} disabled={busy} onClick={() => void remove(a)}>
                  Hapus
                </RowButton>
              ) : null}
            </span>
          </li>
        ))}
      </ul>
      {canManage ? (
        <div>
          <label className="inline-block">
            <span className="sr-only">Pilih berkas lampiran</span>
            <input
              type="file"
              accept={ACCEPT}
              disabled={busy || attachments.length >= 5}
              className="block text-sm text-zinc-600 file:mr-3 file:rounded-xl file:border file:border-zinc-200 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-semibold dark:text-zinc-400 dark:file:border-zinc-700 dark:file:bg-zinc-900"
              onChange={(event) => {
                const file = event.target.files?.[0];
                event.target.value = "";
                if (file) void upload(file);
              }}
            />
          </label>
          <p className="mt-1 text-xs text-zinc-500">PDF, Office, TXT, CSV, JPG, atau PNG. Maksimal 10 MB per berkas, 5 berkas.</p>
        </div>
      ) : null}
    </div>
  );
}

/** Daftar lampiran hanya-baca (mis. pekerjaan siswa yang dibuka penilai): nama, ukuran, dan tautan unduh. */
export function AttachmentLinks({ code, attachments }: { code: string; attachments: Attachment[] }) {
  if (attachments.length === 0) return <p className="text-sm text-zinc-500">Tanpa lampiran.</p>;
  return (
    <ul className="space-y-1.5">
      {attachments.map((a) => (
        <li key={a.id} className="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-800">
          <span className="min-w-0 truncate">
            {a.name} <span className="text-zinc-500">· {formatBytes(a.size)}</span>
          </span>
          <a
            className="inline-flex shrink-0 items-center gap-1 font-semibold text-brand-700 hover:underline dark:text-brand-300"
            href={`/api/v1/schools/${code}/lms/attachments/${a.id}/download`}
            download
          >
            <IconDownload width={14} height={14} /> Unduh
          </a>
        </li>
      ))}
    </ul>
  );
}
