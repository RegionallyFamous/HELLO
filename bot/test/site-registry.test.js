import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { SiteRegistry } from '../src/site-registry.js';

test('SiteRegistry persists room mappings', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'hello-registry-'));
  const path = join(dir, 'sites.json');

  try {
    const registry = new SiteRegistry(path);
    await registry.load();
    await registry.upsertRoom({
      roomId: '!room:matrix.org',
      roomAlias: '#post:matrix.org',
      siteUrl: 'https://example.com',
      incomingUrl: 'https://example.com/wp-json/hello/v1/incoming',
      webhookSecret: 'wp-secret',
      postId: 123
    });

    const restored = new SiteRegistry(path);
    await restored.load();

    assert.equal(restored.getRoom('!room:matrix.org').postId, 123);
    assert.equal(restored.allRooms().length, 1);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
});
