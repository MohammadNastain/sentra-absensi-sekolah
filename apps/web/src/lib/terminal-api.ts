import type { Schemas } from "@sentra/api-client";

/**
 * Klien tipis untuk /terminal/*. Terminal tidak memakai sesi/CSRF; ia memakai cookie perangkat HttpOnly yang
 * dipasang server saat pairing, dan header X-Sentra-Terminal yang memaksa preflight CORS.
 */
export type TerminalFailure =
  | { kind: "unpaired" } // belum/tidak lagi dipasangkan
  | { kind: "revoked" }
  | { kind: "unavailable"; message: string } // sekolah ditangguhkan, dll.
  | { kind: "invalid"; message: string } // 422 (mis. kode pairing salah)
  | { kind: "network" };

export type TerminalResult<T> = { ok: true; data: T } | { ok: false; failure: TerminalFailure };

export type TerminalConfig = Schemas["TerminalConfigEnvelope"]["data"];
export type TerminalScan = Schemas["TerminalScan"];
export type TerminalRecent = Schemas["TerminalRecentEnvelope"]["data"];

async function call<T>(method: "GET" | "POST", path: string, body?: unknown): Promise<TerminalResult<T>> {
  let response: Response;
  try {
    response = await fetch(`/api/v1/terminal${path}`, {
      method,
      credentials: "include",
      cache: "no-store",
      headers: {
        Accept: "application/json",
        "X-Sentra-Terminal": "1",
        ...(body === undefined ? {} : { "Content-Type": "application/json" }),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    return { ok: false, failure: { kind: "network" } };
  }

  if (response.ok) return { ok: true, data: ((await response.json()) as { data: T }).data };

  let problem: { code?: string; title?: string; detail?: string; errors?: Record<string, string[]> } = {};
  try {
    problem = await response.json();
  } catch {
    // Badan bukan JSON (mis. 502 dari proxy): perlakukan sebagai gangguan jaringan.
    if (response.status >= 500) return { ok: false, failure: { kind: "network" } };
  }
  const message = Object.values(problem.errors ?? {})[0]?.[0] ?? problem.detail ?? problem.title ?? "Permintaan ditolak.";

  if (response.status === 401 || problem.code === "DEVICE_UNPAIRED") return { ok: false, failure: { kind: "unpaired" } };
  if (problem.code === "DEVICE_REVOKED") return { ok: false, failure: { kind: "revoked" } };
  if (response.status === 422) return { ok: false, failure: { kind: "invalid", message } };
  if (response.status >= 500 || response.status === 429) return { ok: false, failure: { kind: "network" } };
  return { ok: false, failure: { kind: "unavailable", message } };
}

export const terminalApi = {
  pair: (code: string) => call<{ school: { name: string } }>("POST", "/pair", { code }),
  config: () => call<TerminalConfig>("GET", "/config"),
  heartbeat: () => call<{ server_time: string }>("POST", "/heartbeat", {}),
  scan: (value: string, idempotencyKey: string) => call<TerminalScan>("POST", "/scans", { value, idempotency_key: idempotencyKey }),
  recent: () => call<TerminalRecent>("GET", "/recent"),
};
