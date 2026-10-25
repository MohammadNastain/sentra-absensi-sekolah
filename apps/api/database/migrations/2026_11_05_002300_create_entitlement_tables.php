<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Entitlement fitur per sekolah (ADR 0009): registri fitur, paket, langganan, dan override.
 *
 * - features/plans/plan_features: global, tanpa RLS, hanya-baca bagi sentra_app (diubah lewat migration), seperti roles.
 *   Isi `features` adalah cermin enum App\Domain\Entitlement\Feature (dijaga EntitlementRegistryTest).
 * - school_subscriptions/school_feature_overrides: RLS; sekolah hanya membaca miliknya, hanya konteks platform menulis.
 * - Semua sekolah yang sudah ada dipindah ke paket `legacy` (semua fitur yang sudah rilis) agar perilaku tidak berubah.
 */
return new class extends Migration
{
    /** kode => induk */
    private const FEATURES = [
        'attendance' => null,
        'attendance.scan_qr' => 'attendance',
        'attendance.scan_rfid' => 'attendance',
        'attendance.leave' => 'attendance',
        'attendance.corrections' => 'attendance',
        'attendance.reports' => 'attendance',
        'students' => null,
        'teachers' => null,
        'academic' => null,
        'academic.years' => 'academic',
        'academic.classes' => 'academic',
        'academic.subjects' => 'academic',
        'academic.teaching_schedule' => 'academic',
        'reports' => null,
        'lms' => null,
        'lms.materials' => 'lms',
        'lms.attachments' => 'lms',
        'lms.assignments' => 'lms',
        'lms.submissions' => 'lms',
        'lms.grading' => 'lms',
        'exams' => null,
        'report_cards' => null,
        'finance' => null,
        'admissions' => null,
        'parent_portal' => null,
        'notifications' => null,
    ];

    private const BASIC = [
        'students', 'attendance', 'attendance.scan_qr', 'attendance.scan_rfid', 'attendance.reports',
    ];

    private const STANDARD_EXTRA = [
        'attendance.leave', 'attendance.corrections', 'teachers', 'academic', 'academic.years',
        'academic.classes', 'academic.subjects', 'academic.teaching_schedule', 'reports',
    ];

    private const PREMIUM_EXTRA = [
        'lms', 'lms.materials', 'lms.attachments', 'lms.assignments', 'lms.submissions', 'lms.grading',
    ];

    /** Fitur yang sudah ada di aplikasi saat migration ini dibuat. */
    private const RELEASED = [
        'attendance', 'attendance.scan_qr', 'attendance.scan_rfid', 'attendance.leave', 'attendance.corrections',
        'attendance.reports', 'students', 'teachers', 'academic', 'academic.years', 'academic.classes',
        'academic.subjects', 'academic.teaching_schedule',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE features (
                code text PRIMARY KEY,
                parent_code text NULL REFERENCES features (code),
                CONSTRAINT features_code_format CHECK (code ~ '^[a-z_]+(\.[a-z_]+)?$')
            );

            CREATE TABLE plans (
                code text PRIMARY KEY,
                name text NOT NULL,
                price_per_student_idr integer NOT NULL,
                min_monthly_idr integer NOT NULL,
                is_active boolean NOT NULL DEFAULT true,
                CONSTRAINT plans_code_format CHECK (code ~ '^[a-z]+$'),
                CONSTRAINT plans_prices_check CHECK (price_per_student_idr >= 0 AND min_monthly_idr >= 0)
            );

            CREATE TABLE plan_features (
                plan_code text NOT NULL REFERENCES plans (code),
                feature_code text NOT NULL REFERENCES features (code),
                PRIMARY KEY (plan_code, feature_code)
            );

            CREATE TABLE school_subscriptions (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                plan_code text NOT NULL REFERENCES plans (code),
                status text NOT NULL DEFAULT 'active',
                starts_on date NOT NULL DEFAULT CURRENT_DATE,
                ends_on date NULL,
                created_by_user_id uuid NULL REFERENCES users (id),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT school_subscriptions_status_check CHECK (status IN ('trial', 'active', 'ended')),
                CONSTRAINT school_subscriptions_dates_check CHECK (ends_on IS NULL OR ends_on >= starts_on)
            );
            -- Satu langganan berjalan (bukan ended) per sekolah; riwayat tersimpan sebagai baris ended.
            CREATE UNIQUE INDEX school_subscriptions_current_unique ON school_subscriptions (school_id) WHERE status <> 'ended';
            CREATE INDEX school_subscriptions_plan_index ON school_subscriptions (plan_code);

            CREATE TABLE school_feature_overrides (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                school_id uuid NOT NULL REFERENCES schools (id),
                feature_code text NOT NULL REFERENCES features (code),
                effect text NOT NULL,
                reason text NULL,
                created_by_user_id uuid NULL REFERENCES users (id),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT school_feature_overrides_unique UNIQUE (school_id, feature_code),
                CONSTRAINT school_feature_overrides_effect_check CHECK (effect IN ('grant', 'revoke'))
            );
            SQL);

        $this->seedRegistry();

        // Backfill sebelum RLS aktif: migration berjalan tanpa konteks platform.
        DB::unprepared(<<<'SQL'
            INSERT INTO school_subscriptions (school_id, plan_code, status)
            SELECT id, 'legacy', 'active' FROM schools;

            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON features, plans, plan_features FROM sentra_app;

            ALTER TABLE school_subscriptions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE school_subscriptions FORCE ROW LEVEL SECURITY;
            CREATE POLICY read_own_or_platform ON school_subscriptions FOR SELECT
                USING (school_id = app_school_id() OR app_is_platform());
            CREATE POLICY platform_insert ON school_subscriptions FOR INSERT WITH CHECK (app_is_platform());
            CREATE POLICY platform_update ON school_subscriptions FOR UPDATE
                USING (app_is_platform()) WITH CHECK (app_is_platform());
            CREATE POLICY platform_delete ON school_subscriptions FOR DELETE USING (app_is_platform());

            ALTER TABLE school_feature_overrides ENABLE ROW LEVEL SECURITY;
            ALTER TABLE school_feature_overrides FORCE ROW LEVEL SECURITY;
            CREATE POLICY read_own_or_platform ON school_feature_overrides FOR SELECT
                USING (school_id = app_school_id() OR app_is_platform());
            CREATE POLICY platform_insert ON school_feature_overrides FOR INSERT WITH CHECK (app_is_platform());
            CREATE POLICY platform_update ON school_feature_overrides FOR UPDATE
                USING (app_is_platform()) WITH CHECK (app_is_platform());
            CREATE POLICY platform_delete ON school_feature_overrides FOR DELETE USING (app_is_platform());
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE school_feature_overrides;
            DROP TABLE school_subscriptions;
            DROP TABLE plan_features;
            DROP TABLE plans;
            DROP TABLE features;
            SQL);
    }

    private function seedRegistry(): void
    {
        // Induk dimasukkan lebih dulu (FK): fitur tingkat atas, lalu anak.
        $ordered = array_merge(
            array_filter(self::FEATURES, fn ($parent) => $parent === null),
            array_filter(self::FEATURES, fn ($parent) => $parent !== null),
        );

        foreach ($ordered as $code => $parent) {
            DB::insert('INSERT INTO features (code, parent_code) VALUES (?, ?)', [$code, $parent]);
        }

        $standard = array_merge(self::BASIC, self::STANDARD_EXTRA);
        $plans = [
            ['basic', 'Basic', 2000, 300000, true, self::BASIC],
            ['standard', 'Standard', 4000, 300000, true, $standard],
            ['premium', 'Premium', 7000, 300000, true, array_merge($standard, self::PREMIUM_EXTRA)],
            ['legacy', 'Legacy (semua fitur rilis)', 0, 0, false, self::RELEASED],
        ];

        foreach ($plans as [$code, $name, $price, $minimum, $active, $features]) {
            DB::insert(
                'INSERT INTO plans (code, name, price_per_student_idr, min_monthly_idr, is_active) VALUES (?, ?, ?, ?, ?)',
                [$code, $name, $price, $minimum, $active ? 'true' : 'false'],
            );

            foreach ($features as $feature) {
                DB::insert('INSERT INTO plan_features (plan_code, feature_code) VALUES (?, ?)', [$code, $feature]);
            }
        }
    }
};
