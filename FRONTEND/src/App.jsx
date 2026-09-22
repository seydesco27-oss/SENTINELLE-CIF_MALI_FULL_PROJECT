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
import {
  isAuthenticated,
  getStoredUser,
  getMe,
  storeUser,
  logout as apiLogout,
} from "./services/api";
import { canAccess, getDefaultRoute } from "./auth/access";

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

        <Route path="/dashboard" element={<ProtectedPage user={user} permission="dashboard"><Dashboard user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/alertes" element={<ProtectedPage user={user} permission="alerts"><AlertsList user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/alertes/:id" element={<ProtectedPage user={user} permission="alerts"><AlertDetail user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/transactions" element={<ProtectedPage user={user} permission="transactions"><TransactionsList user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/transactions/:id" element={<ProtectedPage user={user} permission="transactions"><TransactionDetail user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/clients" element={<ProtectedPage user={user} permission="clients"><ClientsList user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/clients/:id" element={<ProtectedPage user={user} permission="clients"><ClientDetail user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/screening" element={<ProtectedPage user={user} permission="screening"><Screening user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/ml" element={<ProtectedPage user={user} permission="ml"><MlAssistance user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/audit" element={<ProtectedPage user={user} permission="audit"><AuditLog user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/comptes" element={<ProtectedPage user={user} permission="accounts"><AccountsList user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/agences" element={<ProtectedPage user={user} permission="network"><NetworkOverview user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/reseau" element={<ProtectedPage user={user} permission="network"><NetworkOverview user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/investigations" element={<ProtectedPage user={user} permission="investigations"><InvestigationsList user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/centif" element={<ProtectedPage user={user} permission="centif"><CentifDeclarations user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/analyse" element={<ProtectedPage user={user} permission="risk_analysis"><Analyse user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/analyse-risque" element={<ProtectedPage user={user} permission="risk_analysis"><Analyse user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/rapports" element={<ProtectedPage user={user} permission="reports"><Rapports user={user} onLogout={handleLogout} /></ProtectedPage>} />
        <Route path="/parametres" element={<ProtectedPage user={user} permission="settings"><Parametres user={user} onLogout={handleLogout} /></ProtectedPage>} />

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
