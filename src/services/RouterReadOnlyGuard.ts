import type { OperationType } from "../types";

/**
 * RouterReadOnlyGuard
 *
 * Enforces that every operation passing through the RouterService
 * is classified as READ. WRITE operations are blocked at the
 * service layer — not merely hidden in the UI.
 *
 * This is a real architectural enforcement point. If someone later
 * accidentally calls `$router->reboot()` through the service, the
 * guard rejects it before it reaches the provider.
 */
export class ReadOnlyGuardError extends Error {
  constructor(operation: string) {
    super(`BLOCKED by ReadOnlyGuard: "${operation}" is a WRITE operation. ` +
          `This console is strictly read-only. No mutation operations are permitted.`);
    this.name = "ReadOnlyGuardError";
  }
}

export class RouterReadOnlyGuard {
  private allowedOperations: Set<string> = new Set([
    "getRouter",
    "getRouters",
    "getStatus",
    "getInterfaces",
    "getClients",
    "getTraffic",
    "getWireless",
    "getMetrics",
    "getDashboardSummary",
    "getIncidents",
    "connect",
    "searchRouters",
  ]);

  classify(operation: string): OperationType {
    return this.allowedOperations.has(operation) ? "READ" : "WRITE";
  }

  enforce(operation: string): void {
    const type = this.classify(operation);
    if (type === "WRITE") {
      throw new ReadOnlyGuardError(operation);
    }
  }
}
