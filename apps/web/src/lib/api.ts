import { createApiClient } from "@sentra/api-client";

/** Klien tunggal; same-origin lewat rewrites di next.config.ts. */
export const api = createApiClient();
