"use client";

import { ImportWizard, uploadImportFile } from "@/components/ImportWizard";
import { api } from "@/lib/api";
import { useSchool } from "../../school-context";

const COLUMNS = ["nomor_induk", "nisn", "nama_lengkap", "tanggal_lahir", "kelas"] as const;

export default function StudentImportPage() {
  const { code } = useSchool();

  return (
    <ImportWizard
      title="Impor siswa"
      noun="siswa"
      type="students"
      columns={COLUMNS}
      permission="school.students.manage"
      backHref={`/schools/${code}/students`}
      backLabel="Kembali ke daftar siswa"
      doneHref={`/schools/${code}/students`}
      doneLabel="Lihat daftar siswa"
      intro={
        <p>
          Unggah file CSV atau XLSX (maksimal 5 MB dan 5.000 baris). Kolom wajib: <code>nomor_induk</code>, <code>nama_lengkap</code>.
          Kolom opsional: <code>nisn</code> (10 digit; format sel sebagai teks agar angka nol di depan tidak hilang),{" "}
          <code>tanggal_lahir</code> (TTTT-BB-HH), <code>kelas</code> (nama kelas aktif di tahun ajaran aktif).
        </p>
      }
      upload={(file) =>
        uploadImportFile(
          (form) =>
            api.POST("/schools/{school}/imports/students", {
              params: { path: { school: code } },
              // Multipart: file dikirim lewat FormData; nilai `body.file` hanya memenuhi tipe.
              body: { file: "" },
              bodySerializer: () => form,
            }),
          file,
        )
      }
    />
  );
}
