<?php

use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\LmsAttachment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingSlot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// ADR 0010 §2–§5, §8 — materi dan lampiran: hak per kelas-mapel, visibilitas draf, berkas privat, unduhan terperiksa.

const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/**
 * Berkas unggahan sungguhan di disk sementara. `UploadedFile::fake()` melaporkan MIME dari nama berkas, bukan dari
 * isinya, sehingga tidak bisa menguji sniffing isi.
 */
function upload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'lms');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function pdf(string $name = 'materi.pdf'): UploadedFile
{
    return upload($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}

beforeEach(function () {
    config(['sentra.lms_released' => true]);
    Storage::fake('lms');

    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    foreach (['lms', 'lms.materials', 'lms.attachments'] as $feature) {
        setFeatureOverride($this->school->id, $feature, 'grant');
    }

    $year = $this->inSchool($this->school, fn () => AcademicYear::query()->create([
        'school_id' => $this->school->id, 'name' => 'Uji', 'start_date' => '2020-01-01', 'end_date' => '2099-12-31',
    ]));
    $class = fn (string $name) => $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => $year->id, 'name' => $name, 'grade_level' => 7, 'status' => 'active',
    ])->id);
    $subject = fn (string $name) => $this->inSchool($this->school, fn () => Subject::query()->create(['school_id' => $this->school->id, 'name' => $name])->id);
    $this->class7a = $class('7A');
    $this->class7b = $class('7B');
    $this->math = $subject('Matematika');
    $this->art = $subject('Seni');

    $teacher = function (string $name, string $class, string $subject, int $day) {
        $user = User::factory()->create();
        $membership = $this->addMember($this->school, $user, [Role::Teacher]);
        $teacherId = $this->inSchool($this->school, fn () => Teacher::query()->create([
            'school_id' => $this->school->id, 'full_name' => $name, 'membership_id' => $membership->id, 'status' => 'active',
        ])->id);
        $this->inSchool($this->school, fn () => TeachingSlot::query()->create([
            'school_id' => $this->school->id, 'class_id' => $class, 'subject_id' => $subject, 'teacher_id' => $teacherId,
            'day_of_week' => $day, 'start_time' => '07:00', 'end_time' => '08:00',
        ]));

        return $user;
    };
    $this->teacher = $teacher('Bu Guru', $this->class7a, $this->math, 1);
    $this->coTeacher = $teacher('Pak Rekan', $this->class7a, $this->math, 2);
    $this->artTeacher = $teacher('Bu Seni', $this->class7b, $this->art, 1);

    $student = function (string $number, string $class) {
        $user = User::factory()->create();
        $membership = $this->addMember($this->school, $user, [Role::Student]);
        $this->inSchool($this->school, function () use ($number, $class, $membership) {
            $row = Student::query()->create([
                'school_id' => $this->school->id, 'full_name' => 'Siswa '.$number, 'student_number' => $number, 'status' => 'active', 'membership_id' => $membership->id,
            ]);
            ClassEnrollment::query()->create(['school_id' => $this->school->id, 'student_id' => $row->id, 'class_id' => $class, 'start_date' => '2020-01-01']);
        });

        return $user;
    };
    $this->student7a = $student('1', $this->class7a);
    $this->student7b = $student('2', $this->class7b);

    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
    $this->principal = User::factory()->create();
    $this->addMember($this->school, $this->principal, [Role::Principal]);

    $this->url = '/api/v1/schools/sekolah-a/lms';
    $this->create = fn (User $by, array $over = []) => $this->actingAs($by)->postJson("{$this->url}/materials", array_merge([
        'class_id' => $this->class7a, 'subject_id' => $this->math, 'title' => 'Bab 1', 'body' => 'Isi materi',
    ], $over));
});

it('guru membuat materi draf hanya untuk kelas-mapelnya; admin untuk mana saja; kelas tak dikenal ditolak', function () {
    ($this->create)($this->teacher)->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.can_manage', true);
    ($this->create)($this->teacher, ['subject_id' => $this->art])->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
    ($this->create)($this->teacher, ['class_id' => $this->class7b])->assertForbidden();
    ($this->create)($this->admin, ['class_id' => $this->class7b, 'subject_id' => $this->art])->assertCreated();
    ($this->create)($this->admin, ['class_id' => fake()->uuid()])->assertUnprocessable()->assertJsonValidationErrors('class_id');
    ($this->create)($this->student7a)->assertForbidden();
    ($this->create)($this->principal)->assertForbidden();
});

