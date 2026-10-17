# DEV SETUP — Menyiapkan Lingkungan Pengembangan (Windows)

Hasil pemeriksaan mesin pengembangan saat ini (17 Okt 2026):

| Prasyarat | Status | Tindakan |
|---|---|---|
| Windows 11 Home build 26200 | ✔ didukung Docker Desktop (backend WSL 2) | — |
| Virtualisasi / hypervisor | ✔ hypervisor aktif | — |
| WSL 2 | ✘ **belum terpasang** | Langkah 1 |
| Docker Desktop | ✘ **belum terpasang** | Langkah 2 |
| RAM | 7,6 GB (pas-pasan) | Batasi memori WSL, Langkah 3 |
| Disk kosong C: | 138 GB | ✔ |
| Node.js | 24.22.0 | ✔ |
| Git (Git Bash) | 2.55 | ✔ |
| PHP XAMPP 8.2 | ada, **tidak dipakai** | Jangan diubah. PHP proyek berjalan di container. |

## Langkah 1 — Pasang WSL 2
Buka **PowerShell sebagai Administrator**:
```powershell
wsl --install --no-distribution
```
Restart Windows bila diminta. Verifikasi:
```powershell
wsl --status
```

## Langkah 2 — Pasang Docker Desktop
Pilih salah satu cara:
- **Unduh resmi:** https://docs.docker.com/desktop/setup/install/windows-install/ → jalankan installer → centang **"Use WSL 2 instead of Hyper-V"**.
- **winget:** `winget install -e --id Docker.DockerDesktop`

Jalankan Docker Desktop sampai status **Engine running**.

> Lisensi: Docker Desktop gratis untuk penggunaan pribadi, pendidikan, dan usaha kecil (< 250 karyawan **dan** < US$10 juta pendapatan tahunan). Di luar itu dibutuhkan langganan berbayar. Periksa ketentuan terbaru di situs Docker.

## Langkah 3 — Batasi memori WSL (disarankan untuk RAM 8 GB)
Buat file `%UserProfile%\.wslconfig`:
```ini
[wsl2]
memory=4GB
processors=4
swap=2GB
```
Lalu jalankan `wsl --shutdown` dan buka ulang Docker Desktop.

## Langkah 4 — Verifikasi Docker
```powershell
docker version
docker compose version
docker run --rm hello-world
```

## Langkah 5 — Jalankan lingkungan proyek
Dari folder repo, di **Git Bash**:
```bash
node scripts/init-env.mjs
docker compose up -d --wait
bash scripts/verify-dev-env.sh
```
Build pertama image PHP mengunduh dan mengompilasi ekstensi, jadi bisa memakan beberapa menit. Semua baris harus `[OK]`. Kirimkan output skrip ini bila ada yang `[GAGAL]`.

## Catatan & pemecahan masalah
- **Port 5432/6379 sudah dipakai:** ubah `FORWARD_DB_PORT` / `FORWARD_REDIS_PORT` di `.env`.
- **Reset database dev:** `docker compose down -v`. Ini menghapus seluruh data dev dan init script dijalankan ulang.
- **Mengubah password di `.env` setelah volume dibuat** tidak mengubah password di PostgreSQL. Lakukan reset seperti di atas.
- **Performa bind mount Windows:** folder `vendor/` dan `node_modules/` di drive C: yang di-mount ke container bisa lambat. Penanganannya diputuskan saat scaffold Laravel di Tahap 1 (named volume untuk `vendor/`, atau repo dipindah ke filesystem WSL).
- Akses DB dari host (mis. DBeaver): `127.0.0.1:5432`, user `sentra_app` atau `sentra_owner`, password dari `.env`.
