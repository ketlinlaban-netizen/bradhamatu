import { createContext, useContext, useState, useEffect, type ReactNode } from "react";
import type { AdminUser } from "../types";
import { apiPost, apiGet, setToken, clearToken } from "../services/api";

/**
 * AuthContext — real Laravel Sanctum authentication.
 *
 * Calls the Laravel /api/auth/login endpoint, stores the Bearer token,
 * and persists the user profile in localStorage. On app load, it
 * verifies the token by calling /api/auth/me.
 */

interface AuthState {
  user: AdminUser | null;
  login: (email: string, password: string) => Promise<{ success: boolean; error?: string }>;
  logout: () => void;
  isLoading: boolean;
}

const AuthContext = createContext<AuthState | null>(null);

const USER_KEY = "bradha-matu-auth";

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AdminUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const stored = localStorage.getItem(USER_KEY);
    if (stored) {
      try {
        const parsed = JSON.parse(stored) as AdminUser;
        setUser(parsed);
        // Verify the token is still valid
        apiGet<AdminUser>("/auth/me")
          .then((freshUser) => {
            setUser(freshUser);
            localStorage.setItem(USER_KEY, JSON.stringify(freshUser));
          })
          .catch(() => {
            clearToken();
            localStorage.removeItem(USER_KEY);
            setUser(null);
          })
          .finally(() => setIsLoading(false));
        return;
      } catch {
        localStorage.removeItem(USER_KEY);
      }
    }
    setIsLoading(false);
  }, []);

  const login = async (email: string, password: string): Promise<{ success: boolean; error?: string }> => {
    try {
      const result = await apiPost<{ success: boolean; token: string; user: AdminUser }>(
        "/auth/login",
        { email, password },
      );

      if (!result.success || !result.token) {
        return { success: false, error: "Login failed" };
      }

      setToken(result.token);
      setUser(result.user);
      localStorage.setItem(USER_KEY, JSON.stringify(result.user));
      return { success: true };
    } catch (err) {
      const message = err instanceof Error ? err.message : "Login failed";
      return { success: false, error: message };
    }
  };

  const logout = () => {
    apiPost("/auth/logout").catch(() => {});
    clearToken();
    localStorage.removeItem(USER_KEY);
    setUser(null);
  };

  return (
    <AuthContext.Provider value={{ user, login, logout, isLoading }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
