import { Routes, Route, Outlet } from "react-router-dom";
import { AuthProvider } from "./auth/AuthContext";
import { CustomerAuthProvider } from "./auth/CustomerAuthContext";
import { Layout } from "./components/Layout";
import { LoginPage } from "./pages/LoginPage";
import { DashboardPage } from "./pages/DashboardPage";
import { RoutersPage } from "./pages/RoutersPage";
import { RouterDetailPage } from "./pages/RouterDetailPage";
import { OfflineRoutersPage } from "./pages/OfflineRoutersPage";
import { ClientsPage } from "./pages/ClientsPage";
import { TrafficPage } from "./pages/TrafficPage";
import { NetworkHealthPage } from "./pages/NetworkHealthPage";
import { IncidentsPage } from "./pages/IncidentsPage";
import { ReportsPage } from "./pages/ReportsPage";
import { SystemPage } from "./pages/SystemPage";
import { MikroTikConfigPage } from "./pages/MikroTikConfigPage";
import { PublicHomePage } from "./pages/public/PublicHomePage";
import { CustomerLoginPage } from "./pages/customer/CustomerLoginPage";
import { CustomerRegisterPage } from "./pages/customer/CustomerRegisterPage";
import { CustomerDashboardPage } from "./pages/customer/CustomerDashboardPage";
import { CustomerProvidersPage } from "./pages/customer/CustomerProvidersPage";
import { CustomerProviderDetailPage } from "./pages/customer/CustomerProviderDetailPage";
import { CustomerHotspotPage } from "./pages/customer/CustomerHotspotPage";
import { CustomerSubscriptionPage } from "./pages/customer/CustomerSubscriptionPage";
import { CustomerUsagePage } from "./pages/customer/CustomerUsagePage";
import { CustomerPaymentsPage } from "./pages/customer/CustomerPaymentsPage";
import { CustomerSupportPage } from "./pages/customer/CustomerSupportPage";
import { BillingFlowPage } from "./pages/BillingFlowPage";
import { PackagesPage } from "./pages/PackagesPage";
import { VouchersPage } from "./pages/VouchersPage";
import { CustomersPage } from "./pages/CustomersPage";
import { RadiusPage } from "./pages/RadiusPage";

export default function App() {
  return (
    <AuthProvider>
      <Routes>
        {/* Public routes */}
        <Route path="/" element={<PublicHomePage />} />
        {/* Customer authentication state is shared across all customer routes. */}
        <Route element={<CustomerAuthProvider><Outlet /></CustomerAuthProvider>}>
          <Route path="/customer/login" element={<CustomerLoginPage />} />
          <Route path="/customer/register" element={<CustomerRegisterPage />} />
          <Route path="/customer/dashboard" element={<CustomerDashboardPage />} />
          <Route path="/customer/providers" element={<CustomerProvidersPage />} />
          <Route path="/customer/providers/:providerId" element={<CustomerProviderDetailPage />} />
          <Route path="/customer/hotspot" element={<CustomerHotspotPage />} />
          <Route path="/customer/subscription" element={<CustomerSubscriptionPage />} />
          <Route path="/customer/usage" element={<CustomerUsagePage />} />
          <Route path="/customer/payments" element={<CustomerPaymentsPage />} />
          <Route path="/customer/support" element={<CustomerSupportPage />} />
        </Route>

        {/* Admin console */}
        <Route path="/login" element={<LoginPage />} />
        <Route path="/admin/login" element={<LoginPage />} />
        <Route element={<Layout />}>
          <Route path="/admin" element={<DashboardPage />} />
          <Route path="/admin/billing-flow" element={<BillingFlowPage />} />
          <Route path="/admin/packages" element={<PackagesPage />} />
          <Route path="/admin/vouchers" element={<VouchersPage />} />
          <Route path="/admin/customers" element={<CustomersPage />} />
          <Route path="/admin/radius" element={<RadiusPage />} />
          <Route path="/admin/routers" element={<RoutersPage />} />
          <Route path="/admin/routers/:routerId" element={<RouterDetailPage />} />
          <Route path="/admin/offline" element={<OfflineRoutersPage />} />
          <Route path="/admin/clients" element={<ClientsPage />} />
          <Route path="/admin/traffic" element={<TrafficPage />} />
          <Route path="/admin/network-health" element={<NetworkHealthPage />} />
          <Route path="/admin/incidents" element={<IncidentsPage />} />
          <Route path="/admin/reports" element={<ReportsPage />} />
          <Route path="/admin/system" element={<SystemPage />} />
          <Route path="/admin/mikrotik-config" element={<MikroTikConfigPage />} />
        </Route>
      </Routes>
    </AuthProvider>
  );
}
