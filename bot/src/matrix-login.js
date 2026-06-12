import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

export function createLoginPayload(user, password) {
  return {
    type: 'm.login.password',
    identifier: {
      type: 'm.id.user',
      user
    },
    password,
    initial_device_display_name: 'HELLO Railway Bridge'
  };
}

export async function loginWithPassword({
  homeserverUrl,
  user,
  password,
  fetchImpl = fetch
}) {
  const homeserver = homeserverUrl.replace(/\/$/, '');
  const flowsResponse = await fetchImpl(`${homeserver}/_matrix/client/v3/login`);
  const flows = await readJsonResponse(flowsResponse);

  if (!flowsResponse.ok) {
    throw new Error(`Matrix login discovery failed with ${flowsResponse.status}: ${JSON.stringify(flows)}`);
  }

  const supportsPassword = Array.isArray(flows.flows)
    && flows.flows.some((flow) => flow?.type === 'm.login.password');

  if (!supportsPassword) {
    throw new Error('This homeserver does not advertise m.login.password login.');
  }

  const response = await fetchImpl(`${homeserver}/_matrix/client/v3/login`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(createLoginPayload(user, password))
  });
  const body = await readJsonResponse(response);

  if (!response.ok) {
    throw new Error(`Matrix login failed with ${response.status}: ${JSON.stringify(body)}`);
  }

  if (!body.access_token || !body.user_id) {
    throw new Error('Matrix login response did not include access_token and user_id.');
  }

  return {
    accessToken: String(body.access_token),
    userId: String(body.user_id),
    deviceId: body.device_id ? String(body.device_id) : ''
  };
}

export function setRailwayVariables({
  homeserverUrl,
  accessToken,
  userId,
  service = 'bridge',
  runner = spawnSync
}) {
  runRailway(['variable', 'set', `MATRIX_HOMESERVER_URL=${homeserverUrl}`, '--service', service, '--skip-deploys'], runner);
  runRailway(['variable', 'set', `MATRIX_USER_ID=${userId}`, '--service', service, '--skip-deploys'], runner);
  runRailway(['variable', 'set', 'MATRIX_ACCESS_TOKEN', '--stdin', '--service', service, '--skip-deploys'], runner, accessToken);
}

function runRailway(args, runner, input = '') {
  const result = runner('railway', args, {
    input,
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe']
  });

  if (result.status !== 0) {
    throw new Error(`railway ${args.join(' ')} failed: ${result.stderr || result.stdout}`);
  }
}

async function readJsonResponse(response) {
  const text = await response.text();
  try {
    return text ? JSON.parse(text) : {};
  } catch {
    return { raw: text };
  }
}

function parseArgs(argv) {
  const args = {
    homeserverUrl: process.env.MATRIX_HOMESERVER_URL || 'https://matrix.org',
    user: process.env.MATRIX_LOGIN_USER || '',
    password: process.env.MATRIX_PASSWORD || '',
    passwordStdin: false,
    railway: false,
    service: 'bridge'
  };

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];

    if (arg === '--homeserver') {
      args.homeserverUrl = requireValue(argv, ++index, arg);
    } else if (arg === '--user') {
      args.user = requireValue(argv, ++index, arg);
    } else if (arg === '--password-stdin') {
      args.passwordStdin = true;
    } else if (arg === '--railway') {
      args.railway = true;
    } else if (arg === '--service') {
      args.service = requireValue(argv, ++index, arg);
    } else if (arg === '--help' || arg === '-h') {
      args.help = true;
    } else {
      throw new Error(`Unknown argument: ${arg}`);
    }
  }

  return args;
}

function requireValue(argv, index, name) {
  if (!argv[index]) {
    throw new Error(`${name} requires a value.`);
  }

  return argv[index];
}

function usage() {
  return `Usage:
  npm --prefix bot run matrix:login -- --user hello-bot --railway --password-stdin

Options:
  --homeserver URL      Matrix homeserver. Defaults to MATRIX_HOMESERVER_URL or https://matrix.org.
  --user USER           Bot login name or full Matrix ID. Can also use MATRIX_LOGIN_USER.
  --password-stdin      Read the bot password from stdin. Can also use MATRIX_PASSWORD.
  --railway             Store MATRIX_HOMESERVER_URL, MATRIX_USER_ID, and MATRIX_ACCESS_TOKEN in Railway.
  --service NAME        Railway service name. Defaults to bridge.
`;
}

async function main() {
  const args = parseArgs(process.argv.slice(2));

  if (args.help) {
    process.stdout.write(usage());
    return;
  }

  if (args.passwordStdin) {
    args.password = readFileSync(0, 'utf8').trim();
  }

  if (!args.user) {
    throw new Error('Missing --user or MATRIX_LOGIN_USER.');
  }

  if (!args.password) {
    throw new Error('Missing MATRIX_PASSWORD or --password-stdin.');
  }

  const login = await loginWithPassword({
    homeserverUrl: args.homeserverUrl,
    user: args.user,
    password: args.password
  });

  if (args.railway) {
    setRailwayVariables({
      homeserverUrl: args.homeserverUrl,
      accessToken: login.accessToken,
      userId: login.userId,
      service: args.service
    });

    process.stdout.write(`Set Matrix Railway variables for ${login.userId} on service ${args.service}.\n`);
    return;
  }

  process.stdout.write(`MATRIX_HOMESERVER_URL=${args.homeserverUrl}\n`);
  process.stdout.write(`MATRIX_USER_ID=${login.userId}\n`);
  process.stdout.write(`MATRIX_ACCESS_TOKEN=${login.accessToken}\n`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
  });
}
