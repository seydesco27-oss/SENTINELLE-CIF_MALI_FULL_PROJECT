import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
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
import AdminUsers from "./pages/AdminUsers";
import { AdminStructures, StructureWizard, StructureAccess } from "./pages/AdminStructures";
import AdminScreeningLists from "./pages/AdminScreeningLists";
import {
  isAuthenticated,
  getStoredUser,
  getMe,
  storeUser,
  logout as apiLogout,
} from "./services/api";
import { canAccess, getDefaultRoute, getRoleId } from "./auth/access";
import "./role-theme.css";

function ProtectedPage({ user, permission, children }) {
  if (!isAuthenticated()) {
    return <Navigate to="/login" replace />;
  }

  if (!canAccess(user, permission)) {
    return <Navigate to={getDefaultRoute(user)} replace />;
  }

  return children;
}

function App() {
  const [user, setUser] = useState(getStoredUser());

  useEffect(() => {
    document.documentElement.dataset.roleTheme = ({
      1: "theme-admin", 2: "theme-co", 3: "theme-supervisor", 4: "theme-agent",
    })[getRoleId(user)] || "theme-default";
  }, [user]);

  useEffect(() => {
    const syncUser = () => setUser(getStoredUser());
    window.addEventListener("sentinelle-auth-changed", syncUser);

    if (isAuthenticated()) {
      getMe()
        .then((response) => {
          if (response?.success && response.data) {
            storeUser(response.data);
            setUser(response.data);
          }
        })
        .catch(() => {
          if (!isAuthenticated()) setUser(null);
        });
    }

    return () => window.removeEventListener("sentinelle-auth-changed", syncUser);
  }, []);

  const handleLogout = async () => {
    await apiLogout();
    setUser(null);
    window.dispatchEvent(new Event("sentinelle-auth-changed"));
  };

  return (
    <BrowserRouter>
      <Routes>
        <Route
          path="/"
          element={
            <Navigate
              to={isAuthenticated() ? getDefaultRoute(user) : "/login"}
              replace
            />
          }
        />
        <Route path="/login" element={<Login />} />

        <Route path="/dashboard" element={<ProtectedPage user={user} permission="nav.dashboard"><Dashboard user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/alertes" element={<ProtectedPage user={user} permission="nav.alerts"><AlertsList user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/alertes/:id" element={<ProtectedPage user={user} permission="alert.view"><AlertDetail user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/transactions" element={<ProtectedPage user={user} permission="nav.transactions"><TransactionsList user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/transactions/:id" element={<ProtectedPage user={user} permission="tx.view"><TransactionDetail user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/clients" element={<ProtectedPage user={user} permission="nav.clients"><ClientsList user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/clients/:id" element={<ProtectedPage user={user} permission="client.view"><ClientDetail user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/screening" element={<ProtectedPage user={user} permission="nav.screening"><Screening user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/ml" element={<ProtectedPage user={user} permission="nav.ml"><MlAssistance user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/audit" element={<ProtectedPage user={user} permission="nav.audit"><AuditLog user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/comptes" element={<ProtectedPage user={user} permission="nav.accounts"><AccountsList user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/agences" element={<ProtectedPage user={user} permission="nav.network"><NetworkOverview user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/reseau" element={<ProtectedPage user={user} permission="nav.network"><NetworkOverview user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/investigations" element={<ProtectedPage user={user} permission="nav.investigations"><InvestigationsList user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/centif" element={<ProtectedPage user={user} permission="nav.centif"><CentifDeclarations user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/analyse" element={<ProtectedPage user={user} permission="nav.analyse"><Analyse user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/analyse-risque" element={<ProtectedPage user={user} permission="nav.analyse"><Analyse user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/rapports" element={<ProtectedPage user={user} permission="nav.reports"><Rapports user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/parametres" element={<ProtectedPage user={user} permission="nav.settings"><Parametres user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/admin/utilisateurs" element={<ProtectedPage user={user} permission="nav.users"><AdminUsers user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/admin/structures" element={<ProtectedPage user={user} permission="org.register"><AdminStructures user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/admin/structures/new" element={<ProtectedPage user={user} permission="org.register"><StructureWizard user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/admin/structures/:id/access" element={<ProtectedPage user={user} permission="org.register"><StructureAccess user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/admin/users" element={<ProtectedPage user={user} permission="user.manage"><AdminUsers user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/admin/screening-lists" element={<ProtectedPage user={user} permission="list.import"><AdminScreeningLists user={user} onLogout={handleLogout} /></ProtectedPage>} />

        {/* M5 : authentifié → dashboard ; sinon → login */}
        <Route
          path="*"
          element={
            <Navigate
              to={isAuthenticated() ? getDefaultRoute(user) : "/login"}
              replace
            />
          }
        />
      </Routes>
    </BrowserRouter>
  );
}

export default App;
