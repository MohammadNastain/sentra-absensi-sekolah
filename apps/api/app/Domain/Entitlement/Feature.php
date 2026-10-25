<?php

namespace App\Domain\Entitlement;

/**
 * Registri fitur yang dapat dijual per sekolah (ADR 0009). Enum ini sumber kebenaran: tabel `features`
 * hanya cermin dan dijaga sama lewat tes. Fitur yang belum `released()` tidak bisa menjadi efektif walau
 * tercantum di paket atau override.
 *
 * Kode memakai titik sebagai hirarki; fitur anak otomatis membutuhkan induknya.
 */
enum Feature: string
{
    case Attendance = 'attendance';
    case AttendanceScanQr = 'attendance.scan_qr';
    case AttendanceScanRfid = 'attendance.scan_rfid';
    case AttendanceLeave = 'attendance.leave';
    case AttendanceCorrections = 'attendance.corrections';
    case AttendanceReports = 'attendance.reports';

    case Students = 'students';
    case Teachers = 'teachers';

    case Academic = 'academic';
    case AcademicYears = 'academic.years';
    case AcademicClasses = 'academic.classes';
    case AcademicSubjects = 'academic.subjects';
    case AcademicTeachingSchedule = 'academic.teaching_schedule';

    case Reports = 'reports';

    case Lms = 'lms';
    case LmsMaterials = 'lms.materials';
    case LmsAttachments = 'lms.attachments';
    case LmsAssignments = 'lms.assignments';
    case LmsSubmissions = 'lms.submissions';
    case LmsGrading = 'lms.grading';

    case Exams = 'exams';
    case ReportCards = 'report_cards';
    case Finance = 'finance';
    case Admissions = 'admissions';
    case ParentPortal = 'parent_portal';
    case Notifications = 'notifications';

    /** Induk hirarki (null untuk fitur tingkat atas). */
    public function parent(): ?self
    {
        return match ($this) {
            self::AttendanceScanQr, self::AttendanceScanRfid, self::AttendanceLeave,
            self::AttendanceCorrections, self::AttendanceReports => self::Attendance,
            self::AcademicYears, self::AcademicClasses, self::AcademicSubjects,
            self::AcademicTeachingSchedule => self::Academic,
            self::LmsMaterials, self::LmsAttachments, self::LmsAssignments,
            self::LmsSubmissions, self::LmsGrading => self::Lms,
            default => null,
        };
    }

    /**
     * Prasyarat selain induk: fitur lain yang harus efektif lebih dulu.
     *
     * @return list<self>
     */
    public function requires(): array
    {
        return match ($this) {
            self::Attendance, self::Academic, self::Reports, self::Finance, self::ParentPortal => [self::Students],
            self::AcademicTeachingSchedule => [self::Teachers, self::AcademicYears, self::AcademicClasses, self::AcademicSubjects],
            self::Lms, self::Exams, self::ReportCards => [self::Academic],
            self::LmsAttachments => [self::LmsMaterials],
            self::LmsSubmissions => [self::LmsAssignments],
            self::LmsGrading => [self::LmsSubmissions],
            default => [],
        };
    }

    /**
     * Induk ditambah prasyarat: semuanya harus efektif agar fitur ini efektif.
     *
     * @return list<self>
     */
    public function prerequisites(): array
    {
        $parent = $this->parent();

        return $parent === null ? $this->requires() : [$parent, ...$this->requires()];
    }

    /** Sudah ada di aplikasi. Fitur yang belum dirilis tidak pernah efektif (PRD §5.3). */
    public function released(): bool
    {
        return match ($this) {
            self::Attendance, self::AttendanceScanQr, self::AttendanceScanRfid, self::AttendanceLeave,
            self::AttendanceCorrections, self::AttendanceReports,
            self::Students, self::Teachers,
            self::Academic, self::AcademicYears, self::AcademicClasses, self::AcademicSubjects,
            self::AcademicTeachingSchedule => true,
            self::Lms, self::LmsMaterials, self::LmsAttachments, self::LmsAssignments,
            self::LmsSubmissions, self::LmsGrading => (bool) config('sentra.lms_released', false),
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Attendance => 'Absensi',
            self::AttendanceScanQr => 'Scan QR/barcode',
            self::AttendanceScanRfid => 'Tap RFID',
            self::AttendanceLeave => 'Izin dan sakit',
            self::AttendanceCorrections => 'Koreksi kehadiran',
            self::AttendanceReports => 'Rekap dan ekspor',
            self::Students => 'Data siswa',
            self::Teachers => 'Data guru',
            self::Academic => 'Data akademik',
            self::AcademicYears => 'Tahun ajaran dan semester',
            self::AcademicClasses => 'Kelas/rombel',
            self::AcademicSubjects => 'Mata pelajaran',
            self::AcademicTeachingSchedule => 'Jadwal mengajar',
            self::Reports => 'Laporan',
            self::Lms => 'LMS',
            self::LmsMaterials => 'Materi pembelajaran',
            self::LmsAttachments => 'Lampiran materi',
            self::LmsAssignments => 'Tugas',
            self::LmsSubmissions => 'Pengumpulan tugas',
            self::LmsGrading => 'Penilaian dan komentar',
            self::Exams => 'Ujian online',
            self::ReportCards => 'Rapor digital',
            self::Finance => 'Keuangan/SPP',
            self::Admissions => 'PPDB',
            self::ParentPortal => 'Portal orang tua',
            self::Notifications => 'Notifikasi',
        };
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(fn (self $f) => $f->value, self::cases());
    }
}
