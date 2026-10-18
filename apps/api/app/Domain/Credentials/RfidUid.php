<?php

namespace App\Domain\Credentials;

/**
 * Normalisasi UID RFID, profil `generic` (docs/hardware/INTEGRASI_PERANGKAT.md §1). Pembaca keyboard-wedge
 * mengirim UID dalam format yang bergantung model; profil ini hanya merapikan variasi tampilan (spasi, `:`, `-`,
 * huruf kecil) dan TIDAK mengubah basis angka atau urutan byte. Artinya kartu harus didaftarkan lewat pembaca
 * dengan format keluaran yang sama dengan pembaca di terminal. Profil khusus model ditambahkan setelah uji fisik.
 *
 * UID bukan rahasia (PRD 7.1): ini bukan kontrol keamanan, hanya kunci pencocokan.
 */
final class RfidUid
{
    public const PROFILE = 'generic';

    public static function normalize(string $raw): ?string
    {
        $clean = strtoupper((string) preg_replace('/[\s:\-]+/', '', $raw));

        return preg_match('/^[0-9A-F]{6,20}$/', $clean) === 1 ? $clean : null;
    }

    /** Petunjuk tampilan untuk daftar kredensial; bukan nilai lengkap. */
    public static function hint(string $canonical): string
    {
        return '…'.substr($canonical, -4);
    }

    /** Kunci hash RFID diberi awalan jenis agar tidak pernah bertabrakan dengan token QR. */
    public static function hashKey(string $canonical): string
    {
        return 'rfid:'.$canonical;
    }
}
