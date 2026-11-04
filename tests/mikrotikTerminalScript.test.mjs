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
const telemetrySource = await readFile(new URL("../src/services/mikrotikTelemetryScript.ts", import.meta.url), "utf8");
const compiledTelemetry = ts.transpileModule(telemetrySource, {
  compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2020 },
}).outputText;
const telemetryGenerator = await import(`data:text/javascript;base64,${Buffer.from(compiledTelemetry).toString("base64")}`);

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
  assert.match(result.script, /policy=read,api,rest-api/);
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
  assert.match(result.script, /policy=read,api,rest-api/);

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

test("router telemetry script installs a self-contained read-only package with a guarded scheduler", () => {
  const script = telemetryGenerator.generateMikroTikTelemetryScript({
    routerId: "123e4567-e89b-42d3-a456-426614174000",
    token: "a".repeat(64),
    intervalSeconds: 60,
    endpoint: "https://pandatechs.co.ke/api/v1/internal/mikrotik/telemetry",
    serverCertificateVerified: true,
  });
  assert.match(script, new RegExp(`telemetry schema v1; generator v${telemetryGenerator.MIKROTIK_TELEMETRY_GENERATOR_VERSION.replace(/\./g, "\\.")}`));
  // Bridge switch-chip port pseudo-MACs (00:00:NN:00:00:00) must never be collected as wired clients.
  assert.match(script, /:if \(\[:pick \$bridgeHostMac 0 5\] != "00:00:"\) do=\{/);
  assert.match(script, /"mac_address"=\$bridgeHostMac/);
  assert.doesNotMatch(script, /"mac_address"=\[\/interface bridge host get \$bridgeHostId mac-address\]/);

  assert.match(script, /:serialize to=json value=\$payload/);
  assert.match(script, /"uptime"=\[:tostr \[\/system resource get uptime\]\]/);
  assert.match(script, /:local snapshotId \(\$isoDate \. "_"/);
  assert.doesNotMatch(script, /:local snapshotId .*resource get uptime/);
  assert.match(script, /\/system clock get gmt-offset/);
  assert.match(script, /:local utcOffset \(\$offsetSign \. \$offsetHourText \. ":" \. \$offsetMinuteText\)/);
  assert.match(script, /:local observedAt \(\$isoDate \. "T" \. \$clockTime \. \$utcOffset\)/);
  assert.doesNotMatch(script, /time-zone-name.*Etc\/UTC/);
  assert.match(script, /http-method=post/);
  assert.match(script, /http-header-field=/);
  assert.match(script, /Content-Type: application\/json,Authorization: Bearer /);
  assert.match(script, /check-certificate=yes/);
  assert.match(script, /as-value output=user/);
  assert.doesNotMatch(script, /output=none/);
  assert.match(script, /fetchResult->"status"/);
  assert.match(script, /fetchResult->"code"/);
  assert.match(script, /:if \(\[:typeof \$responseCode\] = "num"\)/);
  assert.match(script, /endpoint returned HTTP/);
  assert.match(script, /:do \{\s*:local fetchResult \[\/tool fetch/);
  assert.match(script, /\} on-error=\{\s*:log warning "Bratha telemetry: HTTPS submission failed/);
  assert.doesNotMatch(script, /:onerror/);
  assert.match(script, /https:\/\/pandatechs\.co\.ke\/api\/v1\/internal\/mikrotik\/telemetry/);
  assert.match(script, /interval=60s/);
  // Self-contained package: the scheduler is installed (not commented) and a first report fires on import.
  assert.match(script, /^\/system scheduler add /m);
  assert.match(script, /^\/system script run /m);
  assert.match(script, /Send the first report immediately/);
  assert.match(script, /# Rollback/);
  assert.match(script, /\/system script add .*policy=read,test/);
  assert.match(script, /\/system scheduler add .*policy=read,test/);
  assert.doesNotMatch(script, /policy=ftp,read|policy=write/);
  // Idempotent install guards refuse duplicate objects and only manage the two named ones.
  assert.match(script, /Multiple telemetry scripts named .* remove the duplicates before installing/);
  assert.match(script, /Multiple telemetry schedulers named .* remove the duplicates before installing/);
  assert.match(script, /:if \(\[:len \$installScriptIds\] = 1\) do=\{ \/system script remove/);
  assert.match(script, /:if \(\[:len \$installSchedulerIds\] = 1\) do=\{ \/system scheduler remove/);
  // Non-invasive: the package never mutates router services, users, firewall, ARP, addresses or DHCP leases.
  assert.doesNotMatch(script, /\/ip service (set|add|remove)/);
  assert.doesNotMatch(script, /\/user (add|set|remove)/);
  assert.doesNotMatch(script, /\/ip firewall/);
  assert.doesNotMatch(script, /\/ip dhcp-server lease (add|set|remove)/);
  assert.doesNotMatch(script, /\/ip arp (add|set|remove)/);
  assert.doesNotMatch(script, /\/ip address (add|set|remove)/);
  assert.match(script, /runningJobs/);
  assert.match(script, /\/ip dhcp-server lease get \$leaseId server/);
  assert.match(script, /"dynamic"=\[\/ip dhcp-server lease get \$leaseId dynamic\]/);
  assert.match(script, /"dhcp_lease_dynamic_supported"=true/);
  assert.match(script, /\/ip dhcp-server get \[:pick \$leaseServerIds 0\] interface/);
  assert.doesNotMatch(script, /\/ip dhcp-server lease get \$leaseId interface/);
  assert.match(script, /wirelessRegistrationIds/);
  assert.match(script, /wirelessCollectionComplete/);
  assert.match(script, /wireless_clients_complete/);
  assert.match(script, /\$wirelessCollectedCount != \[:len \$wirelessRegistrationIds/);
  assert.match(script, /:set wirelessRegistrationIds \[\/interface wifi registration-table find\]/);
  assert.match(script, /:set wirelessRegistrationIds \[\/interface wireless registration-table find\]/);
  assert.match(script, /:if \(\[:len \$wirelessRegistrationIds\] = 0\) do=\{\s*:do \{\s*:local legacyRegistrationIds \[\/interface wireless registration-table find\]/);
  assert.match(script, /:set wirelessRegistrationIds \$legacyRegistrationIds/);
  assert.doesNotMatch(script, /on-error=\{\s*\}/);
  assert.match(script, /:local wirelessRegistrationPath "wifi"/);
  assert.match(script, /:set wirelessRegistrationPath "wireless"/);
  assert.match(script, /:set signalText \[:tostr \[\/interface wireless registration-table get \$clientId signal-strength\]\]/);
  assert.match(script, /:set signalText \[:tostr \[\/interface wifi registration-table get \$clientId signal\]\]/);
  assert.match(script, /\/interface wifi registration-table get \$clientId rx-bytes/);
  assert.match(script, /\/interface wifi registration-table get \$clientId tx-bytes/);
  assert.match(script, /:local signalEnd \[:find \$signalText "dBm"\]/);
  assert.match(script, /:set signalStrength \[:tonum \[:pick \$signalText 0 \$signalEnd\]\]/);
  assert.match(script, /:set bridgeHostIds \[\/interface bridge host find\]/);
  assert.match(script, /\/interface bridge host get \$bridgeHostId dynamic/);
  assert.match(script, /\/interface bridge host get \$bridgeHostId local/);
  assert.match(script, /:if \(\$bridgeHostDynamic = true\) do=\{\s*:if \(\$bridgeHostLocal = false\) do=/);
  assert.match(script, /"bridge_hosts"=\$bridgeHosts/);
  assert.match(script, /"bridge_hosts_complete"=\$bridgeHostsComplete/);
  assert.match(script, /"bridge_hosts_collection_succeeded"=\$bridgeHostsCollectionSucceeded/);
  assert.match(script, /"bridge_host_count"=\$bridgeHostCount/);
  assert.match(script, /:set arpNeighborIds \[\/ip arp find\]/);
  assert.match(script, /\/ip arp get \$arpNeighborId dynamic/);
  assert.match(script, /\/ip arp get \$arpNeighborId address/);
  assert.match(script, /\/ip arp get \$arpNeighborId mac-address/);
  assert.match(script, /\/ip arp get \$arpNeighborId interface/);
  assert.match(script, /:if \(\[:len \$arpAddress\] > 0\) do=\{\s*:if \(\[:len \$arpMacAddress\] > 0\) do=\{\s*:if \(\$arpMacAddress != "00:00:00:00:00:00"\) do=/);
  assert.doesNotMatch(script, /:if \(\(\[:len \$arpAddress\] > 0\)\n\s+&&/);
  assert.doesNotMatch(script, /&&|\|\|/);
  assert.match(script, /"arp_neighbors"=\$arpNeighbors/);
  assert.match(script, /"arp_neighbors_complete"=\$arpNeighborsComplete/);
  assert.match(script, /"arp_neighbors_collection_succeeded"=\$arpNeighborsCollectionSucceeded/);
  assert.match(script, /"arp_neighbor_count"=\$arpNeighborCount/);
  assert.match(script, /dhcpLeasesComplete/);
  assert.match(script, /:set \(\$leases->\$dhcpLeasesCollectedCount\) \$leaseData/);
  assert.match(script, /:set \(\$interfaces->\$interfacesCollectedCount\) \$interfaceData/);
  assert.match(script, /:set \(\$ipAddresses->\$ipAddressesCollectedCount\) \$ipAddressData/);
  assert.match(script, /:set \(\$routes->\$routesCollectedCount\) \$routeData/);
  assert.match(script, /:set \(\$wirelessClients->\$wirelessCollectedCount\) \$clientData/);
  assert.doesNotMatch(script, /:set (?:interfaces|ipAddresses|routes|leases|wirelessClients) \(\$(?:interfaces|ipAddresses|routes|leases|wirelessClients), \$/);
  assert.match(script, /dhcp_lease_count/);
  assert.match(script, /dhcp_collected_count/);
  assert.match(script, /wireless_registration_count/);
  assert.match(script, /wireless_collected_count/);
  assert.match(script, /wireless_clients_collection_succeeded/);
  assert.match(script, /ip_addresses_collection_succeeded/);
  assert.match(script, /routes_collection_succeeded/);
  assert.match(script, /\/ip address find/);
  assert.match(script, /:set \(\$ipAddresses->\$ipAddressesCollectedCount\) \$ipAddressData/);
  assert.match(script, /\/ip route find where dst-address="0\.0\.0\.0\/0"/);
  assert.match(script, /"immediate_gateway"=\[:tostr \[\/ip route get \$routeId immediate-gw\]\]/);
  assert.match(script, /immediate_gateway/);
  assert.match(script, /router_mac_address/);
  assert.match(script, /\/interface find where type="bridge"/);
  assert.match(script, /00:00:00:00:00:00/);
  assert.doesNotMatch(script, /https?:\/\/192\.168\.88\.1/);
  assert.doesNotMatch(script, /http-method=(put|patch|delete)/);
  assert.doesNotMatch(script, /www-ssl|api-ssl|\/system reboot/i);

  const tenSecondScript = telemetryGenerator.generateMikroTikTelemetryScript({
    routerId: "123e4567-e89b-42d3-a456-426614174000",
    token: "a".repeat(64),
    intervalSeconds: 10,
    endpoint: "https://pandatechs.co.ke/api/v1/internal/mikrotik/telemetry",
    serverCertificateVerified: true,
  });
  assert.match(tenSecondScript, /interval=10s/);
});

test("telemetry script rejects malformed router IDs, secrets, and unsafe intervals", () => {
  const input = {
    routerId: "123e4567-e89b-42d3-a456-426614174000",
    token: "a".repeat(64),
    intervalSeconds: 60,
    endpoint: "https://pandatechs.co.ke/api/v1/internal/mikrotik/telemetry",
    serverCertificateVerified: true,
  };
  assert.throws(() => telemetryGenerator.generateMikroTikTelemetryScript({ ...input, routerId: "not-a-uuid" }), /UUID/);
  assert.throws(() => telemetryGenerator.generateMikroTikTelemetryScript({ ...input, token: "short" }), /generated secret/);
  assert.throws(() => telemetryGenerator.generateMikroTikTelemetryScript({ ...input, intervalSeconds: 30 }), /5, 10, 60, 120, or 300/);
  assert.throws(() => telemetryGenerator.generateMikroTikTelemetryScript({ ...input, serverCertificateVerified: false }), /check-certificate=yes/);
  assert.throws(() => telemetryGenerator.generateMikroTikTelemetryScript({ ...input, endpoint: "http://pandatechs.co.ke/api/v1/internal/mikrotik/telemetry" }), /configured HTTPS/);
  const fiveSecondScript = telemetryGenerator.generateMikroTikTelemetryScript({ ...input, intervalSeconds: 5 });
  assert.match(fiveSecondScript, /interval=5s/);
});
