import { createContext, useContext, useState, useEffect, type ReactNode } from "react";
import type { Customer } from "../types/billing";
import {
  getCustomerToken,
  clearCustomerToken,
  customerLogin,
  customerLogout,
  getCustomerProfile,
  customerRegister,
} from "../services/customerApi";

interface CustomerAuthState {
  customer: Customer | null;
  login: (phone: string, password: string) => Promise<{ success: boolean; error?: string }>;
  register: (data: {
    full_name: string;
    phone: string;
    email?: string;
    password: string;
    terms_accepted: boolean;
  }) => Promise<{ success: boolean; error?: string }>;
  logout: () => void;
  isLoading: boolean;
}

const CustomerAuthContext = createContext<CustomerAuthState | null>(null);

export function CustomerAuthProvider({ children }: { children: ReactNode }) {
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const token = getCustomerToken();
    if (token) {
      getCustomerProfile()
        .then((profile) => {
          if (profile) {
            setCustomer(profile);
          } else {
            clearCustomerToken();
          }
        })
        .catch(() => clearCustomerToken())
        .finally(() => setIsLoading(false));
    } else {
      setIsLoading(false);
    }
  }, []);

  const login = async (phone: string, password: string) => {
    const result = await customerLogin(phone, password);
    if (result.success) {
      const profile = await getCustomerProfile();
      setCustomer(profile);
    }
    return result;
  };

  const register = async (data: {
    full_name: string;
    phone: string;
    email?: string;
    password: string;
    terms_accepted: boolean;
  }) => {
    const result = await customerRegister(data);
    if (result.success && result.customer) {
      setCustomer(result.customer);
    }
    return result;
  };

  const logout = () => {
    customerLogout();
    setCustomer(null);
  };

  return (
    <CustomerAuthContext.Provider value={{ customer, login, register, logout, isLoading }}>
      {children}
    </CustomerAuthContext.Provider>
  );
}

export function useCustomerAuth(): CustomerAuthState {
  const ctx = useContext(CustomerAuthContext);
  if (!ctx) throw new Error("useCustomerAuth must be used within CustomerAuthProvider");
  return ctx;
}