it('draf tidak terlihat siswa, kepala sekolah, dan guru lain; terbit membuatnya terlihat oleh yang berhak saja', function () {
    $id = ($this->create)($this->teacher)->json('data.id');

    foreach ([$this->student7a, $this->principal, $this->coTeacher, $this->artTeacher] as $viewer) {
        $this->actingAs($viewer)->getJson("{$this->url}/materials/{$id}")->assertNotFound();
        expect($this->actingAs($viewer)->getJson("{$this->url}/materials")->assertOk()->json('data'))->toBe([]);
    }
    foreach ([$this->teacher, $this->admin] as $viewer) {
        $this->actingAs($viewer)->getJson("{$this->url}/materials/{$id}")->assertOk();
    }

    $this->actingAs($this->teacher)->patchJson("{$this->url}/materials/{$id}", ['status' => 'published'])
        ->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.published_at', fn ($v) => $v !== null);

    foreach ([$this->student7a, $this->principal, $this->coTeacher, $this->teacher, $this->admin] as $viewer) {
        $this->actingAs($viewer)->getJson("{$this->url}/materials/{$id}")->assertOk();
        expect($this->actingAs($viewer)->getJson("{$this->url}/materials")->json('data.*.id'))->toBe([$id]);
    }
    // Siswa kelas lain dan guru mapel lain tidak melihat materi kelas 7A.
    foreach ([$this->student7b, $this->artTeacher] as $outsider) {
        $this->actingAs($outsider)->getJson("{$this->url}/materials/{$id}")->assertNotFound();
        expect($this->actingAs($outsider)->getJson("{$this->url}/materials")->json('data'))->toBe([]);
    }

    $this->actingAs($this->teacher)->patchJson("{$this->url}/materials/{$id}", ['status' => 'draft'])->assertOk()->assertJsonPath('data.published_at', null);
    $this->actingAs($this->student7a)->getJson("{$this->url}/materials/{$id}")->assertNotFound();
});

it('siswa dan kepala sekolah tidak dapat mengubah atau menghapus; guru rekan boleh mengubah materi yang terbit di kelas-mapelnya', function () {
    $id = ($this->create)($this->teacher)->json('data.id');
    $this->actingAs($this->teacher)->patchJson("{$this->url}/materials/{$id}", ['status' => 'published'])->assertOk();

    foreach ([$this->student7a, $this->principal] as $user) {
        $this->actingAs($user)->patchJson("{$this->url}/materials/{$id}", ['title' => 'x'])->assertForbidden();
        $this->actingAs($user)->deleteJson("{$this->url}/materials/{$id}")->assertForbidden();
    }
    $this->actingAs($this->coTeacher)->patchJson("{$this->url}/materials/{$id}", ['title' => 'Bab 1 revisi'])->assertOk()->assertJsonPath('data.title', 'Bab 1 revisi');
    $this->actingAs($this->artTeacher)->patchJson("{$this->url}/materials/{$id}", ['title' => 'x'])->assertNotFound();
});

it('judul wajib, isi teks polos disimpan apa adanya', function () {
    ($this->create)($this->teacher, ['title' => '   '])->assertUnprocessable()->assertJsonValidationErrors('title');
    $id = ($this->create)($this->teacher, ['body' => '<script>alert(1)</script>'])->assertCreated()->json('data.id');
    $this->actingAs($this->teacher)->getJson("{$this->url}/materials/{$id}")->assertJsonPath('data.body', '<script>alert(1)</script>');
});

