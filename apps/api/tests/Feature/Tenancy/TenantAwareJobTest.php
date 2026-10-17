<?php

use App\Domain\Tenancy\DatabaseContext;
use App\Domain\Tenancy\TenantAwareJob;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// T12 — job tanpa konteks tenant gagal tertutup.

final class CountMembershipsJob extends TenantAwareJob
{
    public static ?int $seen = null;

    public static ?string $contextDuringRun = null;

    protected function run(): void
    {
        self::$seen = (int) DB::table('school_memberships')->count();
        self::$contextDuringRun = app(DatabaseContext::class)->schoolId();
    }
}

beforeEach(function () {
    CountMembershipsJob::$seen = null;
    CountMembershipsJob::$contextDuringRun = null;
    $this->a = School::factory()->create(['code' => 'sekolah-a']);
    $this->b = School::factory()->create(['code' => 'sekolah-b']);
    $this->addMember($this->a, User::factory()->create());
    $this->addMember($this->b, User::factory()->create());
    $this->addMember($this->b, User::factory()->create());
});

it('T12: job tanpa school_id gagal dengan exception, bukan membaca data kosong', function () {
    expect(fn () => (new CountMembershipsJob(''))->handle($this->dbContext()))->toThrow(LogicException::class, 'tanpa school_id');

    expect(CountMembershipsJob::$seen)->toBeNull();
});

it('T12: job hanya melihat data sekolahnya dan konteks dipulihkan sesudahnya', function () {
    (new CountMembershipsJob($this->b->id))->handle($this->dbContext());
    expect(CountMembershipsJob::$seen)->toBe(2)->and(CountMembershipsJob::$contextDuringRun)->toBe($this->b->id);

    (new CountMembershipsJob($this->a->id))->handle($this->dbContext());
    expect(CountMembershipsJob::$seen)->toBe(1)->and(CountMembershipsJob::$contextDuringRun)->toBe($this->a->id);

    expect($this->dbContext()->schoolId())->toBeNull();
});

it('T12: konteks dipulihkan walau job melempar exception', function () {
    $job = new class($this->a->id) extends TenantAwareJob
    {
        protected function run(): void
        {
            throw new RuntimeException('job gagal');
        }
    };

    expect(fn () => $job->handle($this->dbContext()))->toThrow(RuntimeException::class, 'job gagal');
    expect($this->dbContext()->schoolId())->toBeNull();
});

it('T12: tanpa konteks, query langsung ke tabel tenant tidak mengembalikan baris (gagal tertutup oleh RLS)', function () {
    expect(DB::table('school_memberships')->count())->toBe(0);
});
