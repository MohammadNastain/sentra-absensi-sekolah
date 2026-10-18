"use client";

import jsQR from "jsqr";
import { useEffect, useRef, useState } from "react";

const SCAN_INTERVAL_MS = 120;
/** Nilai yang sama tidak dikirim ulang dalam jeda ini; kartu yang masih di depan kamera tidak menghasilkan spam. */
const SAME_VALUE_COOLDOWN_MS = 5000;

/**
 * Pemindai QR lewat kamera (uji dengan kamera laptop; kamera tablet di produksi). Memakai jsQR di canvas
 * karena BarcodeDetector tidak tersedia andal di Chrome desktop. Video tidak pernah disimpan atau dikirim.
 */
export function CameraScanner({ onValue, paused }: { onValue: (value: string) => void; paused: boolean }) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const last = useRef<{ value: string; at: number } | null>(null);
  const pausedRef = useRef(paused);
  const onValueRef = useRef(onValue);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    pausedRef.current = paused;
    onValueRef.current = onValue;
  });

  useEffect(() => {
    let stream: MediaStream | null = null;
    let timer: ReturnType<typeof setInterval> | null = null;
    let stopped = false;

    async function start() {
      if (!navigator.mediaDevices?.getUserMedia) {
        setError("Browser ini tidak mendukung kamera (butuh HTTPS atau localhost).");
        return;
      }
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment", width: { ideal: 1280 } }, audio: false });
      } catch {
        setError("Kamera tidak dapat diakses. Izinkan akses kamera di browser.");
        return;
      }
      if (stopped) {
        stream.getTracks().forEach((t) => t.stop());
        return;
      }
      const video = videoRef.current;
      if (!video) return;
      video.srcObject = stream;
      await video.play().catch(() => undefined);

      timer = setInterval(() => {
        const canvas = canvasRef.current;
        if (pausedRef.current || !canvas || video.readyState < 2 || video.videoWidth === 0) return;
        // Perkecil agar decode ringan di perangkat murah.
        const scale = Math.min(1, 640 / video.videoWidth);
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        const context = canvas.getContext("2d", { willReadFrequently: true });
        if (!context) return;
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        const image = context.getImageData(0, 0, canvas.width, canvas.height);
        const code = jsQR(image.data, image.width, image.height, { inversionAttempts: "dontInvert" });
        if (!code?.data) return;

        const now = Date.now();
        if (last.current && last.current.value === code.data && now - last.current.at < SAME_VALUE_COOLDOWN_MS) return;
        last.current = { value: code.data, at: now };
        onValueRef.current(code.data);
      }, SCAN_INTERVAL_MS);
    }

    void start();
    return () => {
      stopped = true;
      if (timer) clearInterval(timer);
      stream?.getTracks().forEach((t) => t.stop());
    };
  }, []);

  return (
    <div className="relative overflow-hidden rounded-xl bg-black">
      <video ref={videoRef} playsInline muted className="aspect-video w-full object-cover" aria-label="Pratinjau kamera" />
      <canvas ref={canvasRef} className="hidden" />
      <div className="pointer-events-none absolute inset-0 m-auto h-3/5 w-3/5 rounded-2xl border-4 border-white/70" aria-hidden />
      {error ? <p role="alert" className="absolute inset-x-0 bottom-0 bg-red-700 p-3 text-center text-sm text-white">{error}</p> : null}
    </div>
  );
}
