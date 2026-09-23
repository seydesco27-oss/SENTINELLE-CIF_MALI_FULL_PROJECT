import Sidebar from './Sidebar';
import AssistPanel from './AssistPanel';
import { getRoleBadge } from '../config/roles';
import '../pages/AdminSaas.css';

export default function AdminLayout({ user, onLogout, children }) {
  return <div className="app-shell">
    <Sidebar user={user} onLogout={onLogout} />
    <div className="app-main">
      <header className="saas-topbar"><span>Plateforme SENTINELLE <span className="saas-topbar-divider">/</span> Administration</span><span className="role-identity-badge">{getRoleBadge(user)}</span></header>
      <main className="saas-page">{children}</main>
    </div>
    <AssistPanel user={user} objectType="general" title="Assist — administration SaaS" />
  </div>;
}
