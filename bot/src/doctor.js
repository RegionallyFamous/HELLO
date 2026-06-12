import { getConfig } from './config.js';
import { MatrixClient } from './matrix-client.js';
import { WordPressClient } from './wordpress-client.js';

const config = getConfig();
const matrix = new MatrixClient({
  homeserverUrl: config.matrixHomeserverUrl,
  accessToken: config.matrixAccessToken,
  storePath: config.matrixSyncStorePath
});
const wordpress = new WordPressClient({
  baseUrl: config.wordpressBaseUrl,
  botSecret: config.wordpressBotSecret
});

const checks = [
  ['WordPress health', () => wordpress.getHealth()],
  ['WordPress room registry', () => wordpress.getRooms()],
  ['Matrix account', () => matrix.getAccount()]
];

let failed = false;

for (const [label, check] of checks) {
  try {
    const result = await check();
    console.log(`OK ${label}`);
    if (Array.isArray(result)) {
      console.log(`   ${result.length} rooms`);
    }
  } catch (error) {
    failed = true;
    console.error(`FAIL ${label}`);
    console.error(`   ${error.message}`);
  }
}

if (failed) {
  process.exitCode = 1;
}
