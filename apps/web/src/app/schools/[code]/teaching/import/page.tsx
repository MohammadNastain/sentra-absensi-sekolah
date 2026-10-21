"use client";

import { ImportWizard, uploadImportFile } from "@/components/ImportWizard";
import { api } from "@/lib/api";
import { useSchool } from "../../school-context";

const COLUMNS = ["hari", "jam_mulai", "jam_selesai", "kelas", "mapel", "guru"] as const;

export default function TeachingScheduleImportPage() {
  const { code } = useSchool();

  return (
    <ImportWizard
      title="Impor jadwal mengajar"
      noun="jam mengajar"
      type="teaching_schedule"
      columns={COLUMNS}
      permission="school.academic.manage"
      backHref={`/schools/${code}/teaching`}
      backLabel="Kembali ke jadwal mengajar"
      doneHref={`/schools/${code}/teaching`}
      doneLabel="Lihat jadwal mengajar"
      intro={
        <p>
          Unggah file CSV atau XLSX (maksimal 5 MB dan 5.000 baris). Semua kolom wajib: <code>hari</code> (Senin sampai Minggu),{" "}
          <code>jam_mulai</code> dan <code>jam_selesai</code> (JJ:MM, contoh 07:00), <code>kelas</code> (kelas aktif di tahun ajaran aktif),{" "}
          <code>mapel</code> (dibuat otomatis bila belum ada), <code>guru</code> (nomor pegawai, atau nama lengkap bila hanya ada satu guru
          aktif bernama itu). Impor guru dulu agar kolom <code>guru</code> bisa dicocokkan. Baris yang membuat guru atau kelas bentrok jam
          ditolak.
        </p>
      }
      upload={(file) =>
        uploadImportFile(
          (form) =>
            api.POST("/schools/{school}/imports/teaching-schedule", {
              params: { path: { school: code } },
              body: { file: "" },
              bodySerializer: () => form,
            }),
          file,
        )
      }
    />
  );
}
