#!/usr/bin/env node
// Membuat .env dari .env.example dan mengganti setiap nilai __GENERATE__ dengan secret acak.
// Tidak pernah menimpa .env yang sudah ada.
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const examplePath = join(root, '.env.example');
const envPath = join(root, '.env');

if (existsSync(envPath)) {
  console.error('.env sudah ada — tidak ditimpa. Hapus manual jika memang ingin membuat ulang.');
  process.exit(1);
}

let generated = 0;
const content = readFileSync(examplePath, 'utf8').replace(/^([A-Z0-9_]+)=__GENERATE__$/gm, (_, key) => {
  generated += 1;
  return `${key}=${randomBytes(24).toString('hex')}`;
});

writeFileSync(envPath, content, { encoding: 'utf8', mode: 0o600 });
console.log(`.env dibuat dengan ${generated} secret acak.`);
