"use client";

import { useState } from "react";
import { CardSheet, type CardData } from "@/components/QrCard";
import { downloadCardsZip } from "@/lib/qr-download";
import { FormAlert, PrimaryButton, SecondaryButton, SelectField } from "@/components/ui";
import { api } from "@/lib/api";
import { attempt, type FormFailure } from "@/lib/errors";
import { useSchool } from "../school-context";

/** Terbit massal kartu QR satu kelas lalu cetak. Token hanya ada di memori halaman ini. */
export function ClassCardsPanel({ classId, className, onClose }: { classId: string; className: string; onClose: () => void }) {
  const { code, profile } = useSchool();
  const [mode, setMode] = useState<"missing" | "all">("missing");
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<FormFailure | null>(null);
  const [cards, setCards] = useState<CardData[] | null>(null);
  const [zipping, setZipping] = useState(false);

  async function issue() {
    setPending(true);
    setFailure(null);
    const outcome = await attempt(async () => {
      await api.ensureCsrfCookie();
      return api.POST("/schools/{school}/classes/{schoolClass}/credentials/qr", {
        params: { path: { school: code, schoolClass: classId } },
        body: { mode },
      });
    });
    setPending(false);
    if (!outcome.ok) return setFailure(outcome.failure);
    if (outcome.result.data) {
      setCards(
        outcome.result.data.data.map((row) => ({
          studentName: row.student.full_name,
          studentNumber: row.student.student_number,
          className,
          token: row.token,
        })),
      );
    }
  }

  if (cards) {
    return (
      <div className="space-y-4">
        {cards.length === 0 ? (
          <p role="status">Semua siswa di kelas ini sudah memiliki kartu aktif. Pilih «Terbitkan ulang semua» bila perlu mencetak ulang.</p>
        ) : (
          <>
            <FormAlert tone="success">
              {cards.length} kartu diterbitkan. Kode QR hanya tampil sekarang; unduh atau cetak sebelum menutup dialog ini.
            </FormAlert>
            <CardSheet schoolName={profile.name} cards={cards} />
          </>
        )}
        <div className="flex justify-end gap-2">
          {cards.length > 0 ? (
            <>
              <SecondaryButton
                type="button"
                disabled={zipping}
                onClick={async () => {
                  setZipping(true);
                  try {
                    await downloadCardsZip(cards, profile.name, `kartu-qr-${className.replace(/\s+/g, "-").toLowerCase()}.zip`);
                  } finally {
                    setZipping(false);
                  }
                }}
              >
                {zipping ? "Menyiapkan…" : `Unduh semua (${cards.length} PNG, ZIP)`}
              </SecondaryButton>
              <SecondaryButton type="button" onClick={() => window.print()}>
                Cetak
              </SecondaryButton>
            </>
          ) : null}
          <PrimaryButton type="button" onClick={onClose}>
            Selesai
          </PrimaryButton>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {failure ? <FormAlert>{failure.message}</FormAlert> : null}
      <SelectField label="Siswa yang dicetak" name="card_mode" value={mode} onChange={(event) => setMode(event.target.value as "missing" | "all")}>
        <option value="missing">Hanya yang belum punya kartu aktif</option>
        <option value="all">Terbitkan ulang semua (kartu lama dicabut)</option>
      </SelectField>
      <p className="text-sm text-zinc-600 dark:text-zinc-400">
        Kartu dibuat untuk siswa aktif yang sedang ditempatkan di kelas {className}. Server hanya menyimpan hash kode QR, jadi kartu tidak bisa dicetak ulang
        tanpa menerbitkan yang baru.
      </p>
      <div className="flex justify-end gap-2">
        <SecondaryButton type="button" onClick={onClose}>
          Batal
        </SecondaryButton>
        <PrimaryButton type="button" pending={pending} onClick={issue}>
          Terbitkan kartu
        </PrimaryButton>
      </div>
    </div>
  );
}
