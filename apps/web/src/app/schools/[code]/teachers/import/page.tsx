"use client";

import { ImportWizard, uploadImportFile } from "@/components/ImportWizard";
import { api } from "@/lib/api";
import { useSchool } from "../../school-context";

const COLUMNS = ["nomor_pegawai", "nama_lengkap", "mapel", "email"] as const;

export default function TeacherImportPage() {
  const { code } = useSchool();

  return (
    <ImportWizard
      title="Impor guru"
      noun="guru"
      type="teachers"
      columns={COLUMNS}
      permission="school.academic.manage"
      backHref={`/schools/${code}/teachers`}
      backLabel="Kembali ke daftar guru"
      doneHref={`/schools/${code}/teachers`}
      doneLabel="Lihat daftar guru"
      intro={
        <p>
          Unggah file CSV atau XLSX (maksimal 5 MB dan 5.000 baris). Kolom wajib: <code>nama_lengkap</code>. Kolom opsional:{" "}
          <code>nomor_pegawai</code> (NIP/NUPTK, unik), <code>mapel</code> (mapel yang diampu, pisahkan dengan titik koma, contoh{" "}
          <code>Matematika; IPA</code>; mapel yang belum ada dibuat otomatis), <code>email</code> (kontak guru). Guru baru tidak otomatis
          mendapat akun login.
        </p>
      }
      upload={(file) =>
        uploadImportFile(
          (form) =>
            api.POST("/schools/{school}/imports/teachers", {
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
