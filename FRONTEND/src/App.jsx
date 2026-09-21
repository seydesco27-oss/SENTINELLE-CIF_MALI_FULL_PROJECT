import { BrowserRouter, Routes, Route, Navigate, Outlet } from "react-router-dom";
import { useState } from "react";
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
import { isAuthenticated, getStoredUser, logout as apiLogout } from "./services/api";

function ProtectedRoute() {
  if (!isAuthenticated()) {
    return <Navigate to="/login" replace />;
  }
  return <Outlet />;
}

function App() {
  const [user, setUser] = useState(getStoredUser());

  const handleLogout = async () => {
    await apiLogout();
    setUser(null);
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

        <Route element={<ProtectedRoute />}>
          <Route path="/dashboard" element={<Dashboard user={user} onLogout={handleLogout} />} />
          <Route path="/alertes" element={<AlertsList user={user} onLogout={handleLogout} />} />
          <Route path="/alertes/:id" element={<AlertDetail user={user} onLogout={handleLogout} />} />
          <Route path="/transactions" element={<TransactionsList user={user} onLogout={handleLogout} />} />
          <Route path="/transactions/:id" element={<TransactionDetail user={user} onLogout={handleLogout} />} />
          <Route path="/clients" element={<ClientsList user={user} onLogout={handleLogout} />} />
          <Route path="/clients/:id" element={<ClientDetail user={user} onLogout={handleLogout} />} />
          <Route path="/screening" element={<Screening user={user} onLogout={handleLogout} />} />
          <Route path="/ml" element={<MlAssistance user={user} onLogout={handleLogout} />} />
          <Route path="/audit" element={<AuditLog user={user} onLogout={handleLogout} />} />
          <Route path="/comptes" element={<AccountsList user={user} onLogout={handleLogout} />} />
          {/* Figma: /reseau — FE historique: /agences */}
          <Route path="/agences" element={<NetworkOverview user={user} onLogout={handleLogout} />} />
          <Route path="/reseau" element={<NetworkOverview user={user} onLogout={handleLogout} />} />
          <Route path="/investigations" element={<InvestigationsList user={user} onLogout={handleLogout} />} />
          <Route path="/centif" element={<CentifDeclarations user={user} onLogout={handleLogout} />} />
          <Route path="/analyse" element={<Analyse user={user} onLogout={handleLogout} />} />
          <Route path="/analyse-risque" element={<Analyse user={user} onLogout={handleLogout} />} />
          <Route path="/rapports" element={<Rapports user={user} onLogout={handleLogout} />} />
          <Route path="/parametres" element={<Parametres user={user} onLogout={handleLogout} />} />
          <Route path="/centif" element={<CentifDeclarations user={user} onLogout={handleLogout} />} />
        </Route>

        {/* M5 : authentifié → dashboard ; sinon → login */}
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
