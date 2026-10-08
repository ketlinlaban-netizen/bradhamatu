import { Routes, Route } from "react-router-dom";
import { AuthProvider } from "./auth/AuthContext";
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

export default function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route element={<Layout />}>
          <Route path="/" element={<DashboardPage />} />
          <Route path="/routers" element={<RoutersPage />} />
          <Route path="/routers/:routerId" element={<RouterDetailPage />} />
          <Route path="/offline" element={<OfflineRoutersPage />} />
          <Route path="/clients" element={<ClientsPage />} />
          <Route path="/traffic" element={<TrafficPage />} />
          <Route path="/network-health" element={<NetworkHealthPage />} />
          <Route path="/incidents" element={<IncidentsPage />} />
          <Route path="/reports" element={<ReportsPage />} />
          <Route path="/system" element={<SystemPage />} />
          <Route path="/mikrotik-config" element={<MikroTikConfigPage />} />
        </Route>
      </Routes>
    </AuthProvider>
  );
}
