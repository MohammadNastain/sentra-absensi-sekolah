<?php

namespace App\Http\Controllers\School;

use App\Domain\Membership\Actions\ManageStudentAccounts;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\ClassEnrollment;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Akun siswa untuk LMS (ADR 0010 §1). Respons memuat kata sandi awal sekali saja, jadi tidak boleh di-cache.
 */
final class StudentAccountController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageStudentAccounts $accounts) {}

    /** Membuat akun untuk daftar siswa, atau semua siswa aktif sebuah kelas yang belum punya akun. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_ids' => ['required_without:class_id', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['uuid', 'distinct'],
            'class_id' => ['required_without:student_ids', 'uuid', Rule::exists('classes', 'id')->where('school_id', $this->tenant->school()->id)],
        ]);

        $ids = isset($data['student_ids']) ? array_values($data['student_ids']) : $this->classStudentIds((string) $data['class_id']);
        $result = $this->accounts->create($this->tenant->school(), $this->actor($request), $ids);

        return response()->json(['data' => $result], 201)->header('Cache-Control', 'no-store, private');
    }

    public function resetPassword(Request $request, School $school, string $student): JsonResponse
    {
        $credentials = $this->accounts->resetPassword($this->tenant->school(), $this->actor($request), $student);

        return response()->json(['data' => $credentials])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Siswa aktif yang terdaftar di kelas hari ini dan belum punya akun, maksimal 200 per permintaan.
     *
     * @return list<string>
     */
    private function classStudentIds(string $classId): array
    {
        $today = now($this->tenant->school()->timezone)->toDateString();

        return array_values(Student::query()
            ->whereNull('membership_id')
            ->where('status', Student::STATUS_ACTIVE)
            ->whereIn('id', ClassEnrollment::query()->where('class_id', $classId)
                ->where('start_date', '<=', $today)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
                ->select('student_id'))
            ->orderBy('student_number')
            ->limit(200)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all());
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
