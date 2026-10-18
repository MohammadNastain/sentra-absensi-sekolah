<?php

namespace App\Domain\Attendance;

use App\Domain\Credentials\CredentialHasher;
use App\Domain\Credentials\QrToken;
use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\ClassEnrollment;
use App\Models\Device;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pipeline scan terminal (ARCHITECTURE §8). Langkah 3–9 berjalan dalam satu transaksi; baris harian dikunci
 * (SELECT … FOR UPDATE) sehingga scan beruntun untuk siswa yang sama diserialisasi. Waktu selalu waktu server.
 * Hasil ditolak/duplikat/perlu tinjauan tetap disimpan sebagai event diagnostik dan tidak pernah dihitung hadir.
 * Nilai mentah kredensial tidak disimpan: hanya sidik jari HMAC terpotong untuk kartu tak dikenal.
 */
final class RecordScanAction
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly CredentialHasher $hasher,
        private readonly ScheduleResolver $resolver,
        private readonly AttendanceStateMachine $machine,
    ) {}

    /**
     * @return array<string, mixed> payload respons (juga disimpan sebagai snapshot idempotensi)
     */
    public function record(School $school, Device $device, string $rawValue, string $idempotencyKey, ?Carbon $clientCapturedAt): array
    {
        $now = Carbon::now();

        // Percobaan kedua hanya terjadi bila dua request dengan kunci idempotensi sama berlomba: yang kalah membaca
        // snapshot milik pemenang.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $existing = $this->database->runInSchool($school->id, fn () => $this->existingSnapshot($school->id, $idempotencyKey));
            if ($existing !== null) {
                return $existing;
            }

            try {
                return $this->database->runInSchool($school->id, fn () => DB::transaction(
                    fn () => $this->process($school, $device, $rawValue, $idempotencyKey, $clientCapturedAt, $now),
                ));
            } catch (QueryException $e) {
                $message = (string) ($e->errorInfo[2] ?? '');
                if (($e->errorInfo[0] ?? null) !== '23505' || ! str_contains($message, 'attendance_events_')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Scan tidak dapat diproses karena konflik berulang.');
    }

    /**
     * @return array<string, mixed>
     */
    private function process(School $school, Device $device, string $rawValue, string $key, ?Carbon $clientAt, Carbon $now): array
    {
        $local = $now->copy()->setTimezone($school->timezone);
        $date = $local->toDateString();
        $normalized = QrToken::normalize($rawValue);
        $fingerprint = substr($this->hasher->hashHex('fp:'.$normalized), 0, 16);

        $base = [
            'school_id' => $school->id, 'attendance_date' => $date, 'device_id' => $device->id, 'idempotency_key' => $key,
            'occurred_at_server' => $now->toIso8601String(), 'client_captured_at' => $clientAt?->toIso8601String(),
        ];

        $credential = QrToken::looksLikeToken($rawValue) ? $this->findCredential($school->id, $normalized) : null;
        if ($credential === null) {
            return $this->finish($base + ['credential_fingerprint' => $fingerprint], new ScanDecision('rejected', 'UNKNOWN_CREDENTIAL', null), null, $now, $local);
        }

        $base += ['student_id' => (string) $credential->student_id, 'credential_id' => (string) $credential->id];
        if ($credential->status !== 'active') {
            return $this->finish($base, new ScanDecision('rejected', 'CREDENTIAL_BLOCKED', null), null, $now, $local);
        }

        $student = Student::query()->where('school_id', $school->id)->where('id', (string) $credential->student_id)->first();
        $enrollment = $student === null || $student->status !== Student::STATUS_ACTIVE ? null : ClassEnrollment::query()
            ->with('schoolClass')
            ->where('school_id', $school->id)
            ->where('student_id', $student->id)
            ->where('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))
            ->first();
        $class = $enrollment?->schoolClass;
        if ($student === null || ! $class instanceof SchoolClass) {
            return $this->finish($base, new ScanDecision('rejected', 'STUDENT_INACTIVE', null), null, $now, $local);
        }

        $who = ['student' => $student, 'class' => $class];
        $resolved = $this->resolver->resolveRange($school->id, $local->copy()->startOfDay(), $local->copy()->startOfDay(), $class)[$date] ?? null;
        if ($resolved?->holiday !== null) {
            return $this->finish($base, new ScanDecision('rejected', 'HOLIDAY', null), $who + ['holiday' => $resolved->holiday->name], $now, $local);
        }
        $schedule = $resolved?->schedule;
        if (! $schedule instanceof Schedule) {
            return $this->finish($base, new ScanDecision('rejected', 'NO_SCHEDULE', null), $who, $now, $local);
        }
        $window = ScheduleWindow::fromSchedule($schedule);

        $sessionId = $this->ensureSession($school, $date, $schedule);
        $daily = $this->lockDaily($school->id, $student->id, $date);

        $checkInAt = null;
        $checkInTime = null;
        if ($daily->check_in_event_id !== null) {
            $checkInTime = Carbon::parse((string) DB::table('attendance_events')->where('id', $daily->check_in_event_id)->value('occurred_at_server'))->setTimezone($school->timezone);
            $checkInAt = (int) $checkInTime->secondsSinceMidnight();
        }
        $checkOutTime = null;
        if ($daily->check_out_event_id !== null) {
            $checkOutTime = Carbon::parse((string) DB::table('attendance_events')->where('id', $daily->check_out_event_id)->value('occurred_at_server'))->setTimezone($school->timezone);
        }

        $decision = $this->machine->decide($window, (int) $local->secondsSinceMidnight(), $checkInAt, $daily->check_out_event_id !== null);
        $extra = $who + ['existing_check_in' => $checkInTime, 'existing_check_out' => $checkOutTime, 'window' => $window];
        $event = $this->finish($base + ['attendance_session_id' => $sessionId], $decision, $extra, $now, $local, returnId: true);

        if ($decision->isAccepted()) {
            $this->project($school->id, $daily->id, $decision, $event['id'], $sessionId, $class->id);
        }

        return $event['response'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $extra
     * @return array<string, mixed>|array{id: string, response: array<string, mixed>}
     */
    private function finish(array $row, ScanDecision $decision, ?array $extra, Carbon $now, Carbon $local, bool $returnId = false): array
    {
        $response = $this->response($decision, $extra, $now, $local);

        $id = (string) DB::scalar(
            "INSERT INTO attendance_events (school_id, student_id, credential_id, attendance_session_id, attendance_date, event_type, method, result, reason_code,
                    occurred_at_server, client_captured_at, device_id, is_late, late_minutes, is_early_leave, idempotency_key, credential_fingerprint, response_snapshot)
             VALUES (?, ?, ?, ?, ?, ?, 'qr', ?, ?, ?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'), ?::jsonb) RETURNING id",
            [
                $row['school_id'], $row['student_id'] ?? null, $row['credential_id'] ?? null, $row['attendance_session_id'] ?? null, $row['attendance_date'],
                $decision->eventType, $decision->result, $decision->code, $row['occurred_at_server'], $row['client_captured_at'], $row['device_id'],
                $decision->isLate, $decision->lateMinutes, $decision->isEarlyLeave, $row['idempotency_key'], $row['credential_fingerprint'] ?? null,
                json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
        );

        return $returnId ? ['id' => $id, 'response' => $response] : $response;
    }

    /**
     * @param  array<string, mixed>|null  $extra
     * @return array<string, mixed>
     */
    private function response(ScanDecision $d, ?array $extra, Carbon $now, Carbon $local): array
    {
        /** @var Student|null $student */
        $student = $extra['student'] ?? null;
        /** @var SchoolClass|null $class */
        $class = $extra['class'] ?? null;
        $hasStudent = ! in_array($d->code, ['CREDENTIAL_BLOCKED', 'UNKNOWN_CREDENTIAL', 'STUDENT_INACTIVE'], true);
        $name = $student === null ? '' : self::displayName($student->full_name);

        // Waktu yang ditampilkan untuk duplikat adalah waktu catatan yang sudah ada, bukan waktu scan ini.
        $shown = match ($d->code) {
            'ALREADY_CHECKED_IN' => $extra['existing_check_in'] ?? $local,
            'ALREADY_CHECKED_OUT' => $extra['existing_check_out'] ?? $local,
            default => $local,
        };
        $t = $shown->format('H.i');
        /** @var ScheduleWindow|null $window */
        $window = $extra['window'] ?? null;

        $message = match ($d->code) {
            'CHECK_IN_OK' => "Selamat datang, {$name}. Absensi masuk tercatat pukul {$t}.",
            'CHECK_IN_LATE' => "Absensi masuk tercatat pukul {$t} (terlambat {$d->lateMinutes} menit).",
            'CHECK_OUT_OK' => "Sampai jumpa, {$name}. Absensi pulang tercatat pukul {$t}.",
            'CHECK_OUT_EARLY' => "Absensi pulang tercatat pukul {$t} (sebelum jam pulang).",
            'ALREADY_CHECKED_IN' => "Absensi masuk sudah tercatat pukul {$t}.",
            'ALREADY_CHECKED_OUT' => "Absensi pulang sudah tercatat pukul {$t}.",
            'TOO_EARLY' => 'Absensi masuk dibuka pukul '.($window === null ? '' : gmdate('H.i', $window->checkInStart)).'.',
            'CHECKOUT_WITHOUT_CHECKIN' => 'Belum ada catatan masuk hari ini. Hubungi petugas.',
            'UNKNOWN_CREDENTIAL' => 'Kartu tidak dikenali.',
            'CREDENTIAL_BLOCKED' => 'Kartu diblokir. Hubungi petugas.',
            'STUDENT_INACTIVE' => 'Siswa tidak aktif atau belum memiliki kelas.',
            'HOLIDAY' => 'Hari ini libur'.(isset($extra['holiday']) ? ': '.$extra['holiday'] : '').'.',
            'NO_SCHEDULE' => 'Tidak ada jadwal absensi hari ini.',
            default => 'Scan diproses.',
        };

        return [
            'result' => $d->result,
            'code' => $d->code,
            'event_type' => $d->eventType,
            'student' => $hasStudent && $student instanceof Student ? ['display_name' => $name, 'class_name' => $class?->name, 'photo_url' => null] : null,
            'recorded_at' => $now->copy()->utc()->toIso8601String(),
            'recorded_at_local' => $local->format('H.i'),
            'message' => $message,
        ];
    }

    /** "Ahmad Rizky Pratama" → "Ahmad P." (nama depan + inisial nama belakang), seperlunya saja untuk layar publik. */
    public static function displayName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        if (count($parts) < 2) {
            return $parts[0] ?? '';
        }

        return $parts[0].' '.mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)).'.';
    }

    /** @return object{id: string, student_id: string, status: string}|null */
    private function findCredential(string $schoolId, string $normalized): ?object
    {
        return DB::selectOne(
            "SELECT id, student_id, status FROM student_credentials
             WHERE school_id = ? AND credential_type = 'qr' AND value_hash = decode(?, 'hex')
             ORDER BY (status = 'revoked') ASC LIMIT 1",
            [$schoolId, $this->hasher->hashHex($normalized)],
        );
    }

    private function ensureSession(School $school, string $date, Schedule $schedule): string
    {
        DB::insert(
            'INSERT INTO attendance_sessions (school_id, session_date, schedule_id, timezone) VALUES (?, ?, ?, ?) ON CONFLICT (school_id, session_date, schedule_id) DO NOTHING',
            [$school->id, $date, $schedule->id, $school->timezone],
        );

        return (string) DB::scalar(
            'SELECT id FROM attendance_sessions WHERE school_id = ? AND session_date = ? AND schedule_id = ?',
            [$school->id, $date, $schedule->id],
        );
    }

    /** @return object{id: string, check_in_event_id: string|null, check_out_event_id: string|null} */
    private function lockDaily(string $schoolId, string $studentId, string $date): object
    {
        DB::insert(
            'INSERT INTO daily_attendance (school_id, student_id, attendance_date) VALUES (?, ?, ?) ON CONFLICT (school_id, student_id, attendance_date) DO NOTHING',
            [$schoolId, $studentId, $date],
        );

        return DB::selectOne(
            'SELECT id, check_in_event_id, check_out_event_id FROM daily_attendance WHERE school_id = ? AND student_id = ? AND attendance_date = ? FOR UPDATE',
            [$schoolId, $studentId, $date],
        ) ?? throw new \LogicException('Baris harian tidak ditemukan setelah dibuat.');
    }

    private function project(string $schoolId, string $dailyId, ScanDecision $d, string $eventId, string $sessionId, string $classId): void
    {
        if ($d->eventType === 'check_in') {
            DB::update(
                'UPDATE daily_attendance SET attendance_status = ?, check_in_event_id = ?, late_minutes = ?, attendance_session_id = ?, class_id = ?, version = version + 1, updated_at = now() WHERE id = ? AND school_id = ?',
                [$d->isLate ? 'terlambat' : 'hadir', $eventId, $d->isLate ? $d->lateMinutes : null, $sessionId, $classId, $dailyId, $schoolId],
            );

            return;
        }

        DB::update(
            'UPDATE daily_attendance SET check_out_event_id = ?, early_leave = ?, early_leave_status = ?, version = version + 1, updated_at = now() WHERE id = ? AND school_id = ?',
            [$eventId, (bool) $d->isEarlyLeave, $d->isEarlyLeave ? 'pending' : null, $dailyId, $schoolId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingSnapshot(string $schoolId, string $key): ?array
    {
        $json = DB::scalar('SELECT response_snapshot FROM attendance_events WHERE school_id = ? AND idempotency_key = ?', [$schoolId, $key]);
        if (! is_string($json)) {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
