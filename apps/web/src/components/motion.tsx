"use client";

import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from "react";

function prefersReducedMotion(): boolean {
  return typeof window !== "undefined" && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

/** Angka yang menghitung naik dari nilai sebelumnya ke `value` (easing keluar, tanpa pustaka). */
export function CountUp({ value, decimals = 0, duration = 900 }: { value: number; decimals?: number; duration?: number }) {
  const [shown, setShown] = useState(0);
  const from = useRef(0);

  useEffect(() => {
    if (prefersReducedMotion()) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- gerak dimatikan: langsung nilai akhir
      setShown(value);
      from.current = value;
      return;
    }
    const start = performance.now();
    const origin = from.current;
    let frame = 0;
    const tick = (now: number) => {
      const t = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - t, 3);
      setShown(origin + (value - origin) * eased);
      if (t < 1) frame = requestAnimationFrame(tick);
      else from.current = value;
    };
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [value, duration]);

  return <span className="tabular-nums">{shown.toLocaleString("id-ID", { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}</span>;
}

/** Cincin persentase beranimasi (seperti pada dasbor referensi); `children` ditaruh di tengahnya. */
export function Ring({
  percent,
  size = 132,
  stroke = 12,
  children,
  label,
}: {
  percent: number;
  size?: number;
  stroke?: number;
  children?: ReactNode;
  label: string;
}) {
  const [drawn, setDrawn] = useState(0);
  const radius = (size - stroke) / 2;
  const circumference = 2 * Math.PI * radius;
  const clamped = Math.max(0, Math.min(100, percent));

  useEffect(() => {
    const id = requestAnimationFrame(() => setDrawn(clamped));
    return () => cancelAnimationFrame(id);
  }, [clamped]);

  return (
    <div className="relative inline-flex items-center justify-center" style={{ width: size, height: size }} role="img" aria-label={label}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="-rotate-90">
        <defs>
          <linearGradient id="ring-grad" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stopColor="var(--color-brand-400)" />
            <stop offset="100%" stopColor="var(--color-brand-700)" />
          </linearGradient>
        </defs>
        <circle cx={size / 2} cy={size / 2} r={radius} fill="none" strokeWidth={stroke} className="stroke-zinc-100 dark:stroke-zinc-800" />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="url(#ring-grad)"
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={circumference}
          strokeDashoffset={circumference * (1 - drawn / 100)}
          style={{ transition: "stroke-dashoffset 1.2s cubic-bezier(0.22, 1, 0.36, 1)" }}
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">{children}</div>
    </div>
  );
}

/** Pembungkus yang muncul bertahap saat dimuat; `index` menentukan urutan. */
export function Reveal({ index = 0, className = "", children }: { index?: number; className?: string; children: ReactNode }) {
  return (
    <div className={`animate-rise ${className}`} style={{ animationDelay: `${index * 70}ms` } as CSSProperties}>
      {children}
    </div>
  );
}
