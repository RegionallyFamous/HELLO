import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { ensureStateDirectories, resolvePort } from '../src/runtime.js';

test('resolvePort prefers explicit bridge port', () => {
  assert.equal(resolvePort({
    primary: '8787',
    fallback: '3000',
    name: 'BRIDGE_PORT or PORT'
  }), 8787);
});

test('resolvePort falls back to platform PORT', () => {
  assert.equal(resolvePort({
    primary: '',
    fallback: '3000',
    name: 'BRIDGE_PORT or PORT'
  }), 3000);
});

test('resolvePort rejects invalid values', () => {
  assert.throws(() => resolvePort({
    primary: 'abc',
    fallback: '3000',
    name: 'BRIDGE_PORT or PORT'
  }), /valid TCP port/);
});

test('ensureStateDirectories creates parent directories for configured state files', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'hello-runtime-'));
  const statePath = join(dir, 'data', 'nested', 'sites.json');

  try {
    ensureStateDirectories([statePath]);
    assert.equal(existsSync(join(dir, 'data', 'nested')), true);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
});
