import test from 'node:test';
import assert from 'node:assert/strict';
import { WordPressClient } from '../src/wordpress-client.js';

test('WordPressClient attaches bot secret and reads room registry', async () => {
  let requestBody = null;
  const client = new WordPressClient({
    baseUrl: 'https://example.com/',
    botSecret: 'shared',
    fetchImpl: async (url, options) => {
      assert.equal(url, 'https://example.com/wp-json/hello/v1/rooms');
      requestBody = JSON.parse(options.body);
      return {
        ok: true,
        json: async () => ({ rooms: [{ room_id: '!room:matrix.org' }] })
      };
    }
  });

  const rooms = await client.getRooms();

  assert.deepEqual(requestBody, { bot_secret: 'shared' });
  assert.deepEqual(rooms, [{ room_id: '!room:matrix.org' }]);
});

test('WordPressClient treats unknown incoming rooms as ignored', async () => {
  const client = new WordPressClient({
    baseUrl: 'https://example.com',
    botSecret: 'shared',
    fetchImpl: async () => ({
      ok: false,
      status: 404,
      json: async () => ({ message: 'unknown room' })
    })
  });

  const result = await client.postIncomingMessage({ room_id: '!unknown:matrix.org' });

  assert.equal(result.ignored, true);
  assert.equal(result.status, 404);
});

test('WordPressClient can post to a stored incoming callback URL', async () => {
  let calledUrl = '';
  const client = new WordPressClient({
    incomingUrl: 'https://example.com/custom/incoming',
    botSecret: 'shared',
    fetchImpl: async (url) => {
      calledUrl = url;
      return {
        ok: true,
        json: async () => ({ comment_id: 1 })
      };
    }
  });

  await client.postIncomingMessage({ room_id: '!room:matrix.org' });

  assert.equal(calledUrl, 'https://example.com/custom/incoming');
});
