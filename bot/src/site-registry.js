import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

export class SiteRegistry {
  constructor(path) {
    this.path = resolve(path);
    this.rooms = new Map();
  }

  async load() {
    try {
      const raw = await readFile(this.path, 'utf8');
      const parsed = JSON.parse(raw);
      this.rooms = new Map(Object.entries(parsed.rooms || {}));
    } catch (error) {
      if (error.code !== 'ENOENT') {
        throw error;
      }
    }
  }

  getRoom(roomId) {
    return this.rooms.get(roomId) || null;
  }

  allRooms() {
    return Array.from(this.rooms.values());
  }

  async upsertRoom(room) {
    this.rooms.set(room.roomId, {
      ...room,
      updatedAt: new Date().toISOString()
    });
    await this.save();
  }

  async save() {
    await mkdir(dirname(this.path), { recursive: true });
    await writeFile(this.path, JSON.stringify({
      rooms: Object.fromEntries(this.rooms)
    }, null, 2));
  }
}
