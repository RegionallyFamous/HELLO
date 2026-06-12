import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

export class SiteRegistry {
  constructor(path) {
    this.path = resolve(path);
    this.rooms = new Map();
    this.sites = new Map();
  }

  async load() {
    try {
      const raw = await readFile(this.path, 'utf8');
      const parsed = JSON.parse(raw);
      this.rooms = new Map(Object.entries(parsed.rooms || {}));
      this.sites = new Map(Object.entries(parsed.sites || {}));
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

  getSite(siteId) {
    return this.sites.get(siteId) || null;
  }

  getSiteByUrl(siteUrl) {
    for (const site of this.sites.values()) {
      if (site.siteUrl === siteUrl) {
        return site;
      }
    }

    return null;
  }

  getSiteForPayload(site = {}) {
    if (site.site_id) {
      const matchedById = this.getSite(site.site_id);
      if (matchedById) {
        return matchedById;
      }
    }

    if (site.url) {
      return this.getSiteByUrl(site.url);
    }

    return null;
  }

  allSites() {
    return Array.from(this.sites.values());
  }

  async upsertSite(site) {
    const existing = this.getSite(site.siteId) || this.getSiteByUrl(site.siteUrl) || {};
    const siteId = site.siteId || existing.siteId;

    if (!siteId) {
      throw new Error('siteId is required');
    }

    this.sites.set(siteId, {
      ...existing,
      ...site,
      siteId,
      bridgeToken: site.bridgeToken || existing.bridgeToken || '',
      createdAt: existing.createdAt || new Date().toISOString(),
      updatedAt: new Date().toISOString()
    });
    await this.save();

    return this.getSite(siteId);
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
      sites: Object.fromEntries(this.sites),
      rooms: Object.fromEntries(this.rooms)
    }, null, 2));
  }
}
