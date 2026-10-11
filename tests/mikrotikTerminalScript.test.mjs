import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

const source = await readFile(new URL("../src/services/mikrotikTerminalScript.ts", import.meta.url), "utf8");
const compiled = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2020 },
}).outputText;
const generator = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString("base64")}`);
const { generateMikroTikTerminalScript } = generator;
const actionSource = await readFile(new URL("../src/services/mikrotikScriptActions.ts", import.meta.url), "utf8");
const compiledActions = ts.transpileModule(actionSource, {
  compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2020 },
}).outputText;
const actions = await import(`data:text/javascript;base64,${Buffer.from(compiledActions).toString("base64")}`);

function baseInput(overrides = {}) {
  return {
    environment: "local",
    transport: "binary",
    routerOsVersion: "7.16.2",
    binary: {
      host: "192.168.88.1",
      source: "192.168.88.10/32",
      username: "nocBinary",
      password: "Strong$pass\\word123",
      port: "8729",
      useTls: true,
      certificateName: "router-cert",
      certificateReady: true,
    },
    rest: {
      host: "router.example.internal",
      source: "10.20.0.0/24",
      username: "nocRest",
      password: "DifferentStrong$pass456",
      port: "443",
      certificateName: "router-https",
      certificateReady: true,
    },
    ...overrides,
  };
}

test("generates least-privilege API-SSL setup with escaped password and conflict guards", () => {
  const result = generateMikroTikTerminalScript(baseInput());
  assert.deepEqual(result.errors, []);
  assert.match(result.script, /\/user group add name="nocBinary-ro" policy=read,api/);
  assert.match(result.script, /name="nocBinary" group="nocBinary-ro" address="192\.168\.88\.10\/32"/);
  assert.match(result.script, /password="Strong\\\$pass\\\\word123"/);
  assert.match(result.script, /\/ip service set \[\/ip service find where name="api-ssl"\] disabled=no address="192\.168\.88\.10\/32" certificate="router-cert"/);
  assert.match(result.script, /already exists; inspect it and do not overwrite it/);
  assert.match(result.script, /different source restriction/);
  assert.doesNotMatch(result.script, /0\.0\.0\.0\/0/);
});

test("REST requires RouterOS 7.1+, trusted-certificate attestation, and configures HTTPS only", () => {
  const input = baseInput({ environment: "cpanel", transport: "rest" });
  const result = generateMikroTikTerminalScript(input);
  assert.deepEqual(result.errors, []);
  assert.match(result.script, /policy=read,rest-api/);
  assert.match(result.script, /name="www-ssl"/);
  assert.match(result.script, /certificate="router-https"/);
  assert.doesNotMatch(result.script, /name="www"/);
  assert.match(result.script, /Environment: cPanel Production · REST over HTTPS/);

  input.routerOsVersion = "7.0.0";
  assert.ok(generateMikroTikTerminalScript(input).errors.some((error) => error.includes("7.1 or newer")));
  input.routerOsVersion = "7.16.2";
  input.rest.certificateReady = false;
  assert.ok(generateMikroTikTerminalScript(input).errors.some((error) => error.includes("certificate chain")));
});

test("Both keeps separate accounts and sources and rejects credential-name collisions", () => {
  const input = baseInput({ environment: "both", transport: "both" });
  const result = generateMikroTikTerminalScript(input);
  assert.deepEqual(result.errors, []);
  assert.match(result.script, /address="192\.168\.88\.10\/32"/);
  assert.match(result.script, /address="10\.20\.0\.0\/24"/);
  assert.match(result.script, /policy=read,api/);
  assert.match(result.script, /policy=read,rest-api/);

  input.rest.username = input.binary.username;
  assert.ok(generateMikroTikTerminalScript(input).errors.some((error) => error.includes("distinct usernames")));
});

test("enforces deployment/transport pairing and refuses broad source networks", () => {
  assert.ok(generateMikroTikTerminalScript(baseInput({ environment: "local", transport: "rest" })).errors.some((error) => error.includes("Local Development must include Binary API")));
  assert.ok(generateMikroTikTerminalScript(baseInput({ environment: "cpanel", transport: "binary" })).errors.some((error) => error.includes("cPanel Production must include REST")));
  assert.ok(generateMikroTikTerminalScript(baseInput({ environment: "both", transport: "binary" })).errors.some((error) => error.includes("Both environment requires Both transports")));

  const broad = baseInput();
  broad.binary.source = "0.0.0.0/0";
  assert.ok(generateMikroTikTerminalScript(broad).errors.some((error) => error.includes("source IP or CIDR")));
  broad.binary.source = "10.0.0.0/8";
  assert.ok(generateMikroTikTerminalScript(broad).errors.some((error) => error.includes("source IP or CIDR")));
});

test("Mock/Demo emits comments only and never embeds credentials", () => {
  const input = baseInput({ environment: "mock" });
  const result = generateMikroTikTerminalScript(input);
  assert.deepEqual(result.errors, []);
  assert.ok(result.script.split("\n").every((line) => line.startsWith("#")));
  assert.doesNotMatch(result.script, /Strong\$pass|\/user add/);
});

test("rejects invalid IP/CIDR, ports, usernames, newline injection, and TLS/port mismatch", () => {
  const input = baseInput();
  input.binary.host = "192.168.1.1; /user add";
  input.binary.source = "10.1.2.3/33";
  input.binary.port = "8728";
  input.binary.username = "noc\n/user add";
  input.binary.password = "StrongPassword\n/user add";
  const result = generateMikroTikTerminalScript(input);
  assert.ok(result.errors.some((error) => error.includes("valid router IP")));
  assert.ok(result.errors.some((error) => error.includes("source IP or CIDR")));
  assert.ok(result.errors.some((error) => error.includes("username")));
  assert.ok(result.errors.some((error) => error.includes("password")));
  assert.ok(result.errors.some((error) => error.includes("Port 8728")));
  assert.equal(result.script, "");
});

test("rejects whitespace/newlines around values before RouterOS interpolation", () => {
  const input = baseInput();
  input.binary.source = "192.168.88.10/32\n";
  const result = generateMikroTikTerminalScript(input);
  assert.ok(result.errors.some((error) => error.includes("source IP or CIDR")));
  assert.equal(result.script, "");
});

test("supports the plain 8728 pair and verified custom service ports without changing defaults", () => {
  const plain = baseInput();
  plain.binary.useTls = false;
  plain.binary.port = "8728";
  plain.binary.certificateName = "";
  plain.binary.certificateReady = false;
  const plainResult = generateMikroTikTerminalScript(plain);
  assert.deepEqual(plainResult.errors, []);
  assert.match(plainResult.script, /name="api"/);
  assert.match(plainResult.script, /port\] != 8728/);

  const custom = baseInput();
  custom.binary.port = "8829";
  assert.deepEqual(generateMikroTikTerminalScript(custom).errors, []);
  assert.match(generateMikroTikTerminalScript(custom).script, /port\] != 8829/);
  custom.binary.port = "08829";
  assert.ok(generateMikroTikTerminalScript(custom).errors.some((error) => error.includes("port from 1 to 65535")));
});

test("rejects unsupported versions, invalid REST port, and missing TLS prerequisites", () => {
  const input = baseInput({ transport: "rest" });
  input.routerOsVersion = "8.0.0";
  input.rest.port = "65536";
  input.rest.certificateName = "";
  input.rest.certificateReady = false;
  const result = generateMikroTikTerminalScript(input);
  assert.ok(result.errors.some((error) => error.includes("Only RouterOS 6.49+ and 7.x")));
  assert.ok(result.errors.some((error) => error.includes("port from 1 to 65535")));
  assert.ok(result.errors.some((error) => error.includes("certificate name")));
  assert.ok(result.errors.some((error) => error.includes("certificate chain")));
});

test("environment and transport change generated summary deterministically", () => {
  const local = generateMikroTikTerminalScript(baseInput());
  const cpanel = generateMikroTikTerminalScript(baseInput({ environment: "cpanel", transport: "rest" }));
  assert.match(local.summary, /^Local Development · Binary API$/);
  assert.match(cpanel.summary, /^cPanel Production · REST over HTTPS$/);
  assert.equal(generateMikroTikTerminalScript(baseInput()).script, local.script);
});

test("Copy writes the generated text and propagates clipboard failures", async () => {
  let copied = "";
  await actions.copyRouterOsScript("sensitive-router-script", {
    writeText: async (value) => { copied = value; },
  });
  assert.equal(copied, "sensitive-router-script");
  await assert.rejects(
    actions.copyRouterOsScript("sensitive-router-script", {
      writeText: async () => { throw new Error("clipboard denied"); },
    }),
    /clipboard denied/,
  );
});

test("Download creates an .rsc text blob, triggers the named download, and revokes its URL", async () => {
  let downloadedUrl = "";
  let downloadedFilename = "";
  let downloadedBlob;
  let revoke;
  actions.downloadRouterOsScript("router script", "router.rsc", {
    createObjectUrl: (blob) => {
      downloadedBlob = blob;
      return "blob:test-script";
    },
    clickDownload: (url, filename) => {
      downloadedUrl = url;
      downloadedFilename = filename;
    },
    revokeObjectUrl: (url) => { assert.equal(url, "blob:test-script"); },
    defer: (callback) => { revoke = callback; },
  });
  assert.equal(downloadedUrl, "blob:test-script");
  assert.equal(downloadedFilename, "router.rsc");
  assert.equal(downloadedBlob.type, "text/plain;charset=utf-8");
  assert.equal(await downloadedBlob.text(), "router script");
  assert.ok(revoke);
  revoke();
});
