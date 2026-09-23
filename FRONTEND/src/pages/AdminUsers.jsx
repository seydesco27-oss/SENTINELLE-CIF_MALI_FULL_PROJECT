import { useEffect, useMemo, useState } from "react";
import AdminLayout from "../components/AdminLayout";
import { useSearchParams } from "react-router-dom";
import { createAdminUser, getAdminUsers, updateAdminUser } from "../services/api";
import "./AdminUsers.css";

const EMPTY_FORM = {
  username: "", password: "", first_name: "", last_name: "", email: "",
  job_title: "", role_id: "2", agency_id: "", caisse_id: "", scope_level: "CAISSE", is_active: true,
};

const ROLE_LABELS = {
  ADMIN: "ADMIN SaaS", COMPLIANCE_OFFICER: "Conformité",
  SUPERVISOR: "Superviseur", AGENT: "Agent",
};

function defaultScope(roleId) {
  return ({ 1: "PLATFORM", 2: "CAISSE", 3: "AGENCY", 4: "AGENCY" })[Number(roleId)] || "AGENCY";
}

function errorMessage(error) {
  const errors = error.response?.data?.errors;
  if (errors) return Object.values(errors).flat()[0];
  return error.response?.data?.message || "L’opération n’a pas pu être enregistrée.";
}

