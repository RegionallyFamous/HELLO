import { mkdirSync } from 'node:fs';
import { getConfig } from './config.js';
import { IdentityStore } from './identity-store.js';
import { looksLikeEmail, resolveGravatar } from './gravatar.js';
import { MatrixClient } from './matrix-client.js';
import { WordPressClient } from './wordpress-client.js';

const config = getConfig();
mkdirSync('.data', { recursive: true });
const client = new MatrixClient({
  homeserverUrl: config.matrixHomeserverUrl,
  accessToken: config.matrixAccessToken,
  storePath: config.matrixSyncStorePath
});
const identityStore = new IdentityStore(config.identityStorePath);
const wordpress = new WordPressClient({
  baseUrl: config.wordpressBaseUrl,
  botSecret: config.wordpressBotSecret
});

await identityStore.load();
console.log('[beeper-comments] Matrix bot started');
await client.start(async (roomId, event) => {
  try {
    await handleRoomMessage(roomId, event);
  } catch (error) {
    console.error('[beeper-comments] message handler failed', error);
  }
});

async function handleRoomMessage(roomId, event) {
  if (!event?.content || event.sender === config.matrixUserId) {
    return;
  }

  if (event.content.msgtype !== 'm.text' || !event.content.body) {
    return;
  }

  const message = event.content.body.trim();
  if (!message) {
    return;
  }

  if (await maybeHandleIdentityReply(roomId, event.sender, message)) {
    return;
  }

  const identity = await identityForSender(event.sender);
  const displayName = identity.displayName || await matrixDisplayName(event.sender) || event.sender;

  const result = await wordpress.postIncomingMessage({
    room_id: roomId,
    matrix_user_id: event.sender,
    message,
    event_id: event.event_id,
    author_name: displayName,
    author_url: identity.profileUrl || '',
    author_email_hash: identity.emailHash || '',
    author_avatar_url: identity.avatarUrl || ''
  });

  if (result.ignored) {
    return;
  }

  if (!identity.emailHash && !identity.skipped && !identityStore.hasOnboarded(event.sender)) {
    await sendOnboardingMessage(event.sender);
    await identityStore.markOnboarded(event.sender);
  }
}

async function maybeHandleIdentityReply(roomId, userId, message) {
  const normalized = message.replace(/^email\s+/i, '').trim();

  if (/^skip$/i.test(normalized)) {
    await identityStore.skip(userId);
    await client.sendText(roomId, 'Got it. Future messages will use your Matrix display name.');
    return true;
  }

  if (!looksLikeEmail(normalized)) {
    return false;
  }

  const profile = await resolveGravatar(normalized);
  const fallbackName = await matrixDisplayName(userId);

  await identityStore.set(userId, {
    ...profile,
    displayName: profile.displayName || fallbackName || userId
  });

  await client.sendText(roomId, profile.avatarUrl
    ? 'Thanks. Your Gravatar profile will be used for future WordPress comments.'
    : 'Thanks. I saved your email hash, but did not find a public Gravatar profile yet.');

  return true;
}

async function identityForSender(userId) {
  const stored = identityStore.get(userId);
  if (stored) {
    return stored;
  }

  return {
    displayName: await matrixDisplayName(userId),
    emailHash: '',
    avatarUrl: '',
    profileUrl: '',
    skipped: false
  };
}

async function matrixDisplayName(userId) {
  try {
    const profile = await client.getUserProfile(userId);
    return profile?.displayname || '';
  } catch {
    return '';
  }
}

async function sendOnboardingMessage(userId) {
  const roomId = await client.createRoom({
    invite: [userId],
    is_direct: true,
    preset: 'trusted_private_chat'
  });

  await client.sendText(
    roomId,
    'Hi! Your Matrix messages can appear as WordPress comments. Reply with EMAIL you@example.com to use your Gravatar profile, or SKIP to keep using your Matrix display name.'
  );
}
