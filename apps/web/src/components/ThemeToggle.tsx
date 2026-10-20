"use client";

import { useSyncExternalStore } from "react";
import { IconMoon, IconSun } from "@/components/icons";

export const THEME_KEY = "sentra-theme";

/**
 * Skrip yang dijalankan sebelum render pertama agar tidak ada kedipan tema: memakai pilihan tersimpan,
 * atau preferensi sistem bila belum pernah memilih. Dipasang di <head> (lihat RootLayout, diberi nonce CSP).
 */
export const THEME_INIT_SCRIPT = `(function(){try{var t=localStorage.getItem("${THEME_KEY}");if(!t){t=window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"}document.documentElement.classList.toggle("dark",t==="dark")}catch(e){}})();`;

function subscribe(onChange: () => void): () => void {
  const observer = new MutationObserver(onChange);
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });

  return () => observer.disconnect();
}

const isDark = () => document.documentElement.classList.contains("dark");

/** Tombol ganti tema terang/gelap; pilihan disimpan di peramban, awalnya mengikuti sistem. */
export function ThemeToggle({ className = "" }: { className?: string }) {
  const dark = useSyncExternalStore(subscribe, isDark, () => false);

  function toggle() {
    const root = document.documentElement;
    root.classList.add("theme-fade");
    const next = !root.classList.contains("dark");
    root.classList.toggle("dark", next);
    try {
      localStorage.setItem(THEME_KEY, next ? "dark" : "light");
    } catch {
      // Penyimpanan diblokir: tema tetap berlaku untuk sesi ini.
    }
    window.setTimeout(() => root.classList.remove("theme-fade"), 450);
  }

  return (
    <button
      type="button"
      onClick={toggle}
      aria-label={dark ? "Ganti ke mode terang" : "Ganti ke mode gelap"}
      title={dark ? "Mode terang" : "Mode gelap"}
      className={`relative inline-flex size-10 items-center justify-center overflow-hidden rounded-full bg-white text-zinc-700 shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-lift dark:bg-zinc-900 dark:text-zinc-200 ${className}`}
    >
      <IconSun className={`absolute transition-all duration-500 ${dark ? "rotate-90 scale-0 opacity-0" : "rotate-0 scale-100 opacity-100"}`} />
      <IconMoon className={`absolute transition-all duration-500 ${dark ? "rotate-0 scale-100 opacity-100" : "-rotate-90 scale-0 opacity-0"}`} />
    </button>
  );
}
