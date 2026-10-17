/**
 * Membatasi `next` hanya ke path internal (mencegah open redirect): harus diawali satu "/" dan
 * bukan "//host" atau "/\host".
 */
export function safeNext(value: string | null | undefined): string | null {
  if (!value) return null;
  if (!value.startsWith("/") || value.startsWith("//") || value.startsWith("/\\")) return null;
  return value;
}
