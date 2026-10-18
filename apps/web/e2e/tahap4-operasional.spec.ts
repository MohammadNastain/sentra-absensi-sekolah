import { expect, test, type Page } from "@playwright/test";
import { revokeAllDevices } from "./helpers/devices";

// Admin sekolah demo dari DemoSeeder (khusus dev/test).
const ADMIN = { email: "admin@demo.test", password: "demo-password-123" };
const SCHOOL = "/schools/demo-smpn-1";

async function loginAsAdmin(page: Page) {
  await page.goto("/login");
  await page.getByLabel("Email").fill(ADMIN.email);
  await page.getByLabel("Password", { exact: true }).fill(ADMIN.password);
  await page.getByRole("button", { name: "Masuk" }).click();
  await expect(page).toHaveURL(new RegExp(`${SCHOOL}$`));
}

function nav(page: Page, name: string) {
  return page.getByRole("navigation", { name: "Navigasi utama" }).getByRole("link", { name, exact: true });
}

test("Tahap 4: halaman Kehadiran — validasi, pencarian, syarat verifikasi, antrean, dan laporan", async ({ page }) => {
  await loginAsAdmin(page);
  await nav(page, "Kehadiran").click();
  await expect(page.getByRole("heading", { name: "Kehadiran", exact: true })).toBeVisible();

  const assist = page.getByRole("region", { name: "Pencatatan mode bantuan" });
  await test.step("Pencarian terlalu pendek ditolak di klien", async () => {
    await assist.getByLabel("Cari siswa").fill("a");
    await assist.getByRole("button", { name: "Cari" }).click();
    await expect(assist.getByText("Ketik minimal 2 karakter")).toBeVisible();
  });

  await test.step("Tidak ada hasil menampilkan pesan kosong", async () => {
    await assist.getByLabel("Cari siswa").fill("zzzzqqqq");
    await assist.getByRole("button", { name: "Cari" }).click();
    await expect(assist.getByText("Tidak ada siswa aktif yang cocok.")).toBeVisible();
  });

  await test.step("Tombol catat butuh verifikasi visual; alasan 'Lainnya' butuh catatan", async () => {
    await assist.getByLabel("Cari siswa").fill("Candra");
    await assist.getByRole("button", { name: "Cari" }).click();
    await assist.getByRole("listitem").filter({ hasText: "Candra Wijaya" }).getByRole("button", { name: "Pilih" }).click();

    const record = assist.getByRole("button", { name: "Catat kehadiran" });
    await expect(record).toBeDisabled();
    await assist.getByRole("checkbox").check();
    await expect(record).toBeEnabled();

    await assist.getByLabel("Alasan").selectOption("other");
    await expect(record).toBeDisabled();
    await assist.getByLabel("Catatan (wajib)").fill("Uji E2E");
    await expect(record).toBeEnabled();

    // Batal kembali ke pencarian tanpa mencatat.
    await assist.getByRole("button", { name: "Batal" }).click();
    await expect(assist.getByLabel("Cari siswa")).toBeVisible();
  });

  await test.step("Pencatatan menghasilkan jawaban server (sukses atau penolakan jelas, bergantung jam jadwal)", async () => {
    await assist.getByLabel("Cari siswa").fill("Candra");
    await assist.getByRole("button", { name: "Cari" }).click();
    await assist.getByRole("listitem").filter({ hasText: "Candra Wijaya" }).getByRole("button", { name: "Pilih" }).click();
    await assist.getByRole("checkbox").check();
    await assist.getByRole("button", { name: "Catat kehadiran" }).click();
    await expect(assist.getByText(/Candra Wijaya: /).or(assist.getByRole("alert"))).toBeVisible();
  });

  await test.step("Antrean tinjauan dan laporan anomali termuat", async () => {
    const queue = page.getByRole("region", { name: "Antrean tinjauan" });
    await expect(queue).toBeVisible();
    await expect(queue.getByText("Memuat antrean…")).toBeHidden();

    const report = page.getByRole("region", { name: "Laporan anomali mode bantuan" });
    await expect(report.getByText(/Total pencatatan bantuan:/)).toBeVisible();
    await expect(report.getByRole("heading", { name: "Per petugas" })).toBeVisible();
    await expect(report.getByRole("heading", { name: "Per alasan" })).toBeVisible();
  });
});

