<?php

namespace App\Domain\Lms;

use App\Models\LmsAssignment;
use App\Models\LmsSubmission;
use App\Models\Notification;
use App\Models\School;
use Illuminate\Support\Facades\DB;

/**
 * Membuat notifikasi dalam aplikasi untuk siswa (ADR 0010 §8): tugas baru terbit, tugas dinilai, tugas diminta revisi.
 * Dipanggil di dalam transaksi aksi asalnya (konteks sekolah sudah aktif) sehingga notifikasi batal bersama aksinya.
 * Hanya siswa yang punya akun aktif yang diberi notifikasi; isinya tidak memuat komentar guru.
 */
final class LmsNotifier
{
    /** Siswa yang terdaftar di kelas tugas hari ini dan punya akun aktif diberi tahu bahwa tugas terbit. */
    public function assignmentPublished(School $school, LmsAssignment $assignment): void
    {
        $today = now($school->timezone)->toDateString();
        $userIds = DB::table('class_enrollments as e')
            ->join('students as s', fn ($j) => $j->on('s.id', '=', 'e.student_id')->on('s.school_id', '=', 'e.school_id'))
            ->join('school_memberships as m', fn ($j) => $j->on('m.id', '=', 's.membership_id')->on('m.school_id', '=', 's.school_id'))
            ->where('e.school_id', $school->id)->where('e.class_id', $assignment->class_id)
            ->where('e.start_date', '<=', $today)->where(fn ($q) => $q->whereNull('e.end_date')->orWhere('e.end_date', '>=', $today))
            ->where('m.status', 'active')
            ->distinct()->pluck('m.user_id');

        $now = now();
        $rows = [];
        foreach ($userIds as $userId) {
            $rows[] = [
                'school_id' => $school->id, 'user_id' => $userId, 'type' => Notification::TYPE_ASSIGNMENT_PUBLISHED,
                'title' => 'Tugas baru: '.$assignment->title,
                'body' => $assignment->due_at === null ? 'Tidak ada tenggat.' : 'Tenggat '.$assignment->due_at->setTimezone($school->timezone)->format('d/m/Y H:i').'.',
                'data' => json_encode(['assignment_id' => $assignment->id], JSON_THROW_ON_ERROR), 'created_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }
    }

    /** Pemilik pengumpulan diberi tahu bahwa tugasnya dinilai. */
    public function submissionGraded(School $school, LmsAssignment $assignment, LmsSubmission $submission): void
    {
        $max = rtrim(rtrim((string) $assignment->max_score, '0'), '.');
        $score = rtrim(rtrim((string) $submission->score, '0'), '.');
        $this->toStudent($school, $submission, Notification::TYPE_SUBMISSION_GRADED, 'Tugas dinilai: '.$assignment->title, "Nilai Anda {$score} dari {$max}.");
    }

    /** Pemilik pengumpulan diberi tahu bahwa tugasnya diminta direvisi. */
    public function submissionReturned(School $school, LmsAssignment $assignment, LmsSubmission $submission): void
    {
        $this->toStudent($school, $submission, Notification::TYPE_SUBMISSION_RETURNED, 'Perlu revisi: '.$assignment->title, 'Guru meminta Anda memperbaiki dan mengirim ulang.');
    }

    private function toStudent(School $school, LmsSubmission $submission, string $type, string $title, string $body): void
    {
        $userId = DB::table('students as s')
            ->join('school_memberships as m', fn ($j) => $j->on('m.id', '=', 's.membership_id')->on('m.school_id', '=', 's.school_id'))
            ->where('s.school_id', $school->id)->where('s.id', $submission->student_id)->where('m.status', 'active')
            ->value('m.user_id');
        if ($userId === null) {
            return;
        }

        DB::table('notifications')->insert([
            'school_id' => $school->id, 'user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body,
            'data' => json_encode(['assignment_id' => $submission->assignment_id], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
