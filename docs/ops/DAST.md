# DAST (OWASP ZAP) — hasil dan cara mengulang

## Hasil 19 Okt 2026 (dev lokal)

- Alat: OWASP ZAP 2.17.0 (`ghcr.io/zaproxy/zaproxy:stable`), mode `zap-api-scan.py` memakai `apps/api/openapi.yaml` (145 URL terimpor).
- Target: API dev (`http://localhost:8000`), **tanpa login** (hanya yang dapat dijangkau tamu: umumnya respons 401/403/422).
- Hasil: **tidak ada temuan Medium/High**.

| Risiko | Temuan | Penilaian |
|---|---|---|
| Low | Cookie `XSRF-TOKEN` tanpa HttpOnly (5 respons) | **Disengaja.** Pola Sanctum SPA: JavaScript harus membaca token CSRF untuk mengirim header `X-XSRF-TOKEN`. Cookie sesi sendiri HttpOnly. Tidak diubah. |
| Info | Respons 4xx (400 kali) | Wajar untuk pemindaian tanpa kredensial. |
| Info | Authentication Request Identified, Session Management Response Identified, Non-Storable Content | Informasional; `Non-Storable Content` sesuai `Cache-Control: no-store` pada data pribadi. |

## Batasan hasil ini (jangan dibaca sebagai "aman")

1. **Tidak terautentikasi.** Rute yang dilindungi hanya dipindai sampai penolakan 401/403; isi logika bisnis, IDOR lintas sekolah, dan otorisasi per peran **tidak** teruji oleh ZAP. Sisi itu dijaga tes matriks rute (T09), tes RLS, dan tes lintas tenant di suite PHP.
2. Pemindaian dibatasi waktu dan memori (laptop dev); fase aktif tidak selesai penuh pada satu putaran. Ulangi di staging dengan sumber daya cukup.
3. Hanya API. Dashboard Next.js (CSP, header) diperiksa lewat build produksi lokal (lihat PR Tahap 6 #16), bukan oleh ZAP.
4. Bukan pengganti pentest eksternal.

## Mengulang

Berat (image ±3,7 GB, JVM beberapa ratus MB). Jalankan di mesin dengan RAM bebas ≥ 3 GB, atau di CI/staging:

```
docker run --rm --memory=1g -e JAVA_OPTS=-Xmx512m -v "$PWD/zap:/zap/wrk:rw" ghcr.io/zaproxy/zaproxy:stable \
  zap-api-scan.py -t /zap/wrk/openapi.yaml -f openapi -O http://host.docker.internal:8000 \
  -r zap-report.html -J zap-report.json -I -m 1
```

Perbaikan lanjutan yang disarankan: pemindaian terautentikasi di staging memakai sesi admin/wali kelas dari sekolah uji (bukan data nyata), dan tambahkan sebagai job CI terjadwal mingguan.
