<?php

namespace App\Domain\Academic\Actions;

use App\Domain\Academic\ConstraintViolations;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tahun ajaran: buat, ubah, dan aktivasi. Setiap perubahan dan baris auditnya satu transaksi.
 *
 * Tumpang tindih tanggal dan "paling banyak satu aktif" dijaga constraint database; pelanggaran karena
 * balapan diterjemahkan oleh ConstraintViolations. Aktivasi mengunci baris sekolah agar dua aktivasi
 * bersamaan berjalan berurutan.
 */
final class ManageAcademicYears
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, start_date: string, end_date: string}  $data
     */
    public function create(School $school, User $actor, array $data): AcademicYear
    {
        return $this->run($school, function () use ($school, $actor, $data) {
            $year = AcademicYear::query()->create([
                'school_id' => $school->id,
                'name' => $data['name'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_active' => false,
            ]);
            $year->refresh();

            $this->audit->record('school.academic_year.created', $school->id, 'academic_year', $year->id, null, $this->snapshot($year), null, $actor);

            return $year;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  subset dari name, start_date, end_date
     */
    public function update(School $school, User $actor, string $yearId, array $changes): AcademicYear
    {
        return $this->run($school, function () use ($school, $actor, $yearId, $changes) {
            $year = $this->find($school, $yearId);
            $before = $this->snapshot($year);

            $year->fill($changes)->save();
            $year->refresh();

            $this->audit->record('school.academic_year.updated', $school->id, 'academic_year', $year->id, $before, $this->snapshot($year), null, $actor);

            return $year;
        });
    }

    /**
     * Menjadikan tahun ajaran ini satu-satunya yang aktif. Idempoten bila sudah aktif.
     */
    public function activate(School $school, User $actor, string $yearId): AcademicYear
    {
        return $this->run($school, function () use ($school, $actor, $yearId) {
            DB::table('schools')->where('id', $school->id)->lockForUpdate()->first(['id']);

            $year = $this->find($school, $yearId);
            if ($year->is_active) {
                return $year;
            }

            $before = $this->snapshot($year);
            $previous = AcademicYear::query()->where('school_id', $school->id)->where('is_active', true)->first();

            // Urutan penting: yang lama dinonaktifkan dulu agar indeks "satu aktif" tidak terlanggar.
            if ($previous !== null) {
                $previousBefore = $this->snapshot($previous);
                $previous->forceFill(['is_active' => false])->save();
                $this->audit->record('school.academic_year.deactivated', $school->id, 'academic_year', $previous->id, $previousBefore, $this->snapshot($previous), null, $actor);
            }

            $year->forceFill(['is_active' => true])->save();
            $year->refresh();

            $this->audit->record('school.academic_year.activated', $school->id, 'academic_year', $year->id, $before, $this->snapshot($year), null, $actor);

            return $year;
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function run(School $school, callable $work): mixed
    {
        try {
            return $this->database->runInSchool($school->id, fn () => DB::transaction(fn () => $work()));
        } catch (QueryException $e) {
            throw ConstraintViolations::translate($e) ?? $e;
        }
    }

    private function find(School $school, string $yearId): AcademicYear
    {
        return AcademicYear::query()->where('school_id', $school->id)->find($yearId) ?? throw new NotFoundHttpException;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AcademicYear $year): array
    {
        return [
            'name' => $year->name,
            'start_date' => $year->start_date->toDateString(),
            'end_date' => $year->end_date->toDateString(),
            'is_active' => $year->is_active,
        ];
    }
}
