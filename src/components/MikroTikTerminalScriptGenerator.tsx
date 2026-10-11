import { useState } from "react";
import { Check, Clipboard, Download, RefreshCw, ShieldAlert, ShieldCheck } from "lucide-react";
import {
  generateMikroTikTerminalScript,
  type BinaryScriptConfig,
  type RestScriptConfig,
  type ScriptEnvironment,
  type ScriptGeneratorInput,
  type ScriptTransport,
} from "../services/mikrotikTerminalScript";
import { copyRouterOsScript, downloadRouterOsScript } from "../services/mikrotikScriptActions";

const INITIAL_INPUT: ScriptGeneratorInput = {
  environment: "local",
  transport: "binary",
  routerOsVersion: "",
  binary: {
    host: "",
    source: "",
    username: "",
    password: "",
    port: "8729",
    useTls: true,
    certificateName: "",
    certificateReady: false,
  },
  rest: {
    host: "",
    source: "",
    username: "",
    password: "",
    port: "443",
    certificateName: "",
    certificateReady: false,
  },
};

const environmentLabels: Record<ScriptEnvironment, string> = {
  local: "Local Development",
  cpanel: "cPanel Production",
  both: "Both",
  mock: "Mock / Demo",
};

const transportLabels: Record<ScriptTransport, string> = {
  binary: "Binary API",
  rest: "REST over HTTPS",
  both: "Both transports",
};

function Field({
  label,
  value,
  type = "text",
  placeholder,
  onChange,
}: {
  label: string;
  value: string;
  type?: string;
  placeholder?: string;
  onChange: (value: string) => void;
}) {
  return (
    <label className="block text-xs font-medium text-slate-400">
      {label}
      <input
        type={type}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        autoComplete="new-password"
        className="input-field mt-1.5"
      />
    </label>
  );
}

