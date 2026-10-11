export type ScriptEnvironment = "local" | "cpanel" | "both" | "mock";
export type ScriptTransport = "binary" | "rest" | "both";

export type BinaryScriptConfig = {
  host: string;
  source: string;
  username: string;
  password: string;
  port: string;
  useTls: boolean;
  certificateName: string;
  certificateReady: boolean;
};

export type RestScriptConfig = {
  host: string;
  source: string;
  username: string;
  password: string;
  port: string;
  certificateName: string;
  certificateReady: boolean;
};

export type ScriptGeneratorInput = {
  environment: ScriptEnvironment;
  transport: ScriptTransport;
  routerOsVersion: string;
  binary: BinaryScriptConfig;
  rest: RestScriptConfig;
};

export type ScriptGenerationResult = {
  script: string;
  errors: string[];
  warnings: string[];
  preflight: string[];
  verification: string[];
  rollback: string[];
  summary: string;
};

const isSelected = (transport: ScriptTransport, value: "binary" | "rest") =>
  transport === "both" || transport === value;

function validIpv4(value: string): boolean {
  const parts = value.split(".");
  return parts.length === 4 && parts.every((part) =>
    /^\d{1,3}$/.test(part) && Number(part) >= 0 && Number(part) <= 255 &&
    (part === "0" || !part.startsWith("0")),
  );
}

function validIpv6(value: string): boolean {
  if (!value.includes(":") || value.includes("%")) return false;
  try {
    return new URL(`http://[${value}]/`).hostname === `[${value.toLowerCase()}]`;
  } catch {
    return false;
  }
}

function validIp(value: string): boolean {
  return validIpv4(value) || validIpv6(value);
}

function validSource(value: string): boolean {
  const parts = value.split("/");
  if (parts.length > 2 || !validIp(parts[0])) return false;
  if (parts[0] === "0.0.0.0" || parts[0] === "::") return false;
  if (parts.length === 1) return true;
  if (!/^\d{1,3}$/.test(parts[1])) return false;
  const prefix = Number(parts[1]);
  return validIpv4(parts[0]) ? prefix >= 16 && prefix <= 32 : prefix >= 48 && prefix <= 128;
}

function validHost(value: string): boolean {
  if (value === "0.0.0.0" || value === "::") return false;
  if (validIp(value)) return true;
  if (value.length > 253 || value.endsWith(".")) return false;
  return value.split(".").every((label) =>
    label.length > 0 && label.length <= 63 &&
    /^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/.test(label),
  );
}

function validPort(value: string): boolean {
  return /^\d{1,5}$/.test(value) && String(Number(value)) === value && Number(value) >= 1 && Number(value) <= 65535;
}

function validVersion(value: string): { major: number; minor: number; patch: number } | null {
  const match = /^(\d+)\.(\d+)\.(\d+)$/.exec(value);
  if (!match) return null;
  return { major: Number(match[1]), minor: Number(match[2]), patch: Number(match[3]) };
}

function validUsername(value: string): boolean {
  return value.length <= 24 && /^[A-Za-z0-9][A-Za-z0-9_.-]*$/.test(value);
}

function validPassword(value: string): boolean {
  return value.length >= 12 && value.length <= 255 && !/[\x00-\x1f\x7f]/.test(value);
}

function validCertificateName(value: string): boolean {
  return value.length <= 64 && /^[A-Za-z0-9_.-]+$/.test(value);
}

