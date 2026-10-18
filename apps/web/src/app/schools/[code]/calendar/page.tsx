"use client";

import type { Schemas } from "@sentra/api-client";
import { useEffect, useState } from "react";
import { FormAlert, SecondaryButton, SelectField } from "@/components/ui";
import { api } from "@/lib/api";
import { toFormFailure } from "@/lib/errors";
import { fetchAll } from "@/lib/fetch-all";
import { useSchool } from "../school-context";

type CalendarData = NonNullable<Schemas["CalendarEnvelope"]>["data"];
type SchoolClass = Schemas["SchoolClass"];

const WEEKDAYS = ["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"];

function currentMonth(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

function shiftMonth(month: string, delta: number): string {
  const [y, m] = month.split("-").map(Number);
  const d = new Date(Date.UTC(y, m - 1 + delta, 1));
  return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, "0")}`;
}

function monthTitle(month: string): string {
  const [y, m] = month.split("-").map(Number);
  return new Intl.DateTimeFormat("id-ID", { month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(Date.UTC(y, m - 1, 1)));
}

export default function CalendarPage() {
  const { code, can } = useSchool();
  const canListClasses = can("school.academic.view");
  const [month, setMonth] = useState(currentMonth);
  const [classId, setClassId] = useState("");
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [calendar, setCalendar] = useState<CalendarData | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!canListClasses) return;
    let cancelled = false;
    fetchAll(async (page) => {
      const { data, error: problem } = await api.GET("/schools/{school}/classes", {
        params: { path: { school: code }, query: { page, status: "active" } },
      });
      if (problem !== undefined) throw problem;
      return data;
    })
      .then((all) => {
        if (!cancelled) setClasses(all);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [canListClasses, code]);

  useEffect(() => {
    let cancelled = false;
    api
      .GET("/schools/{school}/calendar", {
        params: { path: { school: code }, query: { month, ...(classId ? { class_id: classId } : {}) } },
      })
      .then(({ data, error: problem }) => {
        if (cancelled) return;
        if (problem !== undefined) setError(toFormFailure(problem).message);
        else if (data) {
          setError(null);
          setCalendar(data.data);
        }
      })
      .catch((e) => {
        if (!cancelled) setError(toFormFailure(e).message);
      });
    return () => {
      cancelled = true;
    };
  }, [code, month, classId]);

  // Kalender ditampilkan Senin–Minggu: sel kosong di awal sampai hari pertama bulan.
  const leading = calendar ? calendar.days[0].day_of_week - 1 : 0;

  return (
    <>
      <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Kalender</h1>

      <div className="flex flex-wrap items-end gap-3">
        <SecondaryButton type="button" onClick={() => setMonth((m) => shiftMonth(m, -1))}>
          Bulan sebelumnya
        </SecondaryButton>
        <h2 aria-live="polite" className="min-w-40 text-center text-lg font-medium capitalize text-zinc-900 dark:text-zinc-50">
          {monthTitle(month)}
        </h2>
        <SecondaryButton type="button" onClick={() => setMonth((m) => shiftMonth(m, 1))}>
          Bulan berikutnya
        </SecondaryButton>
        {canListClasses ? (
          <div className="ml-auto w-48">
            <SelectField label="Tampilkan jadwal untuk" name="calendar_class" value={classId} onChange={(event) => setClassId(event.target.value)}>
              <option value="">Seluruh sekolah</option>
              {classes.map((c) => (
                <option key={c.id} value={c.id}>
                  Kelas {c.name}
                </option>
              ))}
            </SelectField>
          </div>
        ) : null}
      </div>

      {error ? <FormAlert>{error}</FormAlert> : null}

      {calendar ? (
        <div className="overflow-x-auto">
          <div className="grid min-w-[42rem] grid-cols-7 gap-px rounded-xl border border-zinc-200 bg-zinc-200 text-sm dark:border-zinc-800 dark:bg-zinc-800">
            {WEEKDAYS.map((d) => (
              <div key={d} className="bg-zinc-50 px-2 py-1.5 text-center font-medium text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                {d}
              </div>
            ))}
            {Array.from({ length: leading }, (_, i) => (
              <div key={`pad-${i}`} className="bg-white dark:bg-zinc-950" />
            ))}
            {calendar.days.map((day) => (
              <div
                key={day.date}
                data-date={day.date}
                className={`min-h-20 px-2 py-1.5 ${day.holiday ? "bg-red-50 dark:bg-red-950/40" : "bg-white dark:bg-zinc-950"}`}
              >
                <div className="font-medium text-zinc-900 dark:text-zinc-100">{Number(day.date.slice(8))}</div>
                {day.holiday ? (
                  <div className="text-red-700 dark:text-red-400">Libur: {day.holiday.name}</div>
                ) : day.schedule ? (
                  <div className="text-zinc-700 dark:text-zinc-300">
                    Masuk {day.schedule.check_in_start}–{day.schedule.check_in_deadline}
                    <br />
                    Pulang {day.schedule.check_out_start}
                  </div>
                ) : (
                  <div className="text-zinc-400 dark:text-zinc-600">Tidak ada sesi</div>
                )}
              </div>
            ))}
          </div>
        </div>
      ) : error ? null : (
        <p role="status" className="text-zinc-600 dark:text-zinc-400">
          Memuat…
        </p>
      )}
    </>
  );
}
