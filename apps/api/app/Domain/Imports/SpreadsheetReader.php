<?php

namespace App\Domain\Imports;

use DateTimeInterface;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use ZipArchive;

/**
 * Membaca CSV/XLSX secara streaming dengan batas ukuran dan jumlah baris (risiko R14, G4). Isi sel selalu
 * diperlakukan sebagai teks biasa: rumus tidak pernah dievaluasi, dan sel kosong menjadi null.
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 5000;

    /** Batas total ukuran terdekompresi XLSX; mencegah zip bomb. */
    private const MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;

    private const MAX_ZIP_ENTRIES = 200;

    /** @var array<string, string> alias header (setelah dikanonikalkan) → nama kolom standar */
    private const HEADER_ALIASES = [
        'nis' => 'nomor_induk',
        'no_induk' => 'nomor_induk',
        'nomor_induk_siswa' => 'nomor_induk',
        'nama' => 'nama_lengkap',
        'nama_siswa' => 'nama_lengkap',
        'tgl_lahir' => 'tanggal_lahir',
        'tanggal_lahir_siswa' => 'tanggal_lahir',
    ];

    /**
     * @return array{headers: list<string>, rows: array<int, array<string, string|null>>} kunci `rows` = nomor baris di file (header = 1)
     *
     * @throws ImportFileException
     */
    public function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        $this->assertSafe($path, $extension);

        $reader = $extension === 'xlsx'
            ? new XlsxReader
            : new CsvReader(new CsvOptions(FIELD_DELIMITER: $this->detectDelimiter($path)));

        $headers = [];
        $rows = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $index => $row) {
                    $cells = array_map($this->cell(...), $row->toArray());

                    if ($index === 1) {
                        $headers = array_map($this->canonicalHeader(...), array_map(fn (?string $h) => $h ?? '', $cells));

                        continue;
                    }
                    if (array_filter($cells, fn (?string $c) => $c !== null) === []) {
                        continue;
                    }
                    if (count($rows) >= self::MAX_ROWS) {
                        throw new ImportFileException('File berisi lebih dari '.self::MAX_ROWS.' baris. Pecah menjadi beberapa file.');
                    }

                    $assoc = [];
                    foreach ($headers as $i => $name) {
                        if ($name !== '' && ! array_key_exists($name, $assoc)) {
                            $assoc[$name] = $cells[$i] ?? null;
                        }
                    }
                    $rows[$index] = $assoc;
                }
                break; // hanya lembar pertama
            }
        } catch (ImportFileException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ImportFileException('File tidak dapat dibaca. Pastikan formatnya CSV atau XLSX yang valid.');
        } finally {
            $reader->close();
        }

        return ['headers' => array_values(array_filter($headers, fn (string $h) => $h !== '')), 'rows' => $rows];
    }

    /**
     * Memeriksa isi file (bukan hanya ekstensi): XLSX harus ZIP yang wajar, CSV harus teks tanpa byte NUL.
     *
     * @throws ImportFileException
     */
    public function assertSafe(string $path, string $extension): void
    {
        $head = (string) file_get_contents($path, false, null, 0, 4);
        $isZip = str_starts_with($head, "PK\x03\x04");

        if ($extension === 'xlsx') {
            if (! $isZip) {
                throw new ImportFileException('Berkas bukan XLSX yang valid.');
            }
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new ImportFileException('Berkas bukan XLSX yang valid.');
            }
            $total = 0;
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                $zip->close();
                throw new ImportFileException('Berkas XLSX tidak wajar (terlalu banyak komponen).');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);
            }
            $zip->close();
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new ImportFileException('Isi berkas XLSX terlalu besar setelah diekstrak.');
            }

            return;
        }

        if ($isZip || str_contains((string) file_get_contents($path, false, null, 0, 8192), "\0")) {
            throw new ImportFileException('Berkas bukan CSV teks yang valid.');
        }
    }

    private function detectDelimiter(string $path): string
    {
        $firstLine = strtok((string) file_get_contents($path, false, null, 0, 8192), "\n") ?: '';

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    private function cell(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if ($value === null || is_bool($value)) {
            return null;
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function canonicalHeader(string $header): string
    {
        $header = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header));
        $header = trim(preg_replace('/[^a-z0-9]+/', '_', $header) ?? '', '_');

        return self::HEADER_ALIASES[$header] ?? $header;
    }
}