test("Tahap 4: mode bantuan di terminal — login petugas, kredensial salah, cari, dan akhiri sesi", async ({ page, browser }) => {
  await loginAsAdmin(page);

  await revokeAllDevices(page);

  // Daftarkan perangkat → kode pairing tampil sekali.
  await nav(page, "Perangkat").click();
  await page.getByRole("button", { name: "Daftarkan perangkat" }).click();
  await page.getByRole("dialog").getByLabel("Nama perangkat").fill("Gerbang bantuan");
  await page.getByRole("dialog").getByRole("button", { name: "Daftarkan" }).click();
  const code = (await page.getByTestId("pairing-code").innerText()).replace("-", "");
  await page.getByRole("dialog").getByRole("button", { name: "Selesai" }).click();

  const context = await browser.newContext();
  const terminal = await context.newPage();
  await terminal.goto("/terminal");
  await terminal.getByLabel("Kode pairing").fill(code);
  await terminal.getByRole("button", { name: "Pasangkan" }).click();
  await expect(terminal.getByText("Siap memindai")).toBeVisible();

  await terminal.getByRole("button", { name: "Mode bantuan" }).click();
  await expect(terminal.getByRole("heading", { name: "Mode bantuan" })).toBeVisible();

  await test.step("Password salah ditolak dan tetap di layar login", async () => {
    await terminal.getByLabel("Email").fill(ADMIN.email);
    await terminal.getByLabel("Password").fill("salah-salah-salah");
    await terminal.getByRole("button", { name: "Masuk" }).click();
    await expect(terminal.getByRole("alert")).toBeVisible();
    await expect(terminal.getByRole("button", { name: "Masuk" })).toBeVisible();
  });

  await test.step("Login benar membuka form pencarian; syarat verifikasi berlaku", async () => {
    await terminal.getByLabel("Password").fill(ADMIN.password);
    await terminal.getByRole("button", { name: "Masuk" }).click();
    await expect(terminal.getByText(/Petugas: /)).toBeVisible();

    await terminal.getByLabel("Cari siswa").fill("Candra");
    await terminal.getByRole("button", { name: "Cari" }).click();
    await terminal.getByRole("button", { name: /Candra Wijaya/ }).click();
    const record = terminal.getByRole("button", { name: "Catat kehadiran" });
    await expect(record).toBeDisabled();
    await terminal.getByRole("checkbox").check();
    await expect(record).toBeEnabled();
    await terminal.getByRole("button", { name: "Batal" }).click();
  });

  await test.step("Akhiri sesi kembali ke terminal siap memindai", async () => {
    await terminal.getByRole("button", { name: "Akhiri sesi" }).click();
    await expect(terminal.getByText("Siap memindai")).toBeVisible();
  });

  // Bersihkan: cabut perangkat uji agar tidak menumpuk.
  await context.close();
  await nav(page, "Perangkat").click();
  await page.reload();
  const row = page.getByRole("row", { name: /Gerbang bantuan/ }).first();
  await row.getByRole("button", { name: "Cabut" }).click();
  await page.getByRole("dialog").getByLabel("Alasan").fill("Pembersihan uji E2E");
  await page.getByRole("dialog").getByRole("button", { name: "Cabut", exact: true }).click();
  await expect(page.getByRole("dialog")).toBeHidden();
});

test("Tahap 5A: pengaturan aturan alpa dan penahanan/pelepasan hari", async ({ page }) => {
  await loginAsAdmin(page);
  await nav(page, "Kehadiran").click();

  await test.step("Pengaturan: jam batas wajib dan divalidasi, lalu dimatikan kembali", async () => {
    const settings = page.getByRole("region", { name: "Aturan alpa" });
    await expect(settings.getByRole("checkbox")).toBeVisible();
    if (!(await settings.getByRole("checkbox").isChecked())) await settings.getByRole("checkbox").check();
    await settings.getByLabel("Jam batas (waktu lokal sekolah)").fill("05:00");
    await settings.getByRole("button", { name: "Simpan" }).click();
    await expect(settings.getByText(/Jam batas harus setelah batas masuk/).first()).toBeVisible();

    await settings.getByLabel("Jam batas (waktu lokal sekolah)").fill("23:00");
    await settings.getByRole("button", { name: "Simpan" }).click();
    await expect(settings.getByText("Pengaturan disimpan.")).toBeVisible();

    await settings.getByRole("checkbox").uncheck();
    await settings.getByRole("button", { name: "Simpan" }).click();
    await expect(settings.getByText("Pengaturan disimpan.")).toBeVisible();
  });

  await test.step("Verifikasi: tandai hari bergangguan lalu lepas penahanan", async () => {
    const panel = page.getByRole("region", { name: "Verifikasi alpa" });
    await panel.getByLabel("Tanggal").fill("2026-01-05");
    await expect(panel.getByText("Tidak ada alpa sementara pada tanggal ini.")).toBeVisible();

    await panel.getByRole("button", { name: "Tandai hari bergangguan" }).click();
    const submit = panel.getByRole("button", { name: "Tandai bergangguan" });
    await expect(submit).toBeDisabled();
    await panel.getByLabel(/Alasan/).fill("Uji e2e: terminal gerbang mati");
    await submit.click();
    await expect(panel.getByText("Hari ditandai bergangguan; aturan alpa ditahan.")).toBeVisible();
    await expect(panel.getByText(/Aturan alpa ditahan untuk hari ini/)).toBeVisible();
    await expect(panel.getByText("Ditandai bergangguan oleh petugas.")).toBeVisible();

    await panel.getByRole("button", { name: "Lepas penahanan" }).click();
    await panel.getByLabel(/Alasan/).fill("Uji e2e: sudah diperiksa normal");
    await panel.getByRole("button", { name: "Lepas penahanan" }).last().click();
    await expect(panel.getByText("Penahanan dilepas; aturan alpa dijalankan.")).toBeVisible();
    await expect(panel.getByText(/Aturan alpa ditahan untuk hari ini/)).toBeHidden();
  });
});