function escapeRouterOsString(value: string): string {
  return value.replace(/\\/g, "\\\\").replace(/"/g, '\\"').replace(/\$/g, "\\$");
}

function quote(value: string): string {
  return `"${escapeRouterOsString(value)}"`;
}

function addEndpointErrors(
  errors: string[],
  label: string,
  endpoint: BinaryScriptConfig | RestScriptConfig,
  transport: "binary" | "rest",
  version: { major: number; minor: number; patch: number } | null,
): void {
  if (!validHost(endpoint.host)) errors.push(`${label}: enter a valid router IP address or hostname.`);
  if (!validSource(endpoint.source)) errors.push(`${label}: enter the administrator-approved source IP or CIDR.`);
  if (!validUsername(endpoint.username)) errors.push(`${label}: username must use 1–24 letters, digits, dots, underscores or hyphens.`);
  if (!validPassword(endpoint.password)) errors.push(`${label}: enter a 12–255 character password with no line breaks or control characters.`);
  if (!validPort(endpoint.port)) errors.push(`${label}: enter a port from 1 to 65535.`);

  if (transport === "binary") {
    const binary = endpoint as BinaryScriptConfig;
    if (version && (version.major < 6 || (version.major === 6 && version.minor < 49))) {
      errors.push("The generated Binary API setup is supported only on RouterOS 6.49+ or RouterOS 7.x.");
    }
    if (binary.useTls) {
      if (!validCertificateName(binary.certificateName)) errors.push("Binary API-SSL requires a valid certificate name.");
      if (!binary.certificateReady) errors.push("Confirm that the API-SSL certificate is trusted and covers the router management hostname.");
    }
    if (Number(binary.port) === 8728 && binary.useTls) errors.push("Port 8728 is plain Binary API; select 8729 or verify a custom API-SSL port.");
    if (Number(binary.port) === 8729 && !binary.useTls) errors.push("Port 8729 is API-SSL; enable TLS or verify a custom plain API port.");
  } else {
    if (version && (version.major < 7 || (version.major === 7 && version.minor < 1))) {
      errors.push("RouterOS REST requires RouterOS 7.1 or newer.");
    }
    const rest = endpoint as RestScriptConfig;
    if (!validCertificateName(rest.certificateName)) errors.push("HTTPS REST requires a valid certificate name.");
    if (!rest.certificateReady) errors.push("Confirm that the trusted HTTPS certificate chain and hostname/IP SAN have been verified.");
  }
}

function commandForService(
  serviceName: string,
  port: string,
  source: string,
  certificateName?: string,
): string[] {
  const serviceRef = `[/ip service find where name=${quote(serviceName)}]`;
  const serviceVar = serviceName === "api-ssl" ? "apiSslService" :
    serviceName === "api" ? "apiService" : "httpsService";
  const lines = [
    `:local ${serviceVar} ${serviceRef}`,
    `:if ([:len $${serviceVar}] = 0) do={ :error ${quote(`${serviceName} service is not present; stop and inspect RouterOS services.`)} }`,
    `:if ([/ip service get $${serviceVar} port] != ${port}) do={ :error ${quote(`${serviceName} uses a different port; review the existing service before proceeding.`)} }`,
    `:local ${serviceVar}Address [/ip service get $${serviceVar} address]`,
    `:if (($${serviceVar}Address != "") && ($${serviceVar}Address != ${quote(source)})) do={ :error ${quote(`${serviceName} already has a different source restriction; review it manually.`)} }`,
  ];

  if (certificateName) {
    lines.push(
      `:local ${serviceVar}Certificate [/ip service get $${serviceVar} certificate]`,
      `:if (($${serviceVar}Certificate != "none") && ($${serviceVar}Certificate != ${quote(certificateName)})) do={ :error ${quote(`${serviceName} already uses a different certificate; review it manually.`)} }`,
      `:if ([:len [/certificate find where name=${quote(certificateName)}]] = 0) do={ :error ${quote(`Certificate ${certificateName} was not found; import and verify it first.`)} }`,
    );
  }

  return lines;
}

function serviceChange(
  serviceName: string,
  source: string,
  certificateName?: string,
): string {
  const serviceRef = `[/ip service find where name=${quote(serviceName)}]`;
  const certificateOption = certificateName ? ` certificate=${quote(certificateName)}` : "";
  return `/ip service set ${serviceRef} disabled=no address=${quote(source)}${certificateOption}`;
}

export function generateMikroTikTerminalScript(input: ScriptGeneratorInput): ScriptGenerationResult {
  const errors: string[] = [];
  const warnings: string[] = [];
  const preflight = [
    "/system resource print",
    "/system identity print",
    "/ip service print where name~\"^(api|api-ssl|www-ssl)$\"",
    "/user group print",
    "/user print",
    "/certificate print",
  ];
  const version = validVersion(input.routerOsVersion.trim());

  if (input.environment === "mock") {
    return {
      script: [
        "# DEMO ONLY — no RouterOS commands are generated for Mock/Demo.",
        "# Select a real environment only after verifying router and network details.",
      ].join("\n"),
      errors: [],
      warnings: ["Mock/Demo mode cannot generate an executable router configuration."],
      preflight,
      verification: [],
      rollback: [],
      summary: "Mock/Demo · no real-router changes",
    };
  }

  if (!version) errors.push("Enter the verified RouterOS version in major.minor.patch format.");
  if (version && ![6, 7].includes(version.major)) errors.push("Only RouterOS 6.49+ and 7.x are supported by this generator.");
  if (version && version.major === 6 && version.minor < 49) errors.push("RouterOS 6.49 or newer is required.");
  if (input.transport === "rest" || input.transport === "both") {
    if (!version || version.major < 7 || (version.major === 7 && version.minor < 1)) {
      errors.push("REST over HTTPS requires a verified RouterOS 7.1 or newer version.");
    }
  }

  const binarySelected = isSelected(input.transport, "binary");
  const restSelected = isSelected(input.transport, "rest");
  if (input.environment === "local" && !binarySelected) {
    errors.push("Local Development must include Binary API; select Binary API or Both.");
  }
  if (input.environment === "cpanel" && !restSelected) {
    errors.push("cPanel Production must include REST over HTTPS; select REST over HTTPS or Both.");
  }
  if (input.environment === "both" && input.transport !== "both") {
    errors.push("The Both environment requires Both transports so local and cPanel setup remain separately scoped.");
  }
  if (binarySelected && restSelected && input.binary.username.toLowerCase() === input.rest.username.toLowerCase()) {
    errors.push("Binary API and REST must use distinct usernames; use separate dedicated accounts.");
  }
  if (binarySelected) addEndpointErrors(errors, "Binary API", input.binary, "binary", version);
  if (restSelected) addEndpointErrors(errors, "REST over HTTPS", input.rest, "rest", version);

  if (binarySelected && !input.binary.useTls) {
    warnings.push("Plain Binary API transmits credentials without transport encryption. Restrict it to an isolated trusted management network; API-SSL is preferred.");
  }
  if (input.environment === "cpanel" && binarySelected) {
    warnings.push("Binary API from cPanel still requires an approved private route/VPN; selecting this environment does not create network reachability.");
  }
  if (input.environment === "local" && restSelected) {
    warnings.push("REST from local development still requires a trusted HTTPS endpoint and a route from the Laravel host to the router.");
  }
  if (binarySelected && Number(input.binary.port) !== (input.binary.useTls ? 8729 : 8728)) {
    warnings.push("The custom Binary API port must already be configured and listed in the Laravel server's MIKROTIK_ALLOWED_PORTS.");
  }
  if (restSelected && Number(input.rest.port) !== 443) {
    warnings.push("The custom HTTPS port must already be configured and included in the Laravel server's MIKROTIK_REST_ALLOWED_PORTS.");
  }
  warnings.push("The RouterOS read policy is coarse-grained. This group uses only read plus the selected API protocol policy; RouterOS may permit more read-only configuration visibility than this application's command allowlist.");
  warnings.push("This generator cannot inspect the physical router. Review the preflight output and conflict guards before importing.");
  warnings.push("Never expose API or HTTPS management services publicly. Use an approved private route/VPN and source restrictions.");

  const summary = `${input.environment === "local" ? "Local Development" :
    input.environment === "cpanel" ? "cPanel Production" : "Both environments"} · ${
    input.transport === "binary" ? "Binary API" :
      input.transport === "rest" ? "REST over HTTPS" : "Binary API + REST over HTTPS"
  }`;

  if (errors.length > 0) {
    return { script: "", errors: [...new Set(errors)], warnings, preflight, verification: [], rollback: [], summary };
  }

  const lines = [
    `# Bradha Matu read-only RouterOS access setup`,
    "# Generator version: 1",
    `# Environment: ${summary}`,
    `# RouterOS version supplied by operator: ${input.routerOsVersion.trim()}`,
    "# Review all preflight results and this complete script before import.",
    "# It creates dedicated user/group resources and restricts selected services to the specified source.",
    ...preflight.map((command) => `# Preflight: ${command}`),
    "",
  ];
  const guardLines: string[] = [];
  const createLines: string[] = [];
  const verification: string[] = [];
  const rollback: string[] = [];

  if (binarySelected) {
    const config = input.binary;
    const group = `${config.username}-ro`;
    const serviceName = config.useTls ? "api-ssl" : "api";
    const cert = config.useTls ? config.certificateName : undefined;
    guardLines.push(
      `:if ([:len [/user group find where name=${quote(group)}]] > 0) do={ :error ${quote(`Group ${group} already exists; inspect it and do not overwrite it.`)} }`,
      `:if ([:len [/user find where name=${quote(config.username)}]] > 0) do={ :error ${quote(`User ${config.username} already exists; inspect it and do not overwrite it.`)} }`,
      ...commandForService(serviceName, config.port, config.source, cert),
    );
    createLines.push(
      `/user group add name=${quote(group)} policy=read,api`,
      `/user add name=${quote(config.username)} group=${quote(group)} address=${quote(config.source)} password=${quote(config.password)} comment="Bradha Matu read-only Binary API"`,
      serviceChange(serviceName, config.source, cert),
    );
    verification.push(
      `/user group print where name=${quote(group)}`,
      `/user print where name=${quote(config.username)}`,
      `/ip service print where name=${quote(serviceName)}`,
    );
    rollback.push(
      `/user remove [find where name=${quote(config.username)}]`,
      `/user group remove [find where name=${quote(group)}]`,
      `# Restore the previous ${serviceName} disabled/address/certificate values recorded during preflight.`,
    );
  }

  if (restSelected) {
    const config = input.rest;
    const group = `${config.username}-ro`;
    const serviceName = "www-ssl";
    guardLines.push(
      `:if ([:len [/user group find where name=${quote(group)}]] > 0) do={ :error ${quote(`Group ${group} already exists; inspect it and do not overwrite it.`)} }`,
      `:if ([:len [/user find where name=${quote(config.username)}]] > 0) do={ :error ${quote(`User ${config.username} already exists; inspect it and do not overwrite it.`)} }`,
      ...commandForService(serviceName, config.port, config.source, config.certificateName),
    );
    createLines.push(
      `/user group add name=${quote(group)} policy=read,rest-api`,
      `/user add name=${quote(config.username)} group=${quote(group)} address=${quote(config.source)} password=${quote(config.password)} comment="Bradha Matu read-only REST"`,
      serviceChange(serviceName, config.source, config.certificateName),
    );
    verification.push(
      `/user group print where name=${quote(group)}`,
      `/user print where name=${quote(config.username)}`,
      `/ip service print where name=${quote(serviceName)}`,
    );
    rollback.push(
      `/user remove [find where name=${quote(config.username)}]`,
      `/user group remove [find where name=${quote(group)}]`,
      `# Restore the previous ${serviceName} disabled/address/certificate values recorded during preflight.`,
    );
  }

  lines.push(
    "# Conflict guards run before any configuration changes.",
    ...guardLines,
    "",
    "# Create dedicated least-privilege application accounts.",
    ...createLines,
    "",
    "# Post-import verification:",
    ...verification,
  );

  return {
    script: lines.join("\n"),
    errors,
    warnings,
    preflight,
    verification,
    rollback,
    summary,
  };
}
