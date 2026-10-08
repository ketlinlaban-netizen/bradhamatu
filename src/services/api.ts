/**
 * Centralized API client for the Laravel backend.
 *
 * All HTTP calls go through this module. It automatically:
 *   - Prepends the Laravel API base URL
 *   - Attaches the Bearer token from localStorage
 *   - Handles 401 by clearing the session and redirecting to login
 *   - Returns parsed JSON or throws with a meaningful message
 */

// When the frontend is built into Laravel's public/ directory and served
// from the same origin, VITE_API_URL can be left unset and we use the
// relative "/api" path — same-origin, no CORS needed.
// When the frontend runs on a different origin (e.g. dev server), set
// VITE_API_URL to the full Laravel URL (e.g. https://console.example.com/api).
const API_BASE_URL = import.meta.env.VITE_API_URL ?? "/api";

const TOKEN_KEY = "bradha-matu-token";

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY);
}

async function request<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const token = getToken();
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    Accept: "application/json",
    ...options.headers as Record<string, string>,
  };

  if (token) {
    headers["Authorization"] = `Bearer ${token}`;
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers,
  });

  if (response.status === 401) {
    clearToken();
    localStorage.removeItem("bradha-matu-auth");
    window.location.href = "/login";
    throw new Error("Session expired. Please sign in again.");
  }

  if (!response.ok) {
    let message = `Request failed (${response.status})`;
    try {
      const body = await response.json();
      if (body.error) message = body.error;
      else if (body.message) message = body.message;
    } catch {
      // response had no JSON body
    }
    throw new Error(message);
  }

  // 204 No Content
  if (response.status === 204) {
    return undefined as T;
  }

  return response.json() as Promise<T>;
}

export async function apiGet<T>(path: string): Promise<T> {
  return request<T>(path, { method: "GET" });
}

export async function apiPost<T>(path: string, body?: unknown): Promise<T> {
  return request<T>(path, {
    method: "POST",
    body: body ? JSON.stringify(body) : undefined,
  });
}

export async function apiDelete<T>(path: string): Promise<T> {
  return request<T>(path, { method: "DELETE" });
}
