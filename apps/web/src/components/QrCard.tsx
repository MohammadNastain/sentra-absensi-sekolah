"use client";

import QRCode from "qrcode";
import { useEffect, useState } from "react";

/** Gambar QR dibuat di browser dari nilai mentah; nilai tidak dikirim ke mana pun. */
export function QrImage({ value, size = 160, label }: { value: string; size?: number; label: string }) {
  const [src, setSrc] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    QRCode.toDataURL(value, { errorCorrectionLevel: "M", margin: 1, width: size * 2 })
      .then((url) => {
        if (!cancelled) setSrc(url);
      })
      .catch(() => {
        if (!cancelled) setSrc(null);
      });
    return () => {
      cancelled = true;
    };
  }, [value, size]);

  // eslint-disable-next-line @next/next/no-img-element -- data URL lokal, tidak perlu optimisasi gambar
  return src ? <img src={src} alt={label} width={size} height={size} /> : <div style={{ width: size, height: size }} aria-hidden />;
}

export type CardData = { studentName: string; studentNumber: string; className: string | null; token: string };

/** Lembar kartu siap cetak: dua kolom, kartu tidak terpotong antar halaman. */
export function CardSheet({ schoolName, cards }: { schoolName: string; cards: CardData[] }) {
  return (
    <div id="print-area" className="grid grid-cols-2 gap-3">
      {cards.map((card) => (
        <div key={card.token} className="break-inside-avoid rounded-lg border border-zinc-400 p-3 text-center text-zinc-900">
          <p className="text-xs font-medium uppercase tracking-wide">{schoolName}</p>
          <div className="my-2 flex justify-center">
            <QrImage value={card.token} size={140} label={`QR ${card.studentName}`} />
          </div>
          <p className="font-semibold">{card.studentName}</p>
          <p className="text-sm">
            {card.className ? `${card.className} · ` : ""}
            {card.studentNumber}
          </p>
        </div>
      ))}
    </div>
  );
}
