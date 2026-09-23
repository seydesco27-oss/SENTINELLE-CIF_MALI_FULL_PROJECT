import { useEffect, useMemo, useState } from "react";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { createAdminUser, getAdminUsers, updateAdminUser } from "../services/api";
import "./AdminUsers.css";

const EMPTY_FORM = {
  username: "", password: "", first_name: "", last_name: "", email: "",
  job_title: "", role_id: "2", agency_id: "", scope_level: "CAISSE", is_active: true,
};

const ROLE_LABELS = {
  ADMIN: "Administrateur", COMPLIANCE_OFFICER: "Conformité",
  SUPERVISOR: "Superviseur", AGENT: "Agent", ACCOUNT_MANAGER: "Gestionnaire",
};

function defaultScope(roleId) {
  return ({ 1: "PLATFORM", 2: "CAISSE", 3: "AGENCY", 4: "AGENCY", 5: "PORTFOLIO" })[Number(roleId)] || "AGENCY";
}

function errorMessage(error) {
  const errors = error.response?.data?.errors;
  if (errors) return Object.values(errors).flat()[0];
  return error.response?.data?.message || "L’opération n’a pas pu être enregistrée.";
}

export default function AdminUsers({ user, onLogout }) {
  const [users, setUsers] = useState([]);
  const [options, setOptions] = useState({ roles: [], agencies: [] });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [search, setSearch] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);

  async function load() {
    setLoading(true);
    setError("");
    try {
      const response = await getAdminUsers();
      setUsers(response?.data || []);
      setOptions(response?.options || { roles: [], agencies: [] });
      setForm((current) => ({
        ...current,
        agency_id: current.agency_id || String(response?.options?.agencies?.[0]?.id || ""),
      }));
    } catch (requestError) {
      setError(errorMessage(requestError));
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    let active = true;
    getAdminUsers()
      .then((response) => {
        if (!active) return;
        setUsers(response?.data || []);
        setOptions(response?.options || { roles: [], agencies: [] });
        setForm((current) => ({
          ...current,
          agency_id: current.agency_id || String(response?.options?.agencies?.[0]?.id || ""),
        }));
      })
      .catch((requestError) => {
        if (active) setError(errorMessage(requestError));
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => { active = false; };
  }, []);

  const filteredUsers = useMemo(() => {
    const needle = search.trim().toLowerCase();
    if (!needle) return users;
    return users.filter((entry) => [entry.username, entry.first_name, entry.last_name, entry.role_name, entry.agency_name, entry.caisse_name]
      .filter(Boolean).some((value) => String(value).toLowerCase().includes(needle)));
  }, [search, users]);

  const setField = (name, value) => setForm((current) => ({ ...current, [name]: value }));

  async function submit(event) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setMessage("");
    try {
      await createAdminUser({
        ...form,
        role_id: Number(form.role_id),
        agency_id: Number(form.role_id) === 1 ? null : Number(form.agency_id),
      });
      setMessage("Compte créé et rattaché à son périmètre.");
      setForm({ ...EMPTY_FORM, agency_id: String(options.agencies?.[0]?.id || "") });
      setShowForm(false);
      await load();
    } catch (requestError) {
      setError(errorMessage(requestError));
    } finally {
      setSaving(false);
    }
  }

  async function toggleActive(entry) {
    setError("");
    setMessage("");
    try {
      await updateAdminUser(entry.id, {
        username: entry.username,
        password: "",
        first_name: entry.first_name || entry.username,
        last_name: entry.last_name || "Compte",
        email: entry.email || null,
        job_title: entry.job_title || null,
        role_id: Number(entry.role_id),
        agency_id: entry.agency_id ? Number(entry.agency_id) : null,
        scope_level: entry.scope_level,
        is_active: !entry.is_active,
      });
      setMessage(entry.is_active ? "Compte désactivé." : "Compte réactivé.");
      await load();
    } catch (requestError) {
      setError(errorMessage(requestError));
    }
  }

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail user={user} />
        <main className="admin-users-page">
          <header className="admin-users-heading">
            <div><p>ADMINISTRATION / ACCÈS</p><h1>Utilisateurs</h1><span>Rôles, rattachements et périmètres de données.</span></div>
            <button type="button" onClick={() => setShowForm((current) => !current)}>{showForm ? "Fermer" : "+ Créer un compte"}</button>
          </header>

          {error && <div className="admin-users-message is-error">{error}</div>}
          {message && <div className="admin-users-message is-success">{message}</div>}

          {showForm && <form className="admin-user-form" onSubmit={submit}>
            <div className="admin-user-form-title"><strong>Nouveau compte</strong><span>L’agence est obligatoire sauf pour un administrateur plateforme.</span></div>
            <label><span>Prénom</span><input required minLength="2" value={form.first_name} onChange={(event) => setField("first_name", event.target.value)} /></label>
            <label><span>Nom</span><input required minLength="2" value={form.last_name} onChange={(event) => setField("last_name", event.target.value)} /></label>
            <label><span>Identifiant</span><input required minLength="3" autoComplete="off" value={form.username} onChange={(event) => setField("username", event.target.value)} /></label>
            <label><span>Mot de passe initial</span><input required minLength="12" type="password" autoComplete="new-password" value={form.password} onChange={(event) => setField("password", event.target.value)} /></label>
            <label><span>Rôle</span><select value={form.role_id} onChange={(event) => { const roleId = event.target.value; setForm((current) => ({ ...current, role_id: roleId, scope_level: defaultScope(roleId) })); }}>{options.roles.map((role) => <option key={role.id} value={role.id}>{ROLE_LABELS[role.name] || role.name}</option>)}</select></label>
            <label><span>Agence</span><select disabled={Number(form.role_id) === 1} required={Number(form.role_id) !== 1} value={Number(form.role_id) === 1 ? "" : form.agency_id} onChange={(event) => setField("agency_id", event.target.value)}><option value="">Sélectionner</option>{options.agencies.map((agency) => <option key={agency.id} value={agency.id}>{agency.caisse_name} · {agency.name}</option>)}</select></label>
            <label><span>Périmètre</span><select value={form.scope_level} onChange={(event) => setField("scope_level", event.target.value)}>{["PLATFORM", "CAISSE", "AGENCY", "PORTFOLIO"].map((scope) => <option key={scope} value={scope}>{scope}</option>)}</select></label>
            <label><span>Fonction</span><input value={form.job_title} onChange={(event) => setField("job_title", event.target.value)} placeholder="Ex. Analyste conformité" /></label>
            <div className="admin-user-form-actions"><button type="button" onClick={() => setShowForm(false)}>Annuler</button><button className="primary" disabled={saving}>{saving ? "Création…" : "Créer le compte"}</button></div>
          </form>}

          <section className="admin-users-panel">
            <div className="admin-users-toolbar"><strong>{users.length} compte{users.length !== 1 ? "s" : ""}</strong><input type="search" placeholder="Rechercher un utilisateur…" value={search} onChange={(event) => setSearch(event.target.value)} /></div>
            {loading ? <div className="admin-users-empty">Chargement…</div> : <div className="admin-users-table-wrap"><table><thead><tr><th>UTILISATEUR</th><th>RÔLE</th><th>RATTACHEMENT</th><th>PÉRIMÈTRE</th><th>STATUT</th><th /></tr></thead><tbody>{filteredUsers.map((entry) => <tr key={entry.id}><td><strong>{[entry.first_name, entry.last_name].filter(Boolean).join(" ") || entry.username}</strong><small>{entry.username}</small></td><td>{ROLE_LABELS[entry.role_name] || entry.role_name}</td><td><strong>{entry.caisse_name || "Plateforme"}</strong><small>{entry.agency_name || "Toutes les structures"}</small></td><td><span className="scope-chip">{entry.scope_level}</span></td><td><span className={`user-status ${entry.is_active ? "is-active" : "is-inactive"}`}>{entry.is_active ? "Actif" : "Inactif"}</span></td><td><button type="button" className="admin-user-toggle" disabled={Number(entry.id) === Number(user?.id)} onClick={() => toggleActive(entry)}>{entry.is_active ? "Désactiver" : "Réactiver"}</button></td></tr>)}</tbody></table>{filteredUsers.length === 0 && <div className="admin-users-empty">Aucun compte ne correspond à la recherche.</div>}</div>}
          </section>
        </main>
      </div>
    </div>
  );
}
