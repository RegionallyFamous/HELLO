import test from 'node:test';
import assert from 'node:assert/strict';
import { MatrixClient } from '../src/matrix-client.js';

test('MatrixClient sends account request with bearer token', async () => {
  const client = new MatrixClient({
    homeserverUrl: 'https://matrix.example.com/',
    accessToken: 'token',
    storePath: '.data/test-sync.json',
    fetchImpl: async (url, options) => {
      assert.equal(url, 'https://matrix.example.com/_matrix/client/v3/account/whoami');
      assert.equal(options.headers.Authorization, 'Bearer token');
      return {
        ok: true,
        text: async () => JSON.stringify({ user_id: '@bot:example.com' })
      };
    }
  });

  assert.deepEqual(await client.getAccount(), { user_id: '@bot:example.com' });
});

test('MatrixClient emits all joined room timeline events', async () => {
  const client = new MatrixClient({
    homeserverUrl: 'https://matrix.example.com',
    accessToken: 'token',
    storePath: '.data/test-sync.json'
  });
  const seen = [];

  await client.processJoinedRoomEvents({
    rooms: {
      join: {
        '!room:example.com': {
          timeline: {
            events: [
              { type: 'm.room.message', event_id: '$1' },
              { type: 'm.room.member', event_id: '$2' }
            ]
          }
        }
      }
    }
  }, async (roomId, event) => {
    seen.push([roomId, event.event_id]);
  });

  assert.deepEqual(seen, [
    ['!room:example.com', '$1'],
    ['!room:example.com', '$2']
  ]);
});
