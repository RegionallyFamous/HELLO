import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

export function resolvePort({
  primary = '',
  fallback = '',
  defaultPort = '8787',
  name = 'PORT'
} = {}) {
  const raw = [primary, fallback, defaultPort]
    .map((value) => String(value || '').trim())
    .find(Boolean);

  const port = Number.parseInt(raw, 10);

  if (!/^\d+$/.test(raw) || !Number.isFinite(port) || port < 1 || port > 65535) {
    throw new Error(`${name} must be a valid TCP port`);
  }

  return port;
}

export function ensureStateDirectories(paths) {
  for (const path of paths) {
    if (typeof path !== 'string' || !path.trim()) {
      continue;
    }

    mkdirSync(dirname(resolve(path)), { recursive: true });
  }
}
