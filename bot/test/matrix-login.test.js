import test from 'node:test';
import assert from 'node:assert/strict';
import { createLoginPayload, loginWithPassword, setRailwayVariables } from '../src/matrix-login.js';

test('createLoginPayload uses password login with a Matrix user identifier', () => {
  assert.deepEqual(createLoginPayload('hello-bot', 'secret'), {
    type: 'm.login.password',
    identifier: {
      type: 'm.id.user',
      user: 'hello-bot'
    },
    password: 'secret',
    initial_device_display_name: 'HELLO Railway Bridge'
  });
});

test('loginWithPassword discovers password flow and returns credentials', async () => {
  const requests = [];
  const login = await loginWithPassword({
    homeserverUrl: 'https://matrix.example',
    user: 'hello-bot',
    password: 'secret',
    fetchImpl: async (url, options = {}) => {
      requests.push({ url, options });

      if (!options.method) {
        return jsonResponse(200, {
          flows: [{ type: 'm.login.password' }]
        });
      }

      assert.equal(options.method, 'POST');
      assert.deepEqual(JSON.parse(options.body), createLoginPayload('hello-bot', 'secret'));

      return jsonResponse(200, {
        access_token: 'token',
        user_id: '@hello-bot:matrix.example',
        device_id: 'DEVICE'
      });
    }
  });

  assert.equal(login.accessToken, 'token');
  assert.equal(login.userId, '@hello-bot:matrix.example');
  assert.equal(login.deviceId, 'DEVICE');
  assert.equal(requests[0].url, 'https://matrix.example/_matrix/client/v3/login');
});

test('setRailwayVariables stores the token via stdin', () => {
  const calls = [];

  setRailwayVariables({
    homeserverUrl: 'https://matrix.example',
    accessToken: 'secret-token',
    userId: '@hello-bot:matrix.example',
    service: 'bridge',
    runner: (command, args, options) => {
      calls.push({ command, args, input: options.input });
      return { status: 0, stdout: '', stderr: '' };
    }
  });

  assert.equal(calls.length, 3);
  assert.equal(calls[0].args.includes('MATRIX_HOMESERVER_URL=https://matrix.example'), true);
  assert.equal(calls[1].args.includes('MATRIX_USER_ID=@hello-bot:matrix.example'), true);
  assert.deepEqual(calls[2].args.slice(0, 4), ['variable', 'set', 'MATRIX_ACCESS_TOKEN', '--stdin']);
  assert.equal(calls[2].input, 'secret-token');
});

function jsonResponse(status, body) {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: async () => JSON.stringify(body)
  };
}
