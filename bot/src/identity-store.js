import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

export class IdentityStore {
  constructor(path) {
    this.path = resolve(path);
    this.identities = new Map();
    this.onboarded = new Set();
    this.identityRooms = new Map();
    this.userIdentityRooms = new Map();
    this.moderatedUsers = new Set();
  }

  async load() {
    try {
      const raw = await readFile(this.path, 'utf8');
      const parsed = JSON.parse(raw);
      this.identities = new Map(Object.entries(parsed.identities || {}));
      this.onboarded = new Set(parsed.onboarded || []);
      this.identityRooms = new Map(Object.entries(parsed.identityRooms || {}));
      this.userIdentityRooms = new Map(Object.entries(parsed.userIdentityRooms || {}));
      this.moderatedUsers = new Set(parsed.moderatedUsers || []);
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

  getUserForIdentityRoom(roomId) {
    return this.identityRooms.get(roomId) || null;
  }

  getIdentityRoomForUser(userId) {
    return this.userIdentityRooms.get(userId) || null;
  }

  async setIdentityRoom(userId, roomId) {
    this.identityRooms.set(roomId, userId);
    this.userIdentityRooms.set(userId, roomId);
    await this.save();
  }

  isModerated(userId) {
    return this.moderatedUsers.has(userId);
  }

  async markModerated(userId) {
    this.moderatedUsers.add(userId);
    await this.save();
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
        onboarded: Array.from(this.onboarded),
        identityRooms: Object.fromEntries(this.identityRooms),
        userIdentityRooms: Object.fromEntries(this.userIdentityRooms),
        moderatedUsers: Array.from(this.moderatedUsers)
      }, null, 2)
    );
  }
}
