import { expect, test, type Page } from "@playwright/test";

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

async function revokeAllDevices(page: Page) {
  await nav(page, "Perangkat").click();
  await expect(page.getByRole("heading", { name: "Perangkat terminal" })).toBeVisible();
  await expect(page.getByText("Memuat…")).toBeHidden();
  for (let guard = 0; guard < 5; guard++) {
    const cabut = page.getByRole("button", { name: "Cabut", exact: true }).first();
    if ((await cabut.count()) === 0) return;
    const revoked = page.getByText("Dicabut", { exact: true });
    const before = await revoked.count();
    await cabut.click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("Alasan").fill("Pembersihan uji E2E");
    await dialog.getByRole("button", { name: "Cabut", exact: true }).click();
    await expect(dialog).toBeHidden();
    // Tunggu daftar disegarkan sebelum mengklik lagi; baris basi masih punya tombol Cabut.
    await expect(revoked).toHaveCount(before + 1);
  }
}

test("Tahap 3B/3C/3D: daftarkan terminal, pairing, scan QR, lalu cabut", async ({ page, browser }) => {
  await loginAsAdmin(page);
  await revokeAllDevices(page);

  // Terbitkan kartu QR dan tangkap token dari respons (hanya ada di sana).
  await nav(page, "Siswa").click();
  await page.getByRole("row", { name: /Candra Wijaya/ }).getByRole("button", { name: "Kartu QR" }).click();
  const issued = page.waitForResponse((r) => r.url().includes("/credentials/qr") && r.request().method() === "POST");
  await page.getByRole("dialog").getByRole("button", { name: /Terbitkan (ulang )?kartu/ }).click();
  const token: string = (await (await issued).json()).data.token;
  await page.getByRole("dialog").getByRole("button", { name: "Selesai" }).click();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).toBeHidden();

  // Daftarkan perangkat → kode pairing tampil sekali.
  await nav(page, "Perangkat").click();
  await page.getByRole("button", { name: "Daftarkan perangkat" }).click();
  await page.getByRole("dialog").getByLabel("Nama perangkat").fill("Gerbang uji");
  await page.getByRole("dialog").getByRole("button", { name: "Daftarkan" }).click();
  const code = (await page.getByTestId("pairing-code").innerText()).replace("-", "");
  expect(code).toMatch(/^[A-HJKMNP-Z2-9]{8}$/);
  await page.getByRole("dialog").getByRole("button", { name: "Selesai" }).click();
  await expect(page.getByRole("row", { name: /Gerbang uji.*Menunggu pairing/ })).toHaveCount(1);

  // Terminal di konteks browser terpisah (tanpa sesi admin).
  const context = await browser.newContext();
  const terminal = await context.newPage();
  await terminal.goto("/terminal");
  await expect(terminal.getByRole("heading", { name: "Pasangkan terminal" })).toBeVisible();

  await terminal.getByLabel("Kode pairing").fill("SALAH123");
  await terminal.getByRole("button", { name: "Pasangkan" }).click();
  await expect(terminal.getByText("Kode tidak valid")).toBeVisible();

  await terminal.getByLabel("Kode pairing").fill(code);
  await terminal.getByRole("button", { name: "Pasangkan" }).click();
  await expect(terminal.getByText("Siap memindai")).toBeVisible();
  await expect(terminal.getByText("Gerbang uji")).toBeVisible();

  await test.step("Kartu tidak dikenal ditolak dengan pesan jelas", async () => {
    await terminal.getByLabel("Kode kartu (uji manual)").fill("SA1.AAAAAAAAAAAAAAAAAAAAAAAAAA");
    await terminal.getByRole("button", { name: "Kirim" }).click();
    await expect(terminal.getByTestId("scan-panel")).toContainText("Kartu tidak dikenali");
    await expect(terminal.getByText("Siap memindai")).toBeVisible({ timeout: 8000 });
  });

  await test.step("Kartu valid menghasilkan jawaban dari server (bergantung jam jadwal)", async () => {
    await terminal.getByLabel("Kode kartu (uji manual)").fill(token);
    await terminal.getByRole("button", { name: "Kirim" }).click();
    const panel = terminal.getByTestId("scan-panel");
    await expect(panel).not.toContainText("Memproses");
    await expect(panel).not.toContainText("Kartu tidak dikenali");
    await expect(panel.getByTestId("scan-code")).toBeVisible();
  });

  await test.step("Dashboard menampilkan ringkasan absensi hari ini", async () => {
    await nav(page, "Ringkasan").click();
    const section = page.getByRole("region", { name: "Absensi hari ini" });
    await expect(section).toBeVisible();
    await expect(section.getByText("Belum tercatat")).toBeVisible();
    await nav(page, "Perangkat").click();
    await expect(page.getByRole("heading", { name: "Perangkat terminal" })).toBeVisible();
  });

  await test.step("Admin melihat perangkat online, lalu mencabutnya; terminal langsung ditolak", async () => {
    await page.reload();
    await expect(page.getByRole("row", { name: /Gerbang uji.*Online/ })).toHaveCount(1);

    await page.getByRole("row", { name: /Gerbang uji.*Online/ }).getByRole("button", { name: "Cabut" }).click();
    await page.getByRole("dialog").getByLabel("Alasan").fill("Selesai uji");
    await page.getByRole("dialog").getByRole("button", { name: "Cabut", exact: true }).click();
    await expect(page.getByRole("row", { name: /Gerbang uji.*Online/ })).toHaveCount(0);
    await expect(page.getByRole("row", { name: /Gerbang uji.*Dicabut/ }).first()).toBeVisible();

    await terminal.reload();
    await expect(terminal.getByRole("heading", { name: "Perangkat dicabut" })).toBeVisible();
  });

  await context.close();
});
