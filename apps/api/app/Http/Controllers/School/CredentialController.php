<?php

namespace App\Http\Controllers\School;

use App\Domain\Credentials\Actions\ManageCredentials;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\OptionalReasonRequest;
use App\Http\Requests\ReasonRequest;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCredential;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Kredensial QR siswa. Token mentah hanya ada di respons penerbitan (`Cache-Control: no-store`) dan tidak dapat
 * diambil lagi; kehilangan kartu berarti terbit ulang.
 */
final class CredentialController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageCredentials $credentials) {}

    public function index(School $school, string $student): JsonResponse
    {
        Student::query()->find($student) ?? throw new NotFoundHttpException;

        $rows = StudentCredential::query()
            ->where('student_id', $student)
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (StudentCredential $c) => self::present($c))->all()]);
    }

    public function issueForStudent(Request $request, School $school, string $student): JsonResponse
    {
        $issued = $this->credentials->issueQr($this->tenant->school(), $this->actor($request), $student);

        return $this->noStore(response()->json(['data' => ['credential' => self::present($issued['credential']), 'token' => $issued['token']]], 201));
    }

    public function issueForClass(Request $request, School $school, string $schoolClass): JsonResponse
    {
        $validated = $request->validate(['mode' => ['sometimes', 'string', 'in:missing,all']]);
        $issued = $this->credentials->issueForClass($this->tenant->school(), $this->actor($request), $schoolClass, $validated['mode'] ?? 'missing');

        return $this->noStore(response()->json([
            'data' => array_map(fn (array $row) => [
                'student' => ['id' => $row['student']->id, 'student_number' => $row['student']->student_number, 'full_name' => $row['student']->full_name],
                'credential' => self::present($row['credential']),
                'token' => $row['token'],
            ], $issued),
            'meta' => ['issued' => count($issued)],
        ], 201));
    }

    public function block(ReasonRequest $request, School $school, string $credential): JsonResponse
    {
        return response()->json(['data' => self::present($this->credentials->block($this->tenant->school(), $this->actor($request), $credential, $request->reason()))]);
    }

    public function unblock(OptionalReasonRequest $request, School $school, string $credential): JsonResponse
    {
        return response()->json(['data' => self::present($this->credentials->unblock($this->tenant->school(), $this->actor($request), $credential, $request->reason()))]);
    }

    public function revoke(ReasonRequest $request, School $school, string $credential): JsonResponse
    {
        return response()->json(['data' => self::present($this->credentials->revoke($this->tenant->school(), $this->actor($request), $credential, $request->reason()))]);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store, private');
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(StudentCredential $c): array
    {
        return [
            'id' => $c->id,
            'student_id' => $c->student_id,
            'credential_type' => $c->credential_type,
            'status' => $c->status,
            'status_reason' => $c->status_reason,
            'issued_at' => $c->issued_at->toIso8601String(),
            'blocked_at' => $c->blocked_at?->toIso8601String(),
            'revoked_at' => $c->revoked_at?->toIso8601String(),
            'replaces_credential_id' => $c->replaces_credential_id,
        ];
    }
}
