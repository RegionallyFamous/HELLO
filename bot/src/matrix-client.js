import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

const delay = (ms) => new Promise((resolveDelay) => setTimeout(resolveDelay, ms));

export class MatrixClient {
  constructor({ homeserverUrl, accessToken, storePath, fetchImpl = fetch }) {
    this.homeserverUrl = homeserverUrl.replace(/\/$/, '');
    this.accessToken = accessToken;
    this.storePath = resolve(storePath);
    this.fetch = fetchImpl;
    this.nextBatch = '';
  }

  async load() {
    try {
      const raw = await readFile(this.storePath, 'utf8');
      const parsed = JSON.parse(raw);
      this.nextBatch = parsed.nextBatch || '';
    } catch (error) {
      if (error.code !== 'ENOENT') {
        throw error;
      }
    }
  }

  async start(onMessage) {
    await this.load();

    while (true) {
      const hadExistingCursor = Boolean(this.nextBatch);

      try {
        const sync = await this.sync();
        await this.joinInvitedRooms(sync);

        if (hadExistingCursor) {
          await this.processJoinedRoomMessages(sync, onMessage);
        }

        this.nextBatch = sync.next_batch || this.nextBatch;
        await this.save();
      } catch (error) {
        console.error('[beeper-comments] Matrix sync failed', error);
        await delay(5000);
      }
    }
  }

  async sendText(roomId, body) {
    const transactionId = `beeper-${Date.now()}-${Math.random().toString(36).slice(2)}`;

    return this.request(
      'PUT',
      `/_matrix/client/v3/rooms/${encodeURIComponent(roomId)}/send/m.room.message/${encodeURIComponent(transactionId)}`,
      {
        msgtype: 'm.text',
        body
      }
    );
  }

  async createDirectRoom(userId) {
    const response = await this.request('POST', '/_matrix/client/v3/createRoom', {
      invite: [userId],
      is_direct: true,
      preset: 'trusted_private_chat'
    });

    if (!response.room_id) {
      throw new Error('Matrix createRoom response did not include room_id');
    }

    return response.room_id;
  }

  async getUserProfile(userId) {
    return this.request('GET', `/_matrix/client/v3/profile/${encodeURIComponent(userId)}`);
  }

  async sync() {
    const params = new URLSearchParams({
      timeout: '30000'
    });

    if (this.nextBatch) {
      params.set('since', this.nextBatch);
    }

    return this.request('GET', `/_matrix/client/v3/sync?${params.toString()}`);
  }

  async joinInvitedRooms(sync) {
    const invites = sync.rooms?.invite || {};

    for (const roomId of Object.keys(invites)) {
      try {
        await this.request('POST', `/_matrix/client/v3/rooms/${encodeURIComponent(roomId)}/join`, {});
        console.log(`[beeper-comments] joined invited room ${roomId}`);
      } catch (error) {
        console.error(`[beeper-comments] failed to join invited room ${roomId}`, error);
      }
    }
  }

  async processJoinedRoomMessages(sync, onMessage) {
    const joinedRooms = sync.rooms?.join || {};

    for (const [roomId, room] of Object.entries(joinedRooms)) {
      const events = room.timeline?.events || [];

      for (const event of events) {
        if (event.type === 'm.room.message') {
          await onMessage(roomId, event);
        }
      }
    }
  }

  async request(method, path, body) {
    const response = await this.fetch(`${this.homeserverUrl}${path}`, {
      method,
      headers: {
        Authorization: `Bearer ${this.accessToken}`,
        'Content-Type': 'application/json'
      },
      body: body ? JSON.stringify(body) : undefined
    });

    const text = await response.text();
    const json = text ? JSON.parse(text) : {};

    if (!response.ok) {
      throw new Error(`Matrix API failed with ${response.status}: ${JSON.stringify(json)}`);
    }

    return json;
  }

  async save() {
    await mkdir(dirname(this.storePath), { recursive: true });
    await writeFile(this.storePath, JSON.stringify({ nextBatch: this.nextBatch }, null, 2));
  }
}