export default function AdminUsers({ user, onLogout }) {
  const [params] = useSearchParams();
  const [caisseFilter, setCaisseFilter] = useState(params.get("caisse_id") || "");
  const [editingId, setEditingId] = useState(null);
  const [users, setUsers] = useState([]);
  const [options, setOptions] = useState({ roles: [], agencies: [], caisses: [] });
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
      setOptions(response?.options || { roles: [], agencies: [], caisses: [] });
      setForm((current) => ({
        ...current,
        caisse_id: current.caisse_id || params.get("caisse_id") || "",
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
        setOptions(response?.options || { roles: [], agencies: [], caisses: [] });
        setForm((current) => ({
          ...current,
          caisse_id: current.caisse_id || params.get("caisse_id") || "",
        }));
      })
      .catch((requestError) => {
        if (active) setError(errorMessage(requestError));
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => { active = false; };
  }, [params]);

  const filteredUsers = useMemo(() => {
    const needle = search.trim().toLowerCase();
    const scoped = users.filter(entry => !caisseFilter || Number(entry.caisse_id) === Number(caisseFilter));
    if (!needle) return scoped;
    return scoped.filter((entry) => [entry.username, entry.first_name, entry.last_name, entry.role_name, entry.agency_name, entry.caisse_name]
      .filter(Boolean).some((value) => String(value).toLowerCase().includes(needle)));
  }, [search, users, caisseFilter]);

  const setField = (name, value) => setForm((current) => ({ ...current, [name]: value }));

  async function submit(event) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setMessage("");
    try {
      const payload = {
        ...form,
        role_id: Number(form.role_id),
        agency_id: Number(form.role_id) === 1 ? null : Number(form.agency_id) || null,
        caisse_id: Number(form.role_id) === 1 ? null : Number(form.caisse_id) || null,
      };
      if (editingId) await updateAdminUser(editingId, payload); else await createAdminUser(payload);
      setMessage(editingId ? "Compte mis à jour." : "Compte créé et rattaché à son périmètre.");
      setForm({ ...EMPTY_FORM, caisse_id: caisseFilter });
      setEditingId(null);
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
        caisse_id: entry.caisse_id,
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
    <AdminLayout user={user} onLogout={onLogout}>
        <div className="admin-users-page">
          <header className="admin-users-heading">
            <div><p>ADMINISTRATION / ACCÈS</p><h1>Utilisateurs</h1><span>Rôles, rattachements et périmètres de données.</span></div>
            <button type="button" onClick={() => { setShowForm(!showForm); setEditingId(null); setForm({ ...EMPTY_FORM, caisse_id: caisseFilter }); }}>{showForm ? "Fermer" : "+ Créer un compte"}</button>
          </header>

          {error && <div className="admin-users-message is-error">{error}</div>}
          {message && <div className="admin-users-message is-success">{message}</div>}

          {showForm && <form className="admin-user-form" onSubmit={submit}>
            <div className="admin-user-form-title"><strong>{editingId ? "Modifier le compte" : "Nouveau compte"}</strong><span>Superviseur + périmètre CAISSE = administrateur de caisse. Agent = agence obligatoire.</span></div>
            <label><span>Prénom</span><input required minLength="2" value={form.first_name} onChange={(event) => setField("first_name", event.target.value)} /></label>
            <label><span>Nom</span><input required minLength="2" value={form.last_name} onChange={(event) => setField("last_name", event.target.value)} /></label>
            <label><span>Identifiant</span><input required minLength="3" autoComplete="off" value={form.username} onChange={(event) => setField("username", event.target.value)} /></label>
            <label><span>{editingId ? "Nouveau mot de passe (facultatif)" : "Mot de passe initial"}</span><input required={!editingId} minLength="12" type="password" autoComplete="new-password" value={form.password} onChange={(event) => setField("password", event.target.value)} /></label>
            <label><span>Rôle</span><select value={form.role_id} onChange={(event) => { const roleId = event.target.value; setForm((current) => ({ ...current, role_id: roleId, scope_level: defaultScope(roleId), ...(Number(roleId) === 1 ? { caisse_id: '', agency_id: '' } : {}) })); }}>{options.roles.map((role) => <option key={role.id} value={role.id}>{ROLE_LABELS[role.name] || role.name}</option>)}</select></label>
            <label><span>Caisse</span><select disabled={Number(form.role_id) === 1} required={Number(form.role_id) !== 1} value={form.caisse_id} onChange={e => setForm(f => ({ ...f, caisse_id: e.target.value, agency_id: "" }))}><option value="">Sélectionner</option>{options.caisses.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
            <label><span>Agence {form.scope_level === "CAISSE" ? "(facultatif)" : ""}</span><select disabled={Number(form.role_id) === 1} required={form.scope_level === "AGENCY"} value={form.agency_id} onChange={e => setField("agency_id", e.target.value)}><option value="">Sélectionner</option>{options.agencies.filter(a => Number(a.caisse_id) === Number(form.caisse_id)).map(a => <option key={a.id} value={a.id}>{a.name}</option>)}</select></label>
            <label><span>Périmètre</span><select value={form.scope_level} onChange={(event) => setField("scope_level", event.target.value)}>{(Number(form.role_id) === 1 ? ["PLATFORM"] : Number(form.role_id) === 4 ? ["AGENCY"] : ["CAISSE", "AGENCY"]).map((scope) => <option key={scope} value={scope}>{scope}</option>)}</select></label>
            <label><span>Fonction</span><input value={form.job_title} onChange={(event) => setField("job_title", event.target.value)} placeholder="Ex. Analyste conformité" /></label>
            <div className="admin-user-form-actions"><button type="button" onClick={() => setShowForm(false)}>Annuler</button><button className="primary" disabled={saving}>{saving ? "Enregistrement…" : editingId ? "Enregistrer" : "Créer le compte"}</button></div>
          </form>}

          <section className="admin-users-panel">
            <div className="admin-users-toolbar"><label>Caisse <select value={caisseFilter} onChange={e => setCaisseFilter(e.target.value)}><option value="">Toutes les caisses</option>{options.caisses.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label><strong>{filteredUsers.length} compte{users.length !== 1 ? "s" : ""}</strong><input type="search" placeholder="Rechercher un utilisateur…" value={search} onChange={(event) => setSearch(event.target.value)} /></div>
            {loading ? <div className="admin-users-empty">Chargement…</div> : <div className="admin-users-table-wrap"><table><thead><tr><th>UTILISATEUR</th><th>RÔLE</th><th>RATTACHEMENT</th><th>PÉRIMÈTRE</th><th>STATUT</th><th /></tr></thead><tbody>{filteredUsers.map((entry) => <tr key={entry.id}><td><strong>{[entry.first_name, entry.last_name].filter(Boolean).join(" ") || entry.username}</strong><small>{entry.username}</small></td><td>{Number(entry.role_id) === 3 && entry.scope_level === "CAISSE" ? "Admin caisse" : ROLE_LABELS[entry.role_name] || entry.role_name}</td><td><strong>{entry.caisse_name || "Plateforme"}</strong><small>{entry.agency_name || "Toutes les structures"}</small></td><td><span className="scope-chip">{entry.scope_level}</span></td><td><span className={`user-status ${entry.is_active ? "is-active" : "is-inactive"}`}>{entry.is_active ? "Actif" : "Inactif"}</span></td><td><button type="button" className="admin-user-toggle" onClick={() => { setEditingId(entry.id); setForm({ ...EMPTY_FORM, ...entry, password: "", agency_id: entry.agency_id || "", caisse_id: entry.caisse_id || "" }); setShowForm(true); }}>Modifier</button> <button type="button" className="admin-user-toggle" disabled={Number(entry.id) === Number(user?.id)} onClick={() => toggleActive(entry)}>{entry.is_active ? "Désactiver" : "Réactiver"}</button></td></tr>)}</tbody></table>{filteredUsers.length === 0 && <div className="admin-users-empty">Aucun compte ne correspond à la recherche.</div>}</div>}
          </section>
        </div>
    </AdminLayout>
  );
}
