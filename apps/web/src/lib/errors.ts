import { isProblem, type Problem } from "@sentra/api-client";

export interface FormFailure {
  /** Pesan umum untuk ditampilkan di atas formulir. */
  message: string;
  /** Pesan per field (kunci = nama field API). */
  fields: Record<string, string>;
}

/** Pesan ramah untuk kode error stabil; kode lain memakai `title` dari server. */
const MESSAGES: Record<string, string> = {
  TOO_MANY_REQUESTS: "Terlalu banyak percobaan. Tunggu beberapa saat lalu coba lagi.",
  CSRF_TOKEN_MISMATCH: "Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.",
  UNAUTHENTICATED: "Sesi Anda berakhir. Silakan masuk kembali.",
  EMAIL_NOT_CONFIGURED: "Layanan email belum dikonfigurasi di lingkungan ini.",
  INVITATION_UNAVAILABLE: "Undangan ini tidak berlaku lagi. Minta admin sekolah mengirim undangan baru.",
  LOGIN_REQUIRED: "Email ini sudah terdaftar. Masuk terlebih dahulu untuk menerima undangan.",
  INVITATION_EMAIL_MISMATCH: "Undangan ini ditujukan untuk email lain. Masuk dengan email yang diundang.",
};

/** Mengubah error openapi-fetch (problem+json atau kegagalan jaringan) menjadi pesan untuk formulir. */
export function toFormFailure(error: unknown): FormFailure {
  if (isProblem(error)) {
    const problem: Problem = error;
    const fields: Record<string, string> = {};
    for (const [name, messages] of Object.entries(problem.errors ?? {})) {
      const first = messages[0];
      if (first) fields[name] = first;
    }
    const firstField = Object.values(fields)[0];
    return {
      message: MESSAGES[problem.code] ?? firstField ?? problem.title,
      fields,
    };
  }
  return { message: "Tidak dapat terhubung ke server. Periksa koneksi lalu coba lagi.", fields: {} };
}

/** Membungkus pemanggilan fetch yang bisa melempar (jaringan putus) agar selalu menjadi FormFailure. */
export async function attempt<T extends { error?: unknown }>(
  call: () => Promise<T>,
): Promise<{ ok: true; result: T } | { ok: false; failure: FormFailure }> {
  try {
    const result = await call();
    if (result.error !== undefined) return { ok: false, failure: toFormFailure(result.error) };
    return { ok: true, result };
  } catch (e) {
    return { ok: false, failure: toFormFailure(e) };
  }
}
