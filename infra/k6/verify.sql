-- Jalankan setelah uji pada database sentra_load. Semua hasil harus 0 baris / 0.
-- 1) Tidak boleh ada lebih dari satu check-in accepted per siswa per hari.
SELECT student_id, attendance_date, count(*) FROM attendance_events
WHERE event_type = 'check_in' AND result = 'accepted' GROUP BY 1, 2 HAVING count(*) > 1;
-- 2) Setiap baris harian menunjuk event check-in yang ada.
SELECT d.id FROM daily_attendance d LEFT JOIN attendance_events e ON e.id = d.check_in_event_id
WHERE d.check_in_event_id IS NOT NULL AND e.id IS NULL;
-- Ringkasan hasil scan.
SELECT result, count(*) FROM attendance_events GROUP BY 1 ORDER BY 1;
