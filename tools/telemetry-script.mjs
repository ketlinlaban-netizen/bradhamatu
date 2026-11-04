#!/usr/bin/env node
/**
 * Generates a per-router RouterOS telemetry installer without opening the console.
 *
 * The token is read from stdin so it never lands in shell history or in argv.
 *
 *   node tools/telemetry-script.mjs --router-id <uuid> --interval 5 \
 *     --out ./bm-telemetry.rsc --preflight-verified <<< '<token>'
 */
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync } from 'node:fs';
import { writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { createInterface } from 'node:readline/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';

const TELEMETRY_PATH = '/api/v1/internal/mikrotik/telemetry';
const projectRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const esbuildPath = join(projectRoot, 'node_modules/.bin/esbuild');

// This Node build has no TypeScript runtime, so transpile the shipped generator
// instead of duplicating its RouterOS source.
async function loadGenerator() {
  const target = join(mkdtempSync(join(tmpdir(), 'bradha-generator-')), 'mikrotikTelemetryScript.mjs');
  execFileSync(esbuildPath, [
    join(projectRoot, 'src/services/mikrotikTelemetryScript.ts'),
    '--format=esm',
    `--outfile=${target}`,
  ], { stdio: 'inherit' });
  return import(pathToFileURL(target).href);
}

function parseArgs(argv) {
  const values = {};
  for (let index = 0; index < argv.length; index += 1) {
    if (!argv[index].startsWith('--')) throw new Error(`Unexpected argument: ${argv[index]}`);
    const name = argv[index].slice(2);
    if (name === 'preflight-verified') {
      values.preflightVerified = true;
      continue;
    }
    values[name] = argv[index + 1];
    index += 1;
  }
  return values;
}

async function readToken() {
  if (!process.stdin.isTTY) {
    return readFileSync(0, 'utf8').trim();
  }
  const prompt = createInterface({ input: process.stdin, output: process.stdout });
  const token = (await prompt.question('Paste the 64-character telemetry token, then press Enter: ')).trim();
  prompt.close();
  return token;
}

const args = parseArgs(process.argv.slice(2));

if (args.help || !args['router-id']) {
  console.error(`Usage: node tools/telemetry-script.mjs --router-id <uuid> --interval <5|10|60|120|300> \
--out <path> --preflight-verified [--host <app-host>]

The telemetry token is read from stdin and is never printed.
--preflight-verified asserts check-certificate=yes already succeeded on this router.`);
  process.exit(args.help ? 0 : 1);
}

if (!args.preflightVerified) {
  throw new Error(
    'Run the HTTPS preflight on the target router first and confirm code: 200:\n'
    + '  /tool fetch url="https://<app-host>/api/health" check-certificate=yes output=none\n'
    + 'Then add --preflight-verified.',
  );
}

const endpoint = `https://${String(args.host ?? 'onlineconsole.communitygroup.co.ke').replace(/^https:\/\//, '')}${TELEMETRY_PATH}`;
const intervalSeconds = Number(args.interval ?? 60);
const scriptName = `bm-telemetry-${args['router-id'].slice(0, 8)}`;
const token = await readToken();

const generator = await loadGenerator();
const output = generator.generateMikroTikTelemetryScript({
  routerId: args['router-id'],
  token,
  intervalSeconds,
  endpoint,
  serverCertificateVerified: true,
});

const outputPath = args.out ?? `${scriptName}.rsc`;
await writeFile(outputPath, output, { mode: 0o600 });

console.error(`generator v${generator.MIKROTIK_TELEMETRY_GENERATOR_VERSION}`);
console.error(`script    : ${scriptName}`);
console.error(`scheduler : ${scriptName}-schedule`);
console.error(`interval  : ${intervalSeconds}s`);
console.error(`endpoint  : ${endpoint}`);
console.error(`wrote     : ${outputPath} (${Buffer.byteLength(output)} bytes) - contains the token, delete it after import`);
