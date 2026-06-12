import test from 'node:test';
import assert from 'node:assert/strict';
import { emailToHash, looksLikeEmail, resolveGravatar } from '../src/gravatar.js';

test('emailToHash normalizes email before hashing', () => {
  assert.equal(emailToHash(' Alice@example.COM '), 'c160f8cc69a4f0bf2b0362752353d060');
});

test('looksLikeEmail accepts ordinary addresses only', () => {
  assert.equal(looksLikeEmail('reader@example.com'), true);
  assert.equal(looksLikeEmail('not an email'), false);
});

test('resolveGravatar returns hash only when avatar is missing', async () => {
  const result = await resolveGravatar('missing@example.com', async () => ({ status: 404 }));

  assert.equal(result.emailHash, '55c617e8bcc292d4947950e908739586');
  assert.equal(result.displayName, '');
  assert.equal(result.avatarUrl, '');
});

test('resolveGravatar includes public profile data when available', async () => {
  const result = await resolveGravatar('person@example.com', async (url) => {
    if (String(url).endsWith('.json')) {
      return {
        ok: true,
        json: async () => ({
          entry: [{
            displayName: 'Person Example',
            profileUrl: 'https://gravatar.com/person'
          }]
        })
      };
    }

    return { status: 200 };
  });

  assert.equal(result.displayName, 'Person Example');
  assert.equal(result.profileUrl, 'https://gravatar.com/person');
  assert.match(result.avatarUrl, /^https:\/\/www\.gravatar\.com\/avatar\//);
});
