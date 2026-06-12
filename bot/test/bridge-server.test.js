import test from 'node:test';
import assert from 'node:assert/strict';
import { BridgeServer } from '../src/bridge-server.js';

test('BridgeServer rejects unauthorized requests', async () => {
  const server = new BridgeServer({
    token: 'secret',
    matrix: {},
    registry: { allRooms: () => [] }
  }).listen(0);

  try {
    const { port } = server.address();
    const response = await fetch(`http://127.0.0.1:${port}/v1/health`, {
      method: 'POST'
    });

    assert.equal(response.status, 401);
  } finally {
    server.close();
  }
});

test('BridgeServer exposes an unauthenticated liveness endpoint', async () => {
  const server = new BridgeServer({
    token: 'secret',
    matrix: {},
    registry: { allRooms: () => [] }
  }).listen(0);

  try {
    const { port } = server.address();
    const response = await fetch(`http://127.0.0.1:${port}/v1/live`);
    const body = await response.json();

    assert.equal(response.status, 200);
    assert.equal(body.ok, true);
  } finally {
    server.close();
  }
});

test('BridgeServer creates a Matrix room and persists mapping', async () => {
  const saved = [];
  const server = new BridgeServer({
    token: 'secret',
    matrix: {
      createPublicPostRoom: async ({ name, topic, roomAliasName }) => {
        assert.equal(name, 'Comments: Test');
        assert.equal(topic, 'https://example.com/test');
        assert.equal(roomAliasName, 'post-site-10');
        return {
          room_id: '!room:matrix.org',
          room_alias: '#post-site-10:matrix.org'
        };
      }
    },
    registry: {
      upsertRoom: async (room) => saved.push(room),
      allRooms: () => saved
    }
  }).listen(0);

  try {
    const { port } = server.address();
    const response = await fetch(`http://127.0.0.1:${port}/v1/rooms`, {
      method: 'POST',
      headers: {
        Authorization: 'Bearer secret',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        site: {
          url: 'https://example.com',
          name: 'Example',
          incoming_url: 'https://example.com/wp-json/hello/v1/incoming',
          webhook_secret: 'wp-secret'
        },
        post: {
          id: 10,
          title: 'Test',
          url: 'https://example.com/test'
        },
        room: {
          alias_name: 'post-site-10',
          name: 'Comments: Test',
          topic: 'https://example.com/test'
        }
      })
    });
    const body = await response.json();

    assert.equal(response.status, 201);
    assert.equal(body.room_id, '!room:matrix.org');
    assert.equal(saved[0].incomingUrl, 'https://example.com/wp-json/hello/v1/incoming');
  } finally {
    server.close();
  }
});

test('BridgeServer refuses comments for unknown rooms', async () => {
  const server = new BridgeServer({
    token: 'secret',
    matrix: {
      sendText: async () => {
        throw new Error('sendText should not run');
      }
    },
    registry: {
      getRoom: () => null,
      allRooms: () => []
    }
  }).listen(0);

  try {
    const { port } = server.address();
    const response = await fetch(`http://127.0.0.1:${port}/v1/comments`, {
      method: 'POST',
      headers: {
        Authorization: 'Bearer secret',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        site: { url: 'https://example.com' },
        room_id: '!missing:matrix.org',
        message: 'Hello',
        transaction_id: 'comment-1'
      })
    });

    assert.equal(response.status, 404);
  } finally {
    server.close();
  }
});
