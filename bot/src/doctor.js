import { getBridgeConfig } from './config.js';
import { MatrixClient } from './matrix-client.js';

const config = getBridgeConfig();
const matrix = new MatrixClient({
  homeserverUrl: config.matrixHomeserverUrl,
  accessToken: config.matrixAccessToken,
  storePath: config.matrixSyncStorePath
});

const checks = [
  ['Matrix account', () => matrix.getAccount()],
  ['Bridge token configured', async () => ({ ok: Boolean(config.bridgeApiToken) })],
  ['Bridge port configured', async () => ({ port: config.bridgePort })]
];

let failed = false;

for (const [label, check] of checks) {
  try {
    const result = await check();
    console.log(`OK ${label}`);
    console.log(`   ${JSON.stringify(result)}`);
  } catch (error) {
    failed = true;
    console.error(`FAIL ${label}`);
    console.error(`   ${error.message}`);
  }
}

if (failed) {
  process.exitCode = 1;
}
