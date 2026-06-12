import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

export class IdentityStore {
  constructor(path) {
    this.path = resolve(path);
    this.identities = new Map();
    this.onboarded = new Set();
  }

  async load() {
    try {
      const raw = await readFile(this.path, 'utf8');
      const parsed = JSON.parse(raw);
      this.identities = new Map(Object.entries(parsed.identities || {}));
      this.onboarded = new Set(parsed.onboarded || []);
    } catch (error) {
      if (error.code !== 'ENOENT') {
        throw error;
      }
    }
  }

  get(userId) {
    return this.identities.get(userId) || null;
  }

  hasOnboarded(userId) {
    return this.onboarded.has(userId);
  }

  async markOnboarded(userId) {
    this.onboarded.add(userId);
    await this.save();
  }

  async set(userId, identity) {
    this.identities.set(userId, {
      ...identity,
      updatedAt: new Date().toISOString()
    });
    await this.save();
  }

  async skip(userId) {
    await this.set(userId, {
      skipped: true,
      displayName: '',
      emailHash: '',
      avatarUrl: '',
      profileUrl: ''
    });
  }

  async save() {
    await mkdir(dirname(this.path), { recursive: true });
    await writeFile(
      this.path,
      JSON.stringify({
        identities: Object.fromEntries(this.identities),
        onboarded: Array.from(this.onboarded)
      }, null, 2)
    );
  }
}
