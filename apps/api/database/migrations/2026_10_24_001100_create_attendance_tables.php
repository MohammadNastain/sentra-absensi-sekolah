<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inti absensi (DATABASE_DESIGN §3.5, Tahap 3C): sesi per tanggal+jadwal, event append-only, dan proyeksi
 * harian per siswa. Integritas ditegakkan database: event tidak bisa diubah/dihapus, satu check_in dan satu
 * check_out accepted per siswa per hari, kunci idempotensi unik per sekolah, dan jadwal yang sudah dipakai sesi
 * tidak bisa diubah isinya (hanya ditutup/dinonaktifkan).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE attendance_sessions (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                session_date date NOT NULL,
                schedule_id uuid NOT NULL,
                timezone text NOT NULL,
                status text NOT NULL DEFAULT 'open',
                closed_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT attendance_sessions_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT attendance_sessions_unique UNIQUE (school_id, session_date, schedule_id),
                CONSTRAINT attendance_sessions_schedule_fk FOREIGN KEY (school_id, schedule_id)
                    REFERENCES schedules (school_id, id),
                CONSTRAINT attendance_sessions_status_check CHECK (status IN ('open', 'closed', 'finalized'))
            );

            CREATE TABLE attendance_events (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                student_id uuid NULL,
                credential_id uuid NULL,
                attendance_session_id uuid NULL,
                attendance_date date NULL,
                event_type text NULL,
                method text NOT NULL,
                result text NOT NULL,
                reason_code text NULL,
                occurred_at_server timestamptz NOT NULL,
                client_captured_at timestamptz NULL,
                device_id uuid NULL,
                created_by_user_id uuid NULL REFERENCES users (id),
                assist_reason_code text NULL,
                assist_note text NULL,
                is_late boolean NULL,
                late_minutes integer NULL,
                is_early_leave boolean NULL,
                idempotency_key uuid NOT NULL,
                credential_fingerprint bytea NULL,
                response_snapshot jsonb NOT NULL,
                metadata jsonb NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT attendance_events_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT attendance_events_idempotency_unique UNIQUE (school_id, idempotency_key),
                CONSTRAINT attendance_events_student_fk FOREIGN KEY (school_id, student_id)
                    REFERENCES students (school_id, id),
                CONSTRAINT attendance_events_credential_fk FOREIGN KEY (school_id, credential_id)
                    REFERENCES student_credentials (school_id, id),
                CONSTRAINT attendance_events_session_fk FOREIGN KEY (school_id, attendance_session_id)
                    REFERENCES attendance_sessions (school_id, id),
                CONSTRAINT attendance_events_device_fk FOREIGN KEY (school_id, device_id)
                    REFERENCES devices (school_id, id),
                CONSTRAINT attendance_events_method_check CHECK (method IN ('qr', 'rfid', 'assisted_manual')),
                CONSTRAINT attendance_events_result_check CHECK (result IN ('accepted', 'duplicate', 'rejected', 'needs_review')),
                CONSTRAINT attendance_events_type_check CHECK (event_type IS NULL OR event_type IN ('check_in', 'check_out')),
                CONSTRAINT attendance_events_accepted_complete CHECK (
                    result <> 'accepted' OR (student_id IS NOT NULL AND event_type IS NOT NULL
                        AND attendance_session_id IS NOT NULL AND attendance_date IS NOT NULL)
                ),
                CONSTRAINT attendance_events_assist_complete CHECK (
                    method <> 'assisted_manual' OR (created_by_user_id IS NOT NULL AND assist_reason_code IS NOT NULL)
                ),
                CONSTRAINT attendance_events_fingerprint_length CHECK (credential_fingerprint IS NULL OR octet_length(credential_fingerprint) = 8)
            );
            CREATE UNIQUE INDEX attendance_events_one_accepted
                ON attendance_events (school_id, student_id, attendance_date, event_type) WHERE result = 'accepted';
            CREATE INDEX attendance_events_recent_index ON attendance_events (school_id, occurred_at_server DESC);
            CREATE INDEX attendance_events_student_date_index ON attendance_events (school_id, student_id, attendance_date);
            CREATE INDEX attendance_events_device_index ON attendance_events (school_id, device_id, occurred_at_server DESC);
            CREATE INDEX attendance_events_issues_index ON attendance_events (school_id, result, occurred_at_server) WHERE result <> 'accepted';

            CREATE OR REPLACE FUNCTION attendance_events_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'attendance_events bersifat append-only' USING ERRCODE = '42501';
            END
            $$;
            CREATE TRIGGER attendance_events_append_only
                BEFORE UPDATE OR DELETE ON attendance_events
                FOR EACH ROW EXECUTE FUNCTION attendance_events_immutable();
            CREATE TRIGGER attendance_events_no_truncate
                BEFORE TRUNCATE ON attendance_events
                FOR EACH STATEMENT EXECUTE FUNCTION attendance_events_immutable();
            REVOKE UPDATE, DELETE, TRUNCATE ON attendance_events FROM sentra_app;

            CREATE TABLE daily_attendance (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                student_id uuid NOT NULL,
                attendance_date date NOT NULL,
                attendance_session_id uuid NULL,
                class_id uuid NULL,
                attendance_status text NOT NULL DEFAULT 'belum_tercatat',
                check_in_event_id uuid NULL,
                check_out_event_id uuid NULL,
                late_minutes integer NULL,
                early_leave boolean NOT NULL DEFAULT false,
                early_leave_status text NULL,
                source text NOT NULL DEFAULT 'automatic',
                version integer NOT NULL DEFAULT 1,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT daily_attendance_school_id_id_unique UNIQUE (school_id, id),
                CONSTRAINT daily_attendance_student_date_unique UNIQUE (school_id, student_id, attendance_date),
                CONSTRAINT daily_attendance_student_fk FOREIGN KEY (school_id, student_id)
                    REFERENCES students (school_id, id),
                CONSTRAINT daily_attendance_session_fk FOREIGN KEY (school_id, attendance_session_id)
                    REFERENCES attendance_sessions (school_id, id),
                CONSTRAINT daily_attendance_class_fk FOREIGN KEY (school_id, class_id)
                    REFERENCES classes (school_id, id),
                CONSTRAINT daily_attendance_check_in_fk FOREIGN KEY (school_id, check_in_event_id)
                    REFERENCES attendance_events (school_id, id),
                CONSTRAINT daily_attendance_check_out_fk FOREIGN KEY (school_id, check_out_event_id)
                    REFERENCES attendance_events (school_id, id),
                CONSTRAINT daily_attendance_status_check CHECK (attendance_status IN
                    ('belum_tercatat', 'hadir', 'terlambat', 'izin', 'sakit', 'dispensasi', 'alpa_belum_diverifikasi', 'alpa')),
                CONSTRAINT daily_attendance_source_check CHECK (source IN ('automatic', 'manual_adjustment')),
                CONSTRAINT daily_attendance_early_status_check CHECK (early_leave_status IS NULL OR early_leave_status IN ('pending', 'approved', 'rejected')),
                CONSTRAINT daily_attendance_present_needs_event CHECK (
                    attendance_status NOT IN ('hadir', 'terlambat') OR check_in_event_id IS NOT NULL OR source = 'manual_adjustment'
                ),
                CONSTRAINT daily_attendance_late_minutes_check CHECK (late_minutes IS NULL OR late_minutes >= 0)
            );
            CREATE INDEX daily_attendance_date_index ON daily_attendance (school_id, attendance_date);
            CREATE INDEX daily_attendance_class_date_index ON daily_attendance (school_id, class_id, attendance_date);
            CREATE INDEX daily_attendance_status_date_index ON daily_attendance (school_id, attendance_status, attendance_date);

            ALTER TABLE attendance_sessions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE attendance_sessions FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON attendance_sessions
                USING (school_id = app_school_id()) WITH CHECK (school_id = app_school_id());
            ALTER TABLE attendance_events ENABLE ROW LEVEL SECURITY;
            ALTER TABLE attendance_events FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON attendance_events
                USING (school_id = app_school_id()) WITH CHECK (school_id = app_school_id());
            ALTER TABLE daily_attendance ENABLE ROW LEVEL SECURITY;
            ALTER TABLE daily_attendance FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON daily_attendance
                USING (school_id = app_school_id()) WITH CHECK (school_id = app_school_id());

            -- Jadwal yang sudah dirujuk sesi absensi tidak boleh berubah isinya (versi baru, bukan edit).
            -- Menutup (effective_until) dan menonaktifkan (is_active) tetap diperbolehkan.
            CREATE OR REPLACE FUNCTION schedules_freeze_when_used() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (NEW.scope_type, NEW.grade_level, NEW.class_id, NEW.day_of_week, NEW.check_in_start, NEW.check_in_deadline,
                    NEW.late_grace_minutes, NEW.check_out_start, NEW.scheduled_check_out, NEW.effective_from)
                   IS DISTINCT FROM
                   (OLD.scope_type, OLD.grade_level, OLD.class_id, OLD.day_of_week, OLD.check_in_start, OLD.check_in_deadline,
                    OLD.late_grace_minutes, OLD.check_out_start, OLD.scheduled_check_out, OLD.effective_from)
                   AND EXISTS (SELECT 1 FROM attendance_sessions s WHERE s.school_id = OLD.school_id AND s.schedule_id = OLD.id)
                THEN
                    RAISE EXCEPTION 'Jadwal yang sudah dipakai sesi absensi tidak dapat diubah; terbitkan versi baru.' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER schedules_freeze_when_used
                BEFORE UPDATE ON schedules
                FOR EACH ROW EXECUTE FUNCTION schedules_freeze_when_used();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER schedules_freeze_when_used ON schedules;
            DROP FUNCTION schedules_freeze_when_used();
            DROP TABLE daily_attendance;
            DROP TABLE attendance_events;
            DROP FUNCTION attendance_events_immutable();
            DROP TABLE attendance_sessions;
            SQL);
    }
};
