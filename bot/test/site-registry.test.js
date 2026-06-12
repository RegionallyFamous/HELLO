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

test('SiteRegistry persists site registrations', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'hello-registry-'));
  const path = join(dir, 'sites.json');

  try {
    const registry = new SiteRegistry(path);
    await registry.load();
    await registry.upsertSite({
      siteId: 'site-1',
      siteUrl: 'https://example.com',
      incomingUrl: 'https://example.com/wp-json/hello/v1/incoming',
      webhookSecret: 'wp-secret',
      bridgeToken: 'site-token'
    });

    const restored = new SiteRegistry(path);
    await restored.load();

    assert.equal(restored.getSite('site-1').bridgeToken, 'site-token');
    assert.equal(restored.getSiteByUrl('https://example.com').siteId, 'site-1');
    assert.equal(restored.getSiteForPayload({ site_id: 'site-1' }).siteUrl, 'https://example.com');
    assert.equal(restored.allSites().length, 1);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
});
