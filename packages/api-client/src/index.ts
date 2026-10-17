import createClient, { type Middleware } from "openapi-fetch";
import type { components, paths } from "./schema";

export type { components, paths };
export type Schemas = components["schemas"];

/** Error RFC 9457 dari API; `code` stabil dipakai untuk logika UI. */
export type Problem = Schemas["Problem"];

export interface ApiClientOptions {
  /** Asal API, mis. `http://localhost:8000`. Kosong = same-origin (lewat proxy). */
  origin?: string;
  fetch?: typeof fetch;
}

function readCookie(name: string): string | undefined {
  if (typeof document === "undefined") return undefined;
  const hit = document.cookie.split("; ").find((row) => row.startsWith(`${name}=`));
  return hit ? decodeURIComponent(hit.slice(name.length + 1)) : undefined;
}

/** Menyalin cookie XSRF-TOKEN ke header X-XSRF-TOKEN pada request tulis (Sanctum SPA). */
const csrf: Middleware = {
  onRequest({ request }) {
    if (!["GET", "HEAD", "OPTIONS"].includes(request.method)) {
      const token = readCookie("XSRF-TOKEN");
      if (token) request.headers.set("X-XSRF-TOKEN", token);
    }
    return request;
  },
};

export function createApiClient({ origin = "", fetch: fetchImpl }: ApiClientOptions = {}) {
  const client = createClient<paths>({
    baseUrl: `${origin}/api/v1`,
    credentials: "include",
    headers: { Accept: "application/json" },
    ...(fetchImpl ? { fetch: fetchImpl } : {}),
  });
  client.use(csrf);

  /** Panggil sekali sebelum login/aksi tulis pertama agar cookie CSRF terpasang. */
  async function ensureCsrfCookie(): Promise<void> {
    await (fetchImpl ?? fetch)(`${origin}/sanctum/csrf-cookie`, { credentials: "include" });
  }

  return Object.assign(client, { ensureCsrfCookie });
}

export type ApiClient = ReturnType<typeof createApiClient>;

/** Type guard: apakah error API ini memiliki `code` tertentu. */
export function isProblem(error: unknown, code?: string): error is Problem {
  if (typeof error !== "object" || error === null || !("code" in error)) return false;
  return code === undefined || (error as Problem).code === code;
}
