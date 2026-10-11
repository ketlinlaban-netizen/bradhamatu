// Customer portal domain types

export interface Customer {
  id: string;
  full_name: string;
  phone: string;
  email: string | null;
  status: string;
  registered_at: string;
}

export interface Package {
  id: string;
  name: string;
  description: string | null;
  price_ksh: number;
  price_minor: number;
  checkout_total_ksh?: number;
  checkout_tax_ksh?: number;
  tax_inclusive?: boolean;
  duration_minutes: number;
  duration_display: string;
  access_type: string;
  download_kbps: number;
  upload_kbps: number;
  max_devices: number;
  is_enabled?: boolean;
  sort_order?: number;
}

export interface Payment {
  id: string;
  state: string;
  amount_ksh: number;
  provider: string;
  provider_reference: string | null;
  failure_reason: string | null;
  initiated_at: string | null;
  completed_at: string | null;
  invoice_number?: string | null;
}

export interface ServiceAccount {
  id: string;
  package_id?: string | null;
  package_name?: string | null;
  price_ksh?: number | null;
  activation_status: string;
  access_type: string;
  activated_at: string | null;
  expires_at: string | null;
  deactivation_reason: string | null;
}

export interface Purchase {
  id: string;
  invoice_number?: string | null;
  order_id: string;
  status: string;
  amount_ksh: number;
  package_name: string;
  created_at: string;
  completed_at: string | null;
  payment: Payment | null;
  service_account: ServiceAccount | null;
}

export interface SupportTicket {
  id: string;
  subject: string;
  body: string;
  status: string;
  priority: string;
  created_at: string;
}
