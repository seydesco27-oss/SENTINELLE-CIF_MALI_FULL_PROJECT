import { BrowserRouter, Routes, Route, Navigate, Outlet } from "react-router-dom";
import { useEffect, useState } from "react";
import Login from "./pages/Login";
import Dashboard from "./pages/Dashboard";
import AlertDetail from "./pages/AlertDetail";
import TransactionDetail from "./pages/TransactionDetail";
import ClientDetail from "./pages/ClientDetail";
import Screening from "./pages/Screening";
import MlAssistance from "./pages/MlAssistance";
import AuditLog from "./pages/AuditLog";
import NetworkOverview from "./pages/NetworkOverview";
import AlertsList from "./pages/AlertsList";
import ClientsList from "./pages/ClientsList";
import TransactionsList from "./pages/TransactionsList";
import AccountsList from "./pages/AccountsList";
import InvestigationsList from "./pages/InvestigationsList";
import Analyse from "./pages/Analyse";
import Rapports from "./pages/Rapports";
import Parametres from "./pages/Parametres";
import CentifDeclarations from "./pages/CentifDeclarations";
import RoleGuard from "./components/RoleGuard";
import ComplianceDashboard from "./pages/ComplianceDashboard";
import { ROLE, resolveRoleName } from "./config/roles";
import "./components/compliance/Compliance.css";
import { isAuthenticated, getStoredUser, logout as apiLogout } from "./services/api";

function ProtectedRoute() {
  if (!isAuthenticated()) {
    return <Navigate to="/login" replace />;
  }
  return <Outlet />;
}

/** Enveloppe page + contrôle RBAC (auth déjà validée par ProtectedRoute) */
function withRole(user, onLogout, Page) {
  return (
    <RoleGuard user={user}>
      <Page user={user} onLogout={onLogout} />
    </RoleGuard>
  );
}

function App() {
  const [user, setUser] = useState(getStoredUser());

  useEffect(() => {
    const syncUser = () => setUser(getStoredUser());
    window.addEventListener("sentinelle-auth-changed", syncUser);
    return () => window.removeEventListener("sentinelle-auth-changed", syncUser);
  }, []);

  const handleLogout = async () => {
    try {
      await apiLogout();
    } catch {
      // La session locale est supprimée même si le serveur ne répond plus.
    } finally {
      setUser(null);
      window.dispatchEvent(new Event("sentinelle-auth-changed"));
    }
  };

  return (
    <BrowserRouter>
      <Routes>
        <Route
          path="/"
          element={
            <Navigate
              to={isAuthenticated() ? "/dashboard" : "/login"}
              replace
            />
          }
        />
        <Route path="/login" element={<Login />} />
        {import.meta.env.DEV && (
          <Route path="/apercu/conformite" element={<ComplianceDashboard preview />} />
        )}

        <Route element={<ProtectedRoute />}>
          <Route path="/dashboard" element={withRole(user, handleLogout, resolveRoleName(user) === ROLE.COMPLIANCE_OFFICER ? ComplianceDashboard : Dashboard)} />
          <Route path="/alertes" element={withRole(user, handleLogout, AlertsList)} />
          <Route path="/alertes/:id" element={withRole(user, handleLogout, AlertDetail)} />
          <Route path="/transactions" element={withRole(user, handleLogout, TransactionsList)} />
          <Route path="/transactions/:id" element={withRole(user, handleLogout, TransactionDetail)} />
          <Route path="/clients" element={withRole(user, handleLogout, ClientsList)} />
          <Route path="/clients/:id" element={withRole(user, handleLogout, ClientDetail)} />
          <Route path="/screening" element={withRole(user, handleLogout, Screening)} />
          <Route path="/ml" element={withRole(user, handleLogout, MlAssistance)} />
          <Route path="/audit" element={withRole(user, handleLogout, AuditLog)} />
          <Route path="/comptes" element={withRole(user, handleLogout, AccountsList)} />
          {/* Figma: /reseau — FE historique: /agences */}
          <Route path="/agences" element={withRole(user, handleLogout, NetworkOverview)} />
          <Route path="/reseau" element={withRole(user, handleLogout, NetworkOverview)} />
          <Route path="/investigations" element={withRole(user, handleLogout, InvestigationsList)} />
          <Route path="/centif" element={withRole(user, handleLogout, CentifDeclarations)} />
          <Route path="/analyse" element={withRole(user, handleLogout, Analyse)} />
          <Route path="/analyse-risque" element={withRole(user, handleLogout, Analyse)} />
          <Route path="/rapports" element={withRole(user, handleLogout, Rapports)} />
          <Route path="/parametres" element={withRole(user, handleLogout, Parametres)} />
        </Route>

        <Route
          path="*"
          element={
            <Navigate
              to={isAuthenticated() ? "/dashboard" : "/login"}
              replace
            />
          }
        />
      </Routes>
    </BrowserRouter>
  );
}

export default App;