export function MikroTikTerminalScriptGenerator({ authorized }: { authorized: boolean }) {
  const [input, setInput] = useState<ScriptGeneratorInput>(INITIAL_INPUT);
  const [generated, setGenerated] = useState<ReturnType<typeof generateMikroTikTerminalScript> | null>(null);
  const [generatedAt, setGeneratedAt] = useState<string | null>(null);
  const [notice, setNotice] = useState("");

  const update = (changes: Partial<ScriptGeneratorInput>) => {
    setInput((previous) => ({ ...previous, ...changes }));
    setGenerated(null);
    setGeneratedAt(null);
    setNotice("");
  };

  const updateBinary = (changes: Partial<BinaryScriptConfig>) => {
    setInput((previous) => ({ ...previous, binary: { ...previous.binary, ...changes } }));
    setGenerated(null);
    setGeneratedAt(null);
    setNotice("");
  };

  const updateRest = (changes: Partial<RestScriptConfig>) => {
    setInput((previous) => ({ ...previous, rest: { ...previous.rest, ...changes } }));
    setGenerated(null);
    setGeneratedAt(null);
    setNotice("");
  };

  const result = generated ?? generateMikroTikTerminalScript(input);
  const binarySelected = input.transport === "binary" || input.transport === "both";
  const restSelected = input.transport === "rest" || input.transport === "both";
  const mockMode = input.environment === "mock";

  const generate = () => {
    const next = generateMikroTikTerminalScript(input);
    setGenerated(next);
    setGeneratedAt(new Date().toLocaleString());
    setNotice(next.errors.length === 0 ? "Script generated in this page only." : "");
  };

  const copyScript = async () => {
    if (!generated?.script || generated.errors.length > 0) return;
    try {
      await copyRouterOsScript(generated.script);
      setNotice("Sensitive script copied to clipboard. Clear it when finished.");
    } catch {
      setNotice("Clipboard access was denied. Select and copy the script text manually.");
    }
  };

  const downloadScript = () => {
    if (!generated?.script || generated.errors.length > 0) return;
    try {
      const filename = `bradha-matu-${input.environment}-${new Date().toISOString().slice(0, 10)}.rsc`;
      downloadRouterOsScript(generated.script, filename);
      setNotice("Sensitive .rsc file downloaded. Store it securely and delete it after use.");
    } catch {
      setNotice("Download failed. Keep the script in this page until you can retry or copy it.");
    }
  };

  const clearGenerated = () => {
    setGenerated(null);
    setGeneratedAt(null);
    setNotice("");
    setInput((previous) => ({
      ...previous,
      binary: { ...previous.binary, password: "" },
      rest: { ...previous.rest, password: "" },
    }));
  };

  return (
    <section className="card p-5 md:p-6 space-y-5" aria-labelledby="terminal-script-heading">
      <div className="flex items-start gap-3">
        <ShieldCheck className="w-5 h-5 text-noc-accent mt-0.5" />
        <div>
          <h2 id="terminal-script-heading" className="text-lg font-semibold text-slate-100">
            Generate MikroTik Terminal Script
          </h2>
          <p className="mt-1 text-sm text-slate-400">
            Build a reviewable RouterOS .rsc locally. This page never executes it or sends these values to the application API.
          </p>
        </div>
      </div>

      {!authorized && (
        <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200">
          Only super administrators may prepare router configuration scripts.
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <label className="block text-xs font-medium text-slate-400">
          Environment
          <select
            value={input.environment}
            disabled={!authorized}
            onChange={(event) => update({
              environment: event.target.value as ScriptEnvironment,
              binary: { ...INITIAL_INPUT.binary },
              rest: { ...INITIAL_INPUT.rest },
            })}
            className="input-field mt-1.5"
          >
            {Object.entries(environmentLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
        <label className="block text-xs font-medium text-slate-400">
          Transport
          <select
            value={input.transport}
            disabled={!authorized}
            onChange={(event) => update({ transport: event.target.value as ScriptTransport })}
            className="input-field mt-1.5"
          >
            {Object.entries(transportLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
        <Field
          label="Verified RouterOS version (major.minor.patch)"
          value={input.routerOsVersion}
          placeholder="Read this from the router; e.g. 7.x.y"
          onChange={(routerOsVersion) => update({ routerOsVersion })}
        />
      </div>
      {!mockMode && (
        <p className="text-xs text-slate-500">
          Local Development must include Binary API; cPanel Production must include REST over HTTPS; Both requires Both transports. Switching environments clears all router addresses, source restrictions, usernames, passwords and certificate selections so values are not carried across environments.
        </p>
      )}

      {mockMode ? (
        <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-100">
          <div className="flex gap-2 font-semibold"><ShieldAlert className="h-4 w-4" /> Demo mode — no router setup commands will be generated.</div>
          <p className="mt-1 text-amber-200/80">The output is comment-only and cannot configure a physical router.</p>
        </div>
      ) : (
        <div className="space-y-5">
          {binarySelected && (
            <div className="rounded-lg border border-noc-border p-4 space-y-4">
              <h3 className="font-medium text-slate-100">Binary API account and service</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Field label="Router management IP / hostname" value={input.binary.host} placeholder="No guessed address" onChange={(host) => updateBinary({ host })} />
                <Field label="Approved source IP / CIDR or VPN address" value={input.binary.source} placeholder="Required — do not use 0.0.0.0/0" onChange={(source) => updateBinary({ source })} />
                <Field label="Dedicated Binary API username" value={input.binary.username} placeholder="Distinct per transport/environment" onChange={(username) => updateBinary({ username })} />
                <Field label="Binary API password" type="password" value={input.binary.password} placeholder="12+ characters; held in memory only" onChange={(password) => updateBinary({ password })} />
                <Field label={`Binary API port (${input.binary.useTls ? "API-SSL" : "plain API"})`} value={input.binary.port} placeholder={input.binary.useTls ? "8729" : "8728"} onChange={(port) => updateBinary({ port })} />
                {input.binary.useTls && (
                  <Field label="API-SSL certificate name on router" value={input.binary.certificateName} placeholder="Must already exist and be valid" onChange={(certificateName) => updateBinary({ certificateName })} />
                )}
              </div>
              <label className="flex items-center gap-2 text-sm text-slate-300">
                <input
                  type="checkbox"
                  checked={input.binary.useTls}
                  onChange={(event) => {
                    const useTls = event.target.checked;
                    const expectedPreviousPort = input.binary.useTls ? "8729" : "8728";
                    const expectedNextPort = useTls ? "8729" : "8728";
                    updateBinary({
                      useTls,
                      port: input.binary.port === expectedPreviousPort ? expectedNextPort : input.binary.port,
                    });
                  }}
                />
                Use TLS with API-SSL (recommended)
              </label>
              {input.binary.useTls ? (
                <label className="flex items-center gap-2 text-xs text-slate-400">
                  <input type="checkbox" checked={input.binary.certificateReady} onChange={(event) => updateBinary({ certificateReady: event.target.checked })} />
                  I verified the trusted certificate and that its SAN matches the management address.
                </label>
              ) : (
                <p className="text-xs text-amber-300">Plain API is normally port 8728 and does not encrypt credentials. Use only on an isolated trusted network.</p>
              )}
              <p className="text-xs text-slate-500">8728 is plain API; 8729 is API-SSL. Custom ports are accepted only when the existing RouterOS service already uses that exact port.</p>
            </div>
          )}

          {restSelected && (
            <div className="rounded-lg border border-noc-border p-4 space-y-4">
              <h3 className="font-medium text-slate-100">REST over HTTPS account and service</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Field label="Router HTTPS management hostname / IP" value={input.rest.host} placeholder="Certificate SAN must match" onChange={(host) => updateRest({ host })} />
                <Field label="Approved cPanel source IP / CIDR or VPN address" value={input.rest.source} placeholder="Required — never public-anywhere" onChange={(source) => updateRest({ source })} />
                <Field label="Dedicated REST username" value={input.rest.username} placeholder="Separate from Binary API account" onChange={(username) => updateRest({ username })} />
                <Field label="REST password" type="password" value={input.rest.password} placeholder="12+ characters; held in memory only" onChange={(password) => updateRest({ password })} />
                <Field label="HTTPS REST port" value={input.rest.port} placeholder="443" onChange={(port) => updateRest({ port })} />
                <Field label="HTTPS certificate name on router" value={input.rest.certificateName} placeholder="Must already exist and be valid" onChange={(certificateName) => updateRest({ certificateName })} />
              </div>
              <label className="flex items-center gap-2 text-xs text-slate-400">
                <input type="checkbox" checked={input.rest.certificateReady} onChange={(event) => updateRest({ certificateReady: event.target.checked })} />
                I verified a trusted certificate chain and hostname/IP SAN for HTTPS.
              </label>
              <p className="text-xs text-slate-500">RouterOS REST requires 7.1+. This configures only the HTTPS www-ssl service; no HTTP service is enabled.</p>
            </div>
          )}
        </div>
      )}

      <div className="rounded-lg border border-noc-border bg-noc-panel/40 p-4">
        <h3 className="text-sm font-semibold text-slate-200">Application read operations and permissions</h3>
        <p className="mt-1 text-xs text-slate-400">
          The application’s fixed allowlist reads identity, system resources, interfaces, IP addresses, DHCP leases, wireless configuration/registrations, and one-shot interface traffic. The generated group uses coarse RouterOS <code>read</code> plus <code>api</code> and/or <code>rest-api</code>; RouterOS cannot constrain that group to only those individual paths.
        </p>
        <p className="mt-2 text-xs text-slate-400">REST maps print paths to GET /rest/&lt;resource&gt;; bounded traffic uses POST /rest/interface/monitor-traffic with interface=all and once. REST availability depends on the verified RouterOS release.</p>
      </div>

      {!mockMode && (
        <div className="rounded-lg border border-blue-500/20 bg-blue-500/5 p-4">
          <h3 className="text-sm font-semibold text-slate-200">Review — intended configuration changes</h3>
          <ul className="mt-2 list-disc space-y-1 pl-5 text-xs text-slate-300">
            {binarySelected && (
              <>
                <li>Create group “{input.binary.username || "(Binary username)"}-ro” with `read,api` and a dedicated Binary API user restricted to {input.binary.source || "(required approved source)"}.</li>
                <li>Use existing {input.binary.useTls ? "api-ssl" : "api"} service on port {input.binary.port || "(required)"}; enable it and set the source restriction. {input.binary.useTls ? `Set certificate ${input.binary.certificateName || "(required verified certificate)"}.` : "No TLS certificate is configured for plain API."}</li>
              </>
            )}
            {restSelected && (
              <>
                <li>Create group “{input.rest.username || "(REST username)"}-ro” with `read,rest-api` and a separate REST user restricted to {input.rest.source || "(required approved source)"}.</li>
                <li>Use existing www-ssl on HTTPS port {input.rest.port || "(required)"}; enable it, set the source restriction, and select certificate {input.rest.certificateName || "(required verified certificate)"}.</li>
              </>
            )}
            <li>Do not replace existing users/groups. Conflict guards stop on name, service-port, existing-source, or certificate conflicts; the service port is not changed.</li>
            <li>No firewall, NAT, VPN, certificate import, DHCP, hotspot, queue, wireless, or other service configuration is changed.</li>
          </ul>
        </div>
      )}

      <div className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="text-sm">
            <span className={result.errors.length ? "text-amber-300" : "text-green-300"}>
              {result.errors.length ? `${result.errors.length} prerequisite(s) missing` : "Validation ready"}
            </span>
            <span className="ml-2 text-xs text-slate-500">{result.summary}</span>
          </div>
          <button type="button" onClick={generate} disabled={!authorized} className="btn-primary flex items-center gap-2 text-sm">
            <RefreshCw className="h-4 w-4" /> {generated ? "Regenerate Script" : "Generate Script"}
          </button>
        </div>

        {result.errors.length > 0 && !mockMode && (
          <ul className="list-disc space-y-1 pl-5 text-xs text-amber-200" aria-live="polite">
            {result.errors.map((item) => <li key={item}>{item}</li>)}
          </ul>
        )}
        {result.warnings.length > 0 && (
          <ul className="list-disc space-y-1 pl-5 text-xs text-amber-200/80">
            {result.warnings.map((item) => <li key={item}>{item}</li>)}
          </ul>
        )}

        {generated && generated.errors.length === 0 && (
          <>
            <div className="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-xs text-red-200">
              Sensitive output: the script contains plaintext password(s). Anyone with the copied text or downloaded file can read them. Do not email, commit, upload, or leave the file on shared storage. For stronger handling, omit password-bearing imports and enter credentials manually in the RouterOS terminal.
            </div>
            <textarea
              readOnly
              value={generated.script}
              rows={Math.min(32, Math.max(8, generated.script.split("\n").length))}
              className="input-field min-h-48 font-mono text-xs leading-5"
              aria-label="Generated RouterOS terminal script"
              spellCheck={false}
            />
            <div className="flex flex-wrap gap-2">
              <button type="button" onClick={copyScript} disabled={!generated.script} className="btn-secondary flex items-center gap-2 text-sm">
                {notice.startsWith("Sensitive script copied") ? <Check className="h-4 w-4" /> : <Clipboard className="h-4 w-4" />} Copy Script
              </button>
              <button type="button" onClick={downloadScript} disabled={!generated.script} className="btn-secondary flex items-center gap-2 text-sm">
                <Download className="h-4 w-4" /> Download .rsc
              </button>
              <button type="button" onClick={clearGenerated} className="btn-secondary text-sm">Clear script and passwords</button>
              {generatedAt && <span className="self-center text-xs text-slate-500">Generated {generatedAt}</span>}
            </div>
            {notice && <p className="text-xs text-slate-300" aria-live="polite">{notice}</p>}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
              <InstructionList title="Preflight — run and inspect manually" items={generated.preflight} />
              <InstructionList title="Post-import verification" items={generated.verification} />
              <InstructionList title="Rollback — manual, review first" items={generated.rollback} />
            </div>
          </>
        )}
        {generated && generated.errors.length > 0 && (
          <p className="text-sm text-amber-200">No executable script was generated. Correct the prerequisites above and regenerate.</p>
        )}
      </div>
    </section>
  );
}

function InstructionList({ title, items }: { title: string; items: string[] }) {
  return (
    <div className="rounded-lg border border-noc-border p-3">
      <h4 className="font-medium text-slate-200">{title}</h4>
      <ul className="mt-2 space-y-1.5 text-slate-400">
        {items.map((item) => <li key={item} className="break-all"><code>{item}</code></li>)}
      </ul>
    </div>
  );
}
