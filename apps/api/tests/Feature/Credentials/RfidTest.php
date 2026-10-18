<?php

use App\Domain\Credentials\RfidUid;
use App\Domain\Identity\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Attendance/ScanHelpers.php';

// Tahap 4A — kartu RFID: normalisasi UID, pendaftaran, deteksi kartu terpakai, scan lewat RFID

beforeEach(fn () => scanSetUp()->call($this));

afterEach(fn () => Carbon::setTestNow());

function rfidApi(string $student, string $uid)
{
    $t = test();

    return $t->actingAs($t->admin)->postJson("/api/v1/schools/sekolah-a/students/{$student}/credentials/rfid", ['uid' => $uid]);
}

it('menormalkan UID: spasi, titik dua, strip, huruf kecil; menolak yang bukan heksadesimal atau terlalu pendek/panjang', function () {
    expect(RfidUid::normalize(' 04:a3-1b 2c '))->toBe('04A31B2C')
        ->and(RfidUid::normalize('0012345678'))->toBe('0012345678') // nol di depan dipertahankan
        ->and(RfidUid::normalize('12345'))->toBeNull()
        ->and(RfidUid::normalize(str_repeat('A', 21)))->toBeNull()
        ->and(RfidUid::normalize('SA1.ABCDEFGHIJKLMNOPQRSTUVWXYZ'))->toBeNull()
        ->and(RfidUid::normalize('GHIJKLMN'))->toBeNull();
});

it('mendaftarkan kartu RFID: hanya hash dan petunjuk empat karakter tersimpan', function () {
    $res = rfidApi($this->student, '04:A3:1B:2C')->assertCreated()
        ->assertJsonPath('data.credential_type', 'rfid')
        ->assertJsonPath('data.value_hint', '…1B2C')
        ->assertJsonPath('data.status', 'active');
    expect($res->getContent())->not->toContain('04A31B2C');

    $this->useSchool($this->school);
    $row = DB::table('student_credentials')->where('credential_type', 'rfid')->first();
    expect($row->reader_profile)->toBe('generic')
        ->and(json_encode(DB::table('audit_logs')->where('entity_type', 'student_credential')->get()))->not->toContain('04A31B2C');
});

it('kartu yang sudah dipakai siswa lain ditolak dengan menyebut pemiliknya; kartu sendiri juga ditolak', function () {
    rfidApi($this->student, '04A31B2C')->assertCreated();
    $other = scanStudent($this, 'Budi Santoso', '1002');

    rfidApi($other, '04a3 1b2c')->assertStatus(409)->assertJsonPath('code', 'CREDENTIAL_IN_USE')
        ->assertJsonPath('detail', 'Kartu ini sedang dipakai oleh Ahmad Rizky Pratama. Cabut kartu tersebut lebih dulu.');
    rfidApi($this->student, '04A31B2C')->assertStatus(409);
});

it('kartu yang dicabut boleh didaftarkan lagi ke siswa lain; mengganti kartu mencabut yang lama', function () {
    $first = rfidApi($this->student, '04A31B2C')->json('data.id');
    $second = rfidApi($this->student, 'AABBCCDD')->assertCreated()->assertJsonPath('data.replaces_credential_id', $first)->json('data.id');
    $this->useSchool($this->school);
    expect(DB::table('student_credentials')->where('id', $first)->value('status'))->toBe('revoked');

    $other = scanStudent($this, 'Budi Santoso', '1002');
    rfidApi($other, '04A31B2C')->assertCreated();
    expect($second)->not->toBe($first);
});

it('format UID tidak valid ditolak; siswa nonaktif ditolak', function () {
    rfidApi($this->student, 'bukan-uid!')->assertStatus(409);
    $this->inSchool($this->school, fn () => Student::query()->where('id', $this->student)->update(['status' => 'inactive']));
    rfidApi($this->student, '04A31B2C')->assertStatus(409);
});

it('izin: hanya yang boleh mengelola kredensial', function () {
    $principal = User::factory()->create();
    $this->addMember($this->school, $principal, [Role::Principal]);

    $this->actingAs($principal)->postJson("/api/v1/schools/sekolah-a/students/{$this->student}/credentials/rfid", ['uid' => '04A31B2C'])->assertForbidden();
});

it('scan RFID mencatat kehadiran dengan metode rfid, apa pun variasi penulisan UID', function () {
    rfidApi($this->student, '04A31B2C')->assertCreated();

    scan('04a3-1b2c')->assertOk()->assertJsonPath('data.code', 'CHECK_IN_OK')->assertJsonPath('data.student.display_name', 'Ahmad P.');
    scan('04A31B2C')->assertJsonPath('data.code', 'ALREADY_CHECKED_IN');

    $this->useSchool($this->school);
    expect(DB::table('attendance_events')->where('result', 'accepted')->value('method'))->toBe('rfid');
});

it('UID tidak dikenal, kartu diblokir, atau dicabut ditolak; QR dan RFID siswa yang sama saling melengkapi', function () {
    scan('DEADBEEF')->assertJsonPath('data.code', 'UNKNOWN_CREDENTIAL');

    $id = rfidApi($this->student, '04A31B2C')->json('data.id');
    $this->actingAs($this->admin)->postJson("/api/v1/schools/sekolah-a/credentials/{$id}/block", ['reason' => 'Kartu hilang'])->assertOk();
    scan('04A31B2C')->assertJsonPath('data.code', 'CREDENTIAL_BLOCKED');

    $this->actingAs($this->admin)->postJson("/api/v1/schools/sekolah-a/credentials/{$id}/revoke", ['reason' => 'Hilang permanen'])->assertOk();
    scan('04A31B2C')->assertJsonPath('data.code', 'CREDENTIAL_BLOCKED');

    scan($this->token)->assertJsonPath('data.code', 'CHECK_IN_OK'); // QR tetap berlaku
    $this->useSchool($this->school);
    expect(DB::table('attendance_events')->where('result', 'accepted')->value('method'))->toBe('qr');
});

it('nilai UID mentah tidak tersimpan di event, termasuk untuk kartu tak dikenal', function () {
    scan('DEADBEEF1234');
    $this->useSchool($this->school);
    $dump = json_encode(DB::table('attendance_events')->select('reason_code', 'response_snapshot', 'metadata')->get());
    expect($dump)->not->toContain('DEADBEEF1234');
});
