import type { Schemas } from "@sentra/api-client";

/**
 * Mengambil semua halaman sebuah daftar (untuk isian pilihan seperti tahun ajaran atau guru, yang jumlahnya kecil).
 * `fetchPage` mengembalikan halaman, atau undefined bila tidak ada; error API sebaiknya dilempar oleh pemanggil.
 */
export async function fetchAll<T>(
  fetchPage: (page: number) => Promise<{ data: T[]; meta: Schemas["PageMeta"] } | undefined>,
): Promise<T[]> {
  const items: T[] = [];
  for (let page = 1; ; page += 1) {
    const result = await fetchPage(page);
    if (!result) break;
    items.push(...result.data);
    if (page >= result.meta.last_page) break;
  }
  return items;
}
