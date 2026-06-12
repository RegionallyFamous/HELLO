import http from 'node:http';

export class BridgeServer {
  constructor({ token, matrix, registry }) {
    this.token = token;
    this.matrix = matrix;
    this.registry = registry;
  }

  listen(port) {
    const server = http.createServer((request, response) => {
      this.handle(request, response).catch((error) => {
        if (!error.status || error.status >= 500) {
          console.error('[hello] bridge request failed', error);
        }
        this.sendJson(response, error.status || 500, { error: error.message || 'Internal bridge error' });
      });
    });

    server.listen(port);
    return server;
  }

  async handle(request, response) {
    const path = new URL(request.url, 'http://hello.local').pathname;

    if (path === '/v1/health') {
      await this.requireAuth(request);
      const account = await this.matrix.getAccount();
      this.sendJson(response, 200, {
        ok: true,
        matrix_user_id: account.user_id || '',
        known_room_count: this.registry.allRooms().length
      });
      return;
    }

    if (request.method !== 'POST') {
      this.sendJson(response, 405, { error: 'Method not allowed' });
      return;
    }

    await this.requireAuth(request);
    const body = await this.readJson(request);

    if (path === '/v1/rooms') {
      await this.createRoom(response, body);
      return;
    }

    if (path === '/v1/comments') {
      await this.sendComment(response, body);
      return;
    }

    if (path === '/v1/redactions') {
      await this.redact(response, body);
      return;
    }

    this.sendJson(response, 404, { error: 'Not found' });
  }

  async createRoom(response, body) {
    this.requireSite(body.site);
    this.requirePost(body.post);

    const room = await this.matrix.createPublicPostRoom({
      name: String(body.room?.name || `Comments: ${body.post.title}`),
      topic: String(body.room?.topic || body.post.url),
      roomAliasName: String(body.room?.alias_name || `post-${body.post.id}`)
    });

    await this.registry.upsertRoom({
      roomId: room.room_id,
      roomAlias: room.room_alias || '',
      siteUrl: body.site.url,
      siteName: body.site.name || '',
      incomingUrl: body.site.incoming_url,
      webhookSecret: body.site.webhook_secret,
      postId: Number(body.post.id),
      postTitle: body.post.title || '',
      postUrl: body.post.url || ''
    });

    this.sendJson(response, 201, {
      room_id: room.room_id,
      room_alias: room.room_alias || ''
    });
  }

  async sendComment(response, body) {
    this.requireString(body.room_id, 'room_id');
    this.requireString(body.message, 'message');
    this.requireString(body.transaction_id, 'transaction_id');
    this.requireRegisteredRoom(body.room_id, body.site);

    const result = await this.matrix.sendText(body.room_id, body.message, body.transaction_id);
    this.sendJson(response, 200, {
      event_id: result.event_id || ''
    });
  }

  async redact(response, body) {
    this.requireString(body.room_id, 'room_id');
    this.requireString(body.event_id, 'event_id');
    this.requireString(body.transaction_id, 'transaction_id');
    this.requireRegisteredRoom(body.room_id, body.site);

    const result = await this.matrix.redactEvent(
      body.room_id,
      body.event_id,
      String(body.reason || 'Redacted by HELLO'),
      body.transaction_id
    );

    this.sendJson(response, 200, result);
  }

  async requireAuth(request) {
    const expected = `Bearer ${this.token}`;
    if (!this.token || request.headers.authorization !== expected) {
      const error = new Error('Unauthorized');
      error.status = 401;
      throw error;
    }
  }

  requireSite(site) {
    if (!site || typeof site !== 'object') {
      throw new Error('Missing site payload');
    }

    this.requireString(site.url, 'site.url');
    this.requireString(site.incoming_url, 'site.incoming_url');
    this.requireString(site.webhook_secret, 'site.webhook_secret');
  }

  requirePost(post) {
    if (!post || typeof post !== 'object') {
      throw new Error('Missing post payload');
    }

    if (!Number.isFinite(Number(post.id))) {
      throw new Error('post.id is required');
    }

    this.requireString(post.title, 'post.title');
    this.requireString(post.url, 'post.url');
  }

  requireRegisteredRoom(roomId, site = null) {
    const room = this.registry.getRoom(roomId);
    if (!room) {
      const error = new Error('Room is not registered with this bridge');
      error.status = 404;
      throw error;
    }

    if (site?.url && room.siteUrl !== site.url) {
      const error = new Error('Room belongs to a different site');
      error.status = 403;
      throw error;
    }

    return room;
  }

  requireString(value, name) {
    if (typeof value !== 'string' || !value.trim()) {
      throw new Error(`${name} is required`);
    }
  }

  async readJson(request) {
    const chunks = [];
    for await (const chunk of request) {
      chunks.push(chunk);
    }

    if (!chunks.length) {
      return {};
    }

    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  }

  sendJson(response, status, body) {
    response.writeHead(status, {
      'Content-Type': 'application/json'
    });
    response.end(JSON.stringify(body));
  }
}
