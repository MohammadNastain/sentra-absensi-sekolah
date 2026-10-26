# Deploy ke satu VPS (Docker)

Panduan memasang Sentra Sekolah di satu VPS (target: Rumahweb, 2 GB RAM, Ubuntu 24.04). Semua layanan berjalan di Docker: edge HTTPS + API (FrankenPHP), web (Next.js), worker antrean, scheduler, PostgreSQL 18, Redis. Berkas ada di `infra/prod/` dan `infra/docker/prod/`. Nilai `sekolah.contoh.my.id` dan `GANTI` adalah placeholder.

## 0. Sebelum mulai

- Domain sudah dibeli dan record **A** mengarah ke IP VPS (HTTPS otomatis butuh ini; kamera terminal tidak jalan tanpa HTTPS).
- Repositori GitHub: image dibangun di GitHub Actions, bukan di VPS.
- Layanan surel (SMTP) untuk undangan dan reset sandi. Tanpa transport nyata, API menolak undangan di produksi.

## 1. Bangun image (di GitHub)

1. Tab **Actions** → workflow **Publish images** → **Run workflow** (centang *demo* bila ini lingkungan demo).
2. Setelah selesai, buka profil/organisasi → **Packages** → `sentra-api` dan `sentra-web` → *Package settings* → ubah visibilitas ke **Public**, atau siapkan token baca (`read:packages`) untuk `docker login ghcr.io` di VPS. (Image tidak berisi rahasia; semua konfigurasi dari `.env`.)

Build pertama bisa gagal bila tag image PHP/FrankenPHP berubah; baca log dan sesuaikan `infra/docker/prod/api.Dockerfile`.

## 2. Siapkan server (sekali)

```bash
# sebagai root
apt update && apt -y upgrade
adduser deploy && usermod -aG sudo deploy        # lalu salin SSH key ke /home/deploy/.ssh/authorized_keys
# /etc/ssh/sshd_config: PermitRootLogin no, PasswordAuthentication no  → systemctl restart ssh
ufw default deny incoming && ufw allow 22,80,443/tcp && ufw allow 443/udp && ufw enable
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
curl -fsSL https://get.docker.com | sh && usermod -aG docker deploy
apt -y install rsync unattended-upgrades
```

Login ulang sebagai `deploy`. Docker menulis aturan iptables sendiri; karena compose hanya memetakan 80/443 pada `api`, PostgreSQL dan Redis tidak terbuka ke internet.

## 3. Pasang konfigurasi

```bash
sudo mkdir -p /opt/sentra && sudo chown deploy: /opt/sentra
git clone https://github.com/MOHAMMADNASTAIN/sentra-sekolah.git /opt/sentra   # atau salin folder infra/prod saja
cd /opt/sentra/infra/prod
cp .env.example .env && chmod 600 .env && nano .env
```

Isi semua `GANTI`. Kata sandi acak: `openssl rand -base64 36 | tr -d '/+='`. `APP_KEY`:

```bash
docker run --rm ghcr.io/<owner>/sentra-api:latest php artisan key:generate --show
```

Simpan `.env` dan `APP_KEY` di pengelola kata sandi; kehilangan `APP_KEY` membuat data terenkripsi tak terbaca.

## 4. Jalankan

```bash
docker compose pull
docker compose up -d postgres redis        # tunggu sehat (±30 detik pada start pertama)
docker compose run --rm migrate            # migrasi dengan sentra_owner
docker compose up -d
docker compose ps
curl -I https://sekolah.contoh.my.id/up    # 200
```

Sertifikat Let's Encrypt diambil otomatis pada permintaan pertama (port 80/443 harus terjangkau dan DNS sudah benar).

## 5. Akun pertama

```bash
docker compose run --rm api php artisan platform:create-admin pemilik@contoh.my.id --name="Pemilik"
```

Perintah meminta sandi tanpa menampilkannya. Login di `/login`; autentikasi dua langkah wajib disiapkan pada login pertama. Sekolah dan admin sekolah dibuat dari area `/platform`.

Mode demo (opsional, hanya lingkungan demo): set `DEMO_ENABLED=true` di `.env`, bangun image web dengan opsi *demo*, lalu `docker compose up -d` dan `docker compose run --rm api php artisan demo:reset` (lihat `MODE_DEMO.md`).

## 6. Cadangan

```bash
sudo mkdir -p /var/backups/sentra && sudo chown deploy: /var/backups/sentra
crontab -e
# 15 2 * * * cd /opt/sentra/infra/prod && BACKUP_REMOTE=pengguna@host-lain:/folder/ sh backup.sh >> /var/log/sentra-backup.log 2>&1
```

Salinan di luar VPS wajib (`BACKUP_REMOTE`, atau tarik dengan `rsync` dari komputer/server lain). Uji restore ke database kosong sebelum pilot dan catat tanggalnya (prosedur di `BACKUP_RESTORE.md`).

## 7. Pembaruan

```bash
cd /opt/sentra/infra/prod
git pull                                   # bila berkas infra berubah
docker compose pull
docker compose run --rm migrate
docker compose up -d
```

Untuk rilis yang bisa dikembalikan, bangun image dari tag `v*` dan set `IMAGE_TAG=vX.Y.Z` di `.env`; rollback = kembalikan `IMAGE_TAG` lalu `docker compose up -d` (migrasi yang sudah jalan tidak otomatis di-rollback).

## 8. Operasional singkat

| Perlu | Perintah |
|---|---|
| Log | `docker compose logs -f api web queue` |
| Memori | `docker stats --no-stream` |
| Tinker/artisan | `docker compose run --rm api php artisan <perintah>` |
| Restart satu layanan | `docker compose restart queue` |

Pantau penggunaan memori minggu pertama; bila `queue`/`api` sering di-OOM-kill atau swap terus terpakai, naikkan paket VPS. Pemantauan dan alert lengkap: `MONITORING.md`. Daftar periksa sebelum pilot: `CHECKLIST_PRODUKSI.md`.

## Catatan keputusan

- Satu image API untuk edge, worker, scheduler, dan migrasi (perbedaan hanya `command`).
- Cache konfigurasi/rute dibuat saat container start, dari environment container (bukan dari image).
- Berkas privat (lampiran LMS, ekspor) ada di volume `api-storage`; ikut dicadangkan bila perlu dengan `docker run --rm -v sentra_api-storage:/s -v $PWD:/b alpine tar czf /b/storage.tgz -C /s .`.
- Belum ada pemantauan eksternal, PITR, dan lokasi hosting final (D-23 tetap terbuka).
