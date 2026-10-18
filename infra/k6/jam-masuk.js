// Uji beban jam masuk (docs/TESTING_STRATEGY.md §8). Target PRD 11.1: p95 scan ≤ 2 dtk, dashboard ≤ 3 dtk,
// error < 0,1%. Data dari `php artisan loadtest:prepare` (infra/k6/README.md).
//
// Per terminal: 1 scan / 2 dtk (RATE_SECONDS) atas kartu berurutan; ~10% sisanya memindai ulang kartu yang
// sudah dipakai (duplikat). Dashboard: 1 admin per sekolah memoll /attendance/today tiap 10 dtk, seperti UI.
import http from "k6/http";
import { check, sleep } from "k6";
import { Counter, Rate } from "k6/metrics";
import { SharedArray } from "k6/data";

const BASE = __ENV.BASE_URL || "http://localhost:8000";
const ORIGIN = __ENV.ORIGIN || "http://localhost:3000"; // harus termasuk domain stateful Sanctum
const DURATION = __ENV.DURATION || "30m";
const RATE_SECONDS = Number(__ENV.RATE_SECONDS || 2);

const data = new SharedArray("data", () => JSON.parse(open(__ENV.DATA || "/work/loadtest.json")).schools);

const terminals = [];
data.forEach((school, s) => school.devices.forEach((_, d) => terminals.push({ s, d })));

const unexpected = new Rate("scan_unexpected"); // bukan 200 / kode bisnis tak dikenal
const accepted = new Counter("scan_accepted");
const duplicates = new Counter("scan_duplicate");

export const options = {
  noCookiesReset: true, // sesi dashboard dipertahankan antar-iterasi (default k6 mengosongkan cookie tiap iterasi)
  scenarios: {
    scan: {
      executor: "constant-vus",
      vus: terminals.length,
      duration: DURATION,
      exec: "scan",
    },
    dashboard: {
      executor: "constant-vus",
      vus: data.length,
      duration: DURATION,
      exec: "dashboard",
    },
  },
  thresholds: {
    "http_req_duration{endpoint:scan}": ["p(95)<2000"],
    "http_req_duration{endpoint:today}": ["p(95)<3000"],
    scan_unexpected: ["rate<0.001"],
    "http_req_failed{endpoint:today}": ["rate<0.001"],
  },
};

const terminalHeaders = (token) => ({
  Accept: "application/json",
  "Content-Type": "application/json",
  "X-Sentra-Terminal": "1",
  Cookie: `sentra_device=${token}`,
});

function uuid() {
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
  });
}

export function scan() {
  // VU 1..N → terminal; tiap terminal memindai potongan kartunya sendiri agar kartu pertama kali = accepted.
  const t = terminals[(__VU - 1) % terminals.length];
  const school = data[t.s];
  const perDevice = Math.floor(school.cards.length / school.devices.length);
  const base = t.d * perDevice;
  const i = __ITER < perDevice ? __ITER : Math.floor(Math.random() * perDevice);
  const repeat = __ITER >= perDevice || Math.random() < 0.1;
  const card = school.cards[base + (repeat ? Math.floor(Math.random() * Math.max(1, Math.min(__ITER, perDevice))) : i)];

  const res = http.post(`${BASE}/api/v1/terminal/scans`, JSON.stringify({ value: card, idempotency_key: uuid() }), {
    headers: terminalHeaders(school.devices[t.d]),
    tags: { endpoint: "scan" },
  });
  const body = res.status === 200 ? res.json("data") : null;
  const ok = check(res, { "scan 200": (r) => r.status === 200 }) && body !== null && ["accepted", "duplicate"].includes(body.result);
  unexpected.add(!ok);
  if (body && body.result === "accepted") accepted.add(1);
  if (body && body.result === "duplicate") duplicates.add(1);
  sleep(RATE_SECONDS);
}

export function dashboard() {
  const school = data[(__VU - 1) % data.length];
  if (__ITER === 0) login(school);
  const res = http.get(`${BASE}/api/v1/schools/${school.code}/attendance/today`, {
    headers: { Accept: "application/json", Origin: ORIGIN, Referer: `${ORIGIN}/` },
    tags: { endpoint: "today" },
  });
  if (res.status !== 200) console.error(`today ${res.status} ${res.body}`);
  check(res, { "today 200": (r) => r.status === 200 });
  sleep(10);
}

function login(school) {
  const csrf = http.get(`${BASE}/sanctum/csrf-cookie`, { headers: { Origin: ORIGIN, Referer: `${ORIGIN}/` } });
  const xsrf = decodeURIComponent(csrf.cookies["XSRF-TOKEN"] ? csrf.cookies["XSRF-TOKEN"][0].value : "");
  const res = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ email: school.admin.email, password: school.admin.password }), {
    headers: { Accept: "application/json", "Content-Type": "application/json", Origin: ORIGIN, Referer: `${ORIGIN}/`, "X-XSRF-TOKEN": xsrf },
    tags: { endpoint: "login" },
  });
  if (res.status >= 300) console.error(`login ${res.status} ${res.body}`);
  check(res, { "login ok": (r) => r.status < 300 });
}

// Invarian "tanpa accepted ganda" diperiksa SQL setelah uji: infra/k6/verify.sql.
