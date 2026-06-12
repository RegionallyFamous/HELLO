import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

export function loadEnvFile(path = '.env') {
  const fullPath = resolve(path);

  if (!existsSync(fullPath)) {
    return;
  }

  const lines = readFileSync(fullPath, 'utf8').split(/\r?\n/);
  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) {
      continue;
    }

    const [key, ...valueParts] = trimmed.split('=');
    if (!process.env[key]) {
      process.env[key] = valueParts.join('=').replace(/^["']|["']$/g, '');
    }
  }
}

export function getConfig() {
  loadEnvFile();

  const config = {
    matrixHomeserverUrl: process.env.MATRIX_HOMESERVER_URL || 'https://matrix.org',
    matrixAccessToken: process.env.MATRIX_ACCESS_TOKEN || '',
    matrixUserId: process.env.MATRIX_USER_ID || '',
    wordpressBaseUrl: (process.env.WORDPRESS_BASE_URL || '').replace(/\/$/, ''),
    wordpressBotSecret: process.env.WORDPRESS_BOT_SECRET || '',
    identityStorePath: process.env.IDENTITY_STORE_PATH || '.data/identities.json',
    matrixSyncStorePath: process.env.MATRIX_SYNC_STORE_PATH || '.data/matrix-sync.json',
    roomRefreshMs: Number.parseInt(process.env.ROOM_REFRESH_MS || '60000', 10)
  };

  const missing = Object.entries({
    MATRIX_ACCESS_TOKEN: config.matrixAccessToken,
    MATRIX_USER_ID: config.matrixUserId,
    WORDPRESS_BASE_URL: config.wordpressBaseUrl,
    WORDPRESS_BOT_SECRET: config.wordpressBotSecret
  }).filter(([, value]) => !value);

  if (missing.length) {
    throw new Error(`Missing required environment variables: ${missing.map(([key]) => key).join(', ')}`);
  }

  if (!Number.isFinite(config.roomRefreshMs) || config.roomRefreshMs < 5000) {
    throw new Error('ROOM_REFRESH_MS must be at least 5000');
  }

  return config;
}
