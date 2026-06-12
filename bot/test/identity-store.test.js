import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { IdentityStore } from '../src/identity-store.js';

test('IdentityStore persists identities, DM room mappings, onboarding, and moderation', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'beeper-comments-'));
  const path = join(dir, 'identities.json');

  try {
    const store = new IdentityStore(path);
    await store.load();
    await store.set('@alice:matrix.org', {
      displayName: 'Alice',
      emailHash: 'abc',
      avatarUrl: '',
      profileUrl: ''
    });
    await store.setIdentityRoom('@alice:matrix.org', '!dm:matrix.org');
    await store.markOnboarded('@alice:matrix.org');
    await store.markModerated('@bob:matrix.org');

    const restored = new IdentityStore(path);
    await restored.load();

    assert.equal(restored.get('@alice:matrix.org').displayName, 'Alice');
    assert.equal(restored.getUserForIdentityRoom('!dm:matrix.org'), '@alice:matrix.org');
    assert.equal(restored.getIdentityRoomForUser('@alice:matrix.org'), '!dm:matrix.org');
    assert.equal(restored.hasOnboarded('@alice:matrix.org'), true);
    assert.equal(restored.isModerated('@bob:matrix.org'), true);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
});
