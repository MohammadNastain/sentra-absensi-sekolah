"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";
import { IconBell } from "@/components/icons";
import { api } from "@/lib/api";
import { formatDateTime } from "@/lib/format";
import type { Schemas } from "@sentra/api-client";

type Item = Schemas["LmsNotification"];

/** Jeda pemeriksaan notifikasi baru selama tab terbuka. */
const POLL_MS = 60_000;

/** Lonceng notifikasi LMS (tugas baru, dinilai, diminta revisi) di bilah atas; memeriksa berkala selagi tab terlihat. */
export function NotificationBell({ code, timeZone }: { code: string; timeZone: string }) {
  const router = useRouter();
  const box = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<Item[]>([]);
  const [unread, setUnread] = useState(0);

  const load = useCallback(async () => {
    try {
      const { data } = await api.GET("/schools/{school}/lms/notifications", { params: { path: { school: code } } });
      if (data) {
        setItems(data.data.items);
        setUnread(data.data.unread_count);
      }
    } catch {
      // Gagal memuat notifikasi tidak boleh mengganggu halaman; coba lagi pada pemeriksaan berikutnya.
    }
  }, [code]);

  useEffect(() => {
    const first = setTimeout(load, 0);
    const timer = setInterval(() => document.visibilityState === "visible" && load(), POLL_MS);
    return () => {
      clearTimeout(first);
      clearInterval(timer);
    };
  }, [load]);

  useEffect(() => {
    if (!open) return;
    const away = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) setOpen(false);
    };
    const esc = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", away);
    document.addEventListener("keydown", esc);
    return () => {
      document.removeEventListener("mousedown", away);
      document.removeEventListener("keydown", esc);
    };
  }, [open]);

  async function markRead(id?: string) {
    try {
      const { data } = await api.POST("/schools/{school}/lms/notifications/read", { params: { path: { school: code } }, body: id ? { id } : undefined });
      if (data) setUnread(data.data.unread_count);
      setItems((list) => list.map((n) => (id === undefined || n.id === id ? { ...n, read: true } : n)));
    } catch {
      // Penandaan gagal: status baca disegarkan pada pemeriksaan berikutnya.
    }
  }

  function openItem(item: Item) {
    setOpen(false);
    if (!item.read) void markRead(item.id);
    if (item.assignment_id) router.push(`/schools/${code}/lms/assignments/${item.assignment_id}`);
  }

  return (
    <div ref={box} className="relative">
      <button
        type="button"
        onClick={() => {
          setOpen((v) => !v);
          if (!open) void load();
        }}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={unread > 0 ? `Notifikasi, ${unread} belum dibaca` : "Notifikasi"}
        className="relative rounded-xl bg-white p-2.5 text-zinc-700 shadow-card transition-shadow hover:shadow-lift dark:bg-zinc-900 dark:text-zinc-200"
      >
        <IconBell />
        {unread > 0 ? (
          <span className="absolute -right-1 -top-1 flex min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-bold leading-5 text-white">{unread > 9 ? "9+" : unread}</span>
        ) : null}
      </button>
      {open ? (
        <div role="dialog" aria-label="Notifikasi" className="fixed inset-x-3 top-16 z-30 animate-pop overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-lift sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-96 dark:border-zinc-800 dark:bg-zinc-900">
          <div className="flex items-center justify-between gap-3 px-4 py-3">
            <p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">Notifikasi</p>
            {unread > 0 ? (
              <button type="button" onClick={() => markRead()} className="text-xs font-semibold text-brand-700 hover:underline dark:text-brand-300">
                Tandai semua dibaca
              </button>
            ) : null}
          </div>
          {items.length === 0 ? (
            <p className="px-4 pb-6 pt-2 text-sm text-zinc-500">Belum ada notifikasi.</p>
          ) : (
            <ul className="max-h-[70dvh] divide-y divide-zinc-100 overflow-y-auto border-t border-zinc-100 sm:max-h-96 dark:divide-zinc-800 dark:border-zinc-800">
              {items.map((item) => (
                <li key={item.id}>
                  <button type="button" onClick={() => openItem(item)} className="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                    <span aria-hidden="true" className={`mt-1.5 size-2 shrink-0 rounded-full ${item.read ? "bg-transparent" : "bg-brand-600"}`} />
                    <span className="min-w-0 flex-1">
                      <span className={`block text-sm ${item.read ? "text-zinc-700 dark:text-zinc-300" : "font-semibold text-zinc-900 dark:text-zinc-50"}`}>{item.title}</span>
                      <span className="block text-xs text-zinc-600 dark:text-zinc-400">{item.body}</span>
                      <span className="mt-0.5 block text-[11px] text-zinc-500">{formatDateTime(item.created_at, timeZone)}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : null}
    </div>
  );
}
