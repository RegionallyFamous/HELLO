import { createHash } from 'node:crypto';

export function emailToHash(email) {
  return createHash('md5').update(email.trim().toLowerCase()).digest('hex');
}

export function looksLikeEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
}

export async function resolveGravatar(email, fetchImpl = fetch) {
  const emailHash = emailToHash(email);
  const avatarUrl = `https://www.gravatar.com/avatar/${emailHash}?d=404`;
  const avatarResponse = await fetchImpl(avatarUrl, { method: 'HEAD' });

  if (avatarResponse.status === 404) {
    return {
      emailHash,
      displayName: '',
      avatarUrl: '',
      profileUrl: ''
    };
  }

  let displayName = '';
  let profileUrl = '';

  try {
    const profileResponse = await fetchImpl(`https://www.gravatar.com/${emailHash}.json`);
    if (profileResponse.ok) {
      const profile = await profileResponse.json();
      const entry = profile.entry?.[0];
      displayName = entry?.displayName || entry?.preferredUsername || '';
      profileUrl = entry?.profileUrl || '';
    }
  } catch {
    // Avatar existence is enough for WordPress rendering; profile data is optional.
  }

  return {
    emailHash,
    displayName,
    avatarUrl: `https://www.gravatar.com/avatar/${emailHash}`,
    profileUrl
  };
}