it('mengunggah lampiran: berkas privat berkunci acak, metadata tersimpan, audit tercatat', function () {
    $id = ($this->create)($this->teacher)->json('data.id');

    $res = $this->actingAs($this->teacher)->post("{$this->url}/materials/{$id}/attachments", ['file' => pdf('Bab 1.pdf')], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.name', 'Bab 1.pdf')->assertJsonPath('data.mime', 'application/pdf');

    $row = $this->inSchool($this->school, fn () => LmsAttachment::query()->findOrFail($res->json('data.id')));
    expect($row->storage_key)->toStartWith($this->school->id.'/')->not->toContain('Bab')
        ->and($row->sha256)->toHaveLength(64);
    Storage::disk('lms')->assertExists($row->storage_key);

    $this->actingAs($this->teacher)->getJson("{$this->url}/materials/{$id}")->assertJsonPath('data.attachments.0.name', 'Bab 1.pdf')->assertJsonPath('data.attachment_count', 1);
    $this->inSchool($this->school, fn () => expect(DB::table('audit_logs')->where('action', 'school.lms_attachment.created')->count())->toBe(1));
});

it('menolak berkas berbahaya atau tidak sesuai: ekstensi di luar allowlist, isi tidak cocok, terlalu besar, terlalu banyak', function () {
    $id = ($this->create)($this->teacher)->json('data.id');
    $upload = fn (UploadedFile $file) => $this->actingAs($this->teacher)->post("{$this->url}/materials/{$id}/attachments", ['file' => $file], ['Accept' => 'application/json']);

    $upload(upload('shell.php', '<?php echo 1;'))->assertUnprocessable();
    $upload(upload('app.exe', 'MZ'))->assertUnprocessable();
    $upload(upload('page.html', '<html></html>'))->assertUnprocessable();
    // Ekstensi png/pdf tetapi isinya lain: sniffing isi menolaknya.
    $upload(upload('gambar.png', '<?php system($_GET["c"]);'))->assertUnprocessable();
    $upload(upload('palsu.pdf', 'bukan pdf, hanya teks'))->assertUnprocessable();
    $upload(upload('besar.pdf', "%PDF-1.4\n".str_repeat('a', 10 * 1024 * 1024)))->assertUnprocessable();

    $upload(upload('gambar.png', base64_decode(PNG_1PX)))->assertCreated();
    $upload(upload('catatan.txt', 'halo'))->assertCreated();
    foreach (range(1, 3) as $n) {
        $upload(pdf("m{$n}.pdf"))->assertCreated();
    }
    $upload(pdf('keenam.pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');

    expect(Storage::disk('lms')->allFiles())->toHaveCount(5);
});

it('lampiran hanya dapat diunggah dan dihapus pengelola materi', function () {
    $id = ($this->create)($this->teacher)->json('data.id');
    $this->actingAs($this->teacher)->patchJson("{$this->url}/materials/{$id}", ['status' => 'published']);

    $this->actingAs($this->student7a)->post("{$this->url}/materials/{$id}/attachments", ['file' => pdf()], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($this->artTeacher)->post("{$this->url}/materials/{$id}/attachments", ['file' => pdf()], ['Accept' => 'application/json'])->assertNotFound();

    $attId = $this->actingAs($this->teacher)->post("{$this->url}/materials/{$id}/attachments", ['file' => pdf()], ['Accept' => 'application/json'])->json('data.id');
    $this->actingAs($this->student7a)->deleteJson("{$this->url}/attachments/{$attId}")->assertForbidden();
    $this->actingAs($this->artTeacher)->deleteJson("{$this->url}/attachments/{$attId}")->assertNotFound();

    $key = $this->inSchool($this->school, fn () => LmsAttachment::query()->findOrFail($attId)->storage_key);
    $this->actingAs($this->teacher)->deleteJson("{$this->url}/attachments/{$attId}")->assertNoContent();
    Storage::disk('lms')->assertMissing($key);
    $this->inSchool($this->school, fn () => expect(LmsAttachment::query()->count())->toBe(0));
});

it('unduhan: paksa unduh, nosniff, nama aman; hanya yang berhak atas materinya', function () {
    $id = ($this->create)($this->teacher)->json('data.id');
    $attId = $this->actingAs($this->teacher)->post("{$this->url}/materials/{$id}/attachments", ['file' => pdf('../../etc/pass"wd.pdf')], ['Accept' => 'application/json'])->json('data.id');
    $download = "{$this->url}/attachments/{$attId}/download";

    // Materi masih draf: siswa tidak dapat mengunduh, penulis dan admin dapat.
    $this->actingAs($this->student7a)->get($download)->assertNotFound();
    $this->actingAs($this->principal)->get($download)->assertNotFound();
    $this->actingAs($this->teacher)->get($download)->assertOk();

    $this->actingAs($this->teacher)->patchJson("{$this->url}/materials/{$id}", ['status' => 'published']);

    $res = $this->actingAs($this->student7a)->get($download)->assertOk();
    expect($res->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($res->headers->get('Content-Disposition'))->toStartWith('attachment;')->not->toContain('../')->not->toContain("\n")
        ->and($res->headers->get('Cache-Control'))->toContain('no-store');
    $this->actingAs($this->principal)->get($download)->assertOk();
    $this->actingAs($this->admin)->get($download)->assertOk();

    // Siswa kelas lain dan guru mapel lain: seolah tidak ada.
    $this->actingAs($this->student7b)->get($download)->assertNotFound();
    $this->actingAs($this->artTeacher)->get($download)->assertNotFound();
});

it('mencabut fitur lampiran menutup unduhan dan unggahan, tetapi materi tetap terbaca', function () {
    $id = ($this->create)($this->teacher)->json('data.id');
    $attId = $this->actingAs($this->teacher)->post("{$this->url}/materials/{$id}/attachments", ['file' => pdf()], ['Accept' => 'application/json'])->json('data.id');
    $this->actingAs($this->teacher)->patchJson("{$this->url}/materials/{$id}", ['status' => 'published']);

    setFeatureOverride($this->school->id, 'lms.attachments', 'revoke');

    $this->actingAs($this->student7a)->get("{$this->url}/attachments/{$attId}/download")->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED')->assertJsonPath('feature', 'lms.attachments');
    $this->actingAs($this->teacher)->post("{$this->url}/materials/{$id}/attachments", ['file' => pdf()], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($this->student7a)->getJson("{$this->url}/materials/{$id}")->assertOk();

    setFeatureOverride($this->school->id, 'lms.materials', 'revoke');
    $this->actingAs($this->student7a)->getJson("{$this->url}/materials/{$id}")->assertForbidden()->assertJsonPath('feature', 'lms.materials');
});

it('modul belum dirilis: semua rute LMS ditutup walau ada override', function () {
    config(['sentra.lms_released' => false]);

    $this->actingAs($this->admin)->getJson("{$this->url}/materials")->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
});

it('menghapus materi menghapus lampiran dan berkasnya', function () {
    $id = ($this->create)($this->teacher)->json('data.id');
    $this->actingAs($this->teacher)->post("{$this->url}/materials/{$id}/attachments", ['file' => pdf()], ['Accept' => 'application/json'])->assertCreated();
    expect(Storage::disk('lms')->allFiles())->toHaveCount(1);

    $this->actingAs($this->teacher)->deleteJson("{$this->url}/materials/{$id}")->assertNoContent();

    expect(Storage::disk('lms')->allFiles())->toBe([]);
    $this->inSchool($this->school, fn () => expect(LmsAttachment::query()->count())->toBe(0));
    $this->actingAs($this->teacher)->getJson("{$this->url}/materials/{$id}")->assertNotFound();
});

it('lintas sekolah: admin sekolah lain tidak melihat, mengubah, atau mengunduh', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    foreach (['lms', 'lms.materials', 'lms.attachments'] as $feature) {
        setFeatureOverride($other->id, $feature, 'grant');
    }
    $outsider = User::factory()->create();
    $this->addMember($other, $outsider, [Role::SchoolAdmin]);

    $id = ($this->create)($this->teacher)->json('data.id');
    $attId = $this->actingAs($this->teacher)->post("{$this->url}/materials/{$id}/attachments", ['file' => pdf()], ['Accept' => 'application/json'])->json('data.id');

    $b = '/api/v1/schools/sekolah-b/lms';
    $this->actingAs($outsider)->getJson("{$b}/materials/{$id}")->assertNotFound();
    $this->actingAs($outsider)->patchJson("{$b}/materials/{$id}", ['title' => 'x'])->assertNotFound();
    $this->actingAs($outsider)->deleteJson("{$b}/materials/{$id}")->assertNotFound();
    $this->actingAs($outsider)->get("{$b}/attachments/{$attId}/download")->assertNotFound();
    // Dan bukan anggota sekolah A sama sekali.
    $this->actingAs($outsider)->getJson("{$this->url}/materials")->assertNotFound();
    expect($this->actingAs($outsider)->getJson("{$b}/materials")->json('data'))->toBe([]);
});

it('mencatat audit untuk terbit dan tarik, tetapi tidak untuk simpan draf', function () {
    $id = ($this->create)($this->teacher)->json('data.id');
    $this->actingAs($this->teacher)->patchJson("{$this->url}/materials/{$id}", ['body' => 'diubah'])->assertOk();
    $this->actingAs($this->teacher)->patchJson("{$this->url}/materials/{$id}", ['status' => 'published'])->assertOk();
    $this->actingAs($this->teacher)->patchJson("{$this->url}/materials/{$id}", ['status' => 'draft'])->assertOk();

    $this->inSchool($this->school, function () {
        $actions = DB::table('audit_logs')->where('entity_type', 'lms_material')->orderBy('created_at')->orderBy('id')->pluck('action')->all();
        expect($actions)->toBe(['school.lms_material.created', 'school.lms_material.published', 'school.lms_material.unpublished']);
    });
});
