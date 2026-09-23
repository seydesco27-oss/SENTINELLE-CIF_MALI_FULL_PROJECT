import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { createAdminStructure, getAdminStructures, getStructureAccess, provisionStructureApiKey } from '../services/api';
import { apiError, downloadText } from '../services/adminHelpers';

const MODES = {
  API: ['Flux API', 'Pour un SI moderne : clé, endpoint et contrat JSON pour des échanges quasi temps réel.'],
  CSV: ['Fichiers CSV', 'Pour des exports périodiques : trois formats simples, clients, comptes et transactions.'],
  SQL: ['Mapping SQL', 'Pour un dump ou un accès en lecture seule : instructions et vues de référence.'],
};
const PERIMETERS = { REALTIME: 'Nouvelles opérations', BOUNDED: 'Historique délimité', FULL: 'Historique complet', REFUSED: 'Historique non partagé' };
const ENGINES = { aml: 'Règles AML', screening: 'Screening', centif: 'CENTIF', network: 'Réseau', ml: 'Score ML' };
const STEPS = ['Identité', 'Intégration', 'Périmètre & moteurs', 'Utilisateurs initiaux', 'Confirmation'];

export function AdminStructures(props) {
  const [structures, setStructures] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  useEffect(() => {
    let active = true;
    getAdminStructures().then(r => { if (active) setStructures(r.data || []); })
      .catch(e => { if (active) setError(apiError(e)); }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, []);
  const visible = structures.filter(s => `${s.code} ${s.name} ${s.city}`.toLowerCase().includes(search.toLowerCase()));
  return <AdminLayout {...props}>
    <header className="saas-heading"><div><p className="saas-eyebrow">DÉPLOIEMENT / STRUCTURES</p><h1>Un réseau, des espaces dédiés.</h1><p>Inscrivez une caisse et préparez son raccordement à SENTINELLE.</p></div><Link className="saas-button primary" to="/admin/structures/new">+ Inscrire une structure</Link></header>
    <div className="saas-metrics"><div><span>Structures</span><strong>{loading ? '—' : structures.length}</strong></div><div><span>Modes de raccordement</span><strong>API · CSV · SQL</strong></div><div><span>Protection des données</span><strong>Périmètre par caisse</strong></div></div>
    {error && <div className="saas-notice error" role="alert">{error}</div>}
    <section className="saas-card"><div className="saas-toolbar"><h2>Caisses inscrites</h2><input aria-label="Rechercher une structure" type="search" placeholder="Rechercher une caisse…" value={search} onChange={e => setSearch(e.target.value)} /></div>
      {loading ? <p className="saas-empty" role="status">Chargement des structures…</p> : <div className="saas-table-scroll"><table className="saas-table"><thead><tr><th>Structure</th><th>Intégration</th><th>Périmètre</th><th>Inscription</th><th>Actions</th></tr></thead><tbody>
        {visible.map(s => <tr key={s.id}><td><strong>{s.name}</strong><small>{s.code} · {s.city}</small></td><td><span className="saas-tag">{s.integration_mode}</span></td><td>{PERIMETERS[s.data_perimeter]}</td><td>{s.onboarded_at ? new Date(s.onboarded_at).toLocaleDateString('fr-FR') : 'Structure existante'}</td><td><div className="saas-row-actions"><Link to={`/admin/structures/${s.id}/access`}>Accès techniques</Link><Link to={`/admin/users?caisse_id=${s.id}`}>Gérer users</Link><Link to={`/admin/screening-lists?caisse_id=${s.id}`}>Screening batch</Link></div></td></tr>)}
      </tbody></table>{visible.length === 0 && <p className="saas-empty">Aucune structure ne correspond à votre recherche.</p>}</div>}
    </section>
  </AdminLayout>;
}

const initialUser = (role_id, scope_level) => ({ role_id, scope_level, first_name: '', last_name: '', username: '', password: '' });
export function StructureWizard(props) {
  const navigate = useNavigate();
  const [step, setStep] = useState(0);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [data, setData] = useState({ code: '', name: '', city: '', country: 'Mali', agency: { code: '', name: '', city: '' }, integration_mode: 'API', data_perimeter: 'REALTIME', engines: { aml: true, screening: true, centif: true, network: true, ml: true }, sql_connection_hint: '', users: [initialUser(3, 'CAISSE'), initialUser(2, 'AGENCY'), initialUser(4, 'AGENCY')] });
  const set = (field, value) => setData(d => ({ ...d, [field]: value }));
  const setUser = (index, field, value) => setData(d => ({ ...d, users: d.users.map((u, i) => i === index ? { ...u, [field]: value } : u) }));
  async function submit(e) {
    e.preventDefault(); setError('');
    if (step < 4) { setStep(step + 1); return; }
    setSaving(true);
    try { const r = await createAdminStructure(data); navigate(`/admin/structures/${r.data.id}/access`, { replace: true }); }
    catch (e) { setError(apiError(e)); } finally { setSaving(false); }
  }
  return <AdminLayout {...props}>
    <Link className="saas-back" to="/admin/structures">← Structures</Link>
    <header className="saas-heading"><div><p className="saas-eyebrow">NOUVELLE STRUCTURE</p><h1>Préparer le déploiement</h1><p>Une caisse, sa première agence et une équipe prête à se connecter.</p></div><span className="saas-tag">Étape {step + 1} sur 5</span></header>
    <div className="saas-wizard"><ol className="saas-steps">{STEPS.map((label, i) => <li key={label} className={i === step ? 'current' : i < step ? 'done' : ''} aria-current={i === step ? 'step' : undefined}><span>{i < step ? '✓' : i + 1}</span><div>{label}{i === step && <small>En cours</small>}</div></li>)}</ol>
      <form className="saas-card saas-wizard-form" onSubmit={submit}>
        <h2>{STEPS[step]}</h2>{error && <div role="alert" className="saas-notice error">{error}<p>Vous pouvez revenir aux étapes précédentes pour corriger les champs.</p></div>}
        {step === 0 && <><p className="saas-muted">La première agence servira de rattachement initial aux utilisateurs.</p><div className="saas-form-grid">{[['code', 'Code caisse', 20], ['name', 'Nom de la caisse', 150], ['city', 'Ville', 100], ['country', 'Pays', 100]].map(([key, label, max]) => <label key={key}>{label}<input required maxLength={max} pattern={key === 'code' ? '[A-Za-z0-9_-]+' : undefined} value={data[key]} onChange={e => set(key, e.target.value)} /></label>)}</div><h3>Première agence</h3><div className="saas-form-grid">{[['code', 'Code agence', 20], ['name', 'Nom de l’agence', 150], ['city', 'Ville de l’agence', 100]].map(([key, label, max]) => <label key={key}>{label}<input required maxLength={max} pattern={key === 'code' ? '[A-Za-z0-9_-]+' : undefined} value={data.agency[key]} onChange={e => set('agency', { ...data.agency, [key]: e.target.value })} /></label>)}</div></>}
        {step === 1 && <><p className="saas-muted">Choisissez le contrat qui correspond au système d’information de la caisse.</p><div className="saas-mode-grid">{Object.entries(MODES).map(([key, [title, description]]) => <label key={key} className={`saas-mode ${data.integration_mode === key ? 'selected' : ''}`}><input type="radio" name="mode" value={key} checked={data.integration_mode === key} onChange={() => set('integration_mode', key)} /><b>{key}</b><strong>{title}</strong><span>{description}</span></label>)}</div>{data.integration_mode === 'SQL' && <label className="saas-field">Instructions de connexion (facultatif, sans mot de passe)<textarea maxLength={4000} value={data.sql_connection_hint} onChange={e => set('sql_connection_hint', e.target.value)} placeholder="Schéma source, modalités de remise du dump, contact technique…" /></label>}</>}
        {step === 2 && <><label className="saas-field">Périmètre des données<select value={data.data_perimeter} onChange={e => set('data_perimeter', e.target.value)}>{Object.entries(PERIMETERS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label><h3>Moteurs prévus pour la caisse</h3><div className="saas-engine-grid">{Object.entries(ENGINES).map(([key, label]) => <label key={key}><input type="checkbox" checked={data.engines[key]} onChange={e => set('engines', { ...data.engines, [key]: e.target.checked })} />{label}</label>)}</div><p className="saas-muted">Cette sélection définit le périmètre du raccordement. AML et ML conservent leurs scores distincts.</p></>}
        {step === 3 && <><p className="saas-muted">Trois comptes initiaux sont requis. Conservez leurs mots de passe pour les remettre à leurs titulaires.</p>{data.users.map((u, i) => <fieldset className="saas-user-fields" key={i}><legend>{['Administrateur de caisse · Superviseur / CAISSE', 'Responsable conformité', 'Agent · AGENCE'][i]}</legend><div className="saas-form-grid">{[['first_name', 'Prénom'], ['last_name', 'Nom'], ['username', 'Identifiant'], ['password', 'Mot de passe initial (12 caractères min.)']].map(([key, label]) => <label key={key}>{label}<input required minLength={key === 'password' ? 12 : key === 'username' ? 3 : 1} maxLength={key === 'password' ? 255 : 100} type={key === 'password' ? 'password' : 'text'} autoComplete={key === 'password' ? 'new-password' : 'off'} value={u[key]} onChange={e => setUser(i, key, e.target.value)} /></label>)}{i === 1 && <label>Périmètre conformité<select value={u.scope_level} onChange={e => setUser(i, 'scope_level', e.target.value)}><option value="AGENCY">Première agence</option><option value="CAISSE">Toute la caisse</option></select></label>}</div></fieldset>)}</>}
        {step === 4 && <><div className="saas-notice">Vérifiez les informations avant de créer la structure et de générer son contrat de raccordement.</div><dl className="saas-recap"><div><dt>Structure</dt><dd>{data.name} · {data.code}</dd></div><div><dt>Localisation</dt><dd>{data.city}, {data.country}</dd></div><div><dt>Première agence</dt><dd>{data.agency.name} · {data.agency.code}</dd></div><div><dt>Mode</dt><dd>{data.integration_mode} — {MODES[data.integration_mode][0]}</dd></div><div><dt>Données</dt><dd>{PERIMETERS[data.data_perimeter]}</dd></div><div><dt>Moteurs</dt><dd>{Object.entries(data.engines).filter(([, on]) => on).map(([key]) => ENGINES[key]).join(', ') || 'Aucun sélectionné'}</dd></div></dl><h3>Équipe initiale</h3><ul className="saas-simple-list">{data.users.map((u, i) => <li key={i}><strong>{u.first_name} {u.last_name}</strong><span>{u.username} · {['Admin caisse', 'Conformité', 'Agent'][i]} · {u.scope_level}</span></li>)}</ul></>}
        <footer className="saas-form-footer"><button type="button" className="saas-button" disabled={step === 0 || saving} onClick={() => { setStep(step - 1); setError(''); }}>Précédent</button><button className="saas-button primary" disabled={saving}>{saving ? 'Inscription…' : step === 4 ? 'Inscrire et générer les accès' : 'Continuer →'}</button></footer>
      </form>
    </div>
  </AdminLayout>;
}

export function StructureAccess(props) {
  const { id } = useParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [copied, setCopied] = useState('');
  const [visibleKey, setVisibleKey] = useState(false);
  const [provisioning, setProvisioning] = useState(false);
  useEffect(() => { let active = true; getStructureAccess(id).then(r => { if (active) setData(r.data); }).catch(e => { if (active) setError(apiError(e)); }); return () => { active = false; }; }, [id]);
  async function copy(text, label) { try { await navigator.clipboard.writeText(text); setCopied(`${label} copié.`); } catch { setError('La copie automatique est indisponible. Sélectionnez le texte affiché.'); } }
  async function provision() {
    setProvisioning(true); setError('');
    try { await provisionStructureApiKey(id); const response = await getStructureAccess(id); setData(response.data); setCopied('Clé générée. Remettez-la à la caisse par un canal sécurisé.'); }
    catch (e) { setError(apiError(e)); } finally { setProvisioning(false); }
  }
  return <AdminLayout {...props}><Link className="saas-back" to="/admin/structures">← Structures</Link><header className="saas-heading"><div><p className="saas-eyebrow">LIVRABLE DE RACCORDEMENT</p><h1>Accès techniques</h1><p>{data ? `${data.name} · ${data.code}` : 'Chargement du contrat…'}</p></div>{data && <span className="saas-tag">Mode {data.mode}</span>}</header>
    {error && <div className="saas-notice error" role="alert">{error}</div>}{copied && <div className="saas-notice success" role="status">{copied}</div>}
    {data && <><section className="saas-card saas-access"><h2>{MODES[data.mode][0]}</h2><p className="saas-muted">{data.notice}</p>
      {data.mode === 'API' && <><label className="saas-field">Endpoint prévu<code className="saas-code">POST {data.endpoint}</code></label><label className="saas-field">Clé API<input type={visibleKey ? 'text' : 'password'} readOnly value={data.api_key || ''} placeholder="Clé non générée pour cette structure existante" /></label><div className="saas-actions">{!data.api_key && <button className="saas-button primary" disabled={provisioning} onClick={provision}>{provisioning ? 'Génération…' : 'Générer la clé API'}</button>}<button className="saas-button" disabled={!data.api_key} onClick={() => setVisibleKey(!visibleKey)}>{visibleKey ? 'Masquer' : 'Afficher'} la clé</button><button className="saas-button" disabled={!data.api_key} onClick={() => copy(data.api_key, 'Clé')}>Copier clé</button><button className="saas-button" onClick={() => copy(data.endpoint, 'Endpoint')}>Copier endpoint</button><button className="saas-button primary" disabled={!data.api_key} onClick={() => downloadText(`${data.code}-contrat-api.txt`, data.guide)}>Télécharger mini-doc</button></div><h3>En-têtes</h3><pre className="saas-code">{`X-API-Key: ${visibleKey ? data.api_key || 'non générée' : '••••••••••••'}\nX-Caisse-Code: ${data.code}\nContent-Type: application/json`}</pre><h3>Exemple JSON</h3><pre className="saas-code">{JSON.stringify(data.example, null, 2)}</pre></>}
      {data.mode === 'CSV' && <><p>UTF-8 · séparateur <code>;</code> · dates <code>YYYY-MM-DD HH:MM:SS</code></p>{Object.entries(data.files).map(([name, content]) => <div className="saas-file" key={name}><div><h3>{name}</h3><code>{content.split('\n')[0]}</code></div><button className="saas-button" onClick={() => downloadText(name, content, 'text/csv;charset=utf-8')}>Télécharger</button></div>)}<button className="saas-button primary" onClick={() => downloadText(`${data.code}-guide-import.txt`, data.guide)}>Télécharger guide d’import</button></>}
      {data.mode === 'SQL' && <><pre className="saas-code">{data.instructions}</pre><h3>Vues de référence</h3><pre className="saas-code">{data.script}</pre><div className="saas-actions"><button className="saas-button primary" onClick={() => downloadText('sentinelle-mapping.sql', data.script, 'application/sql')}>Télécharger le script de vues</button><button className="saas-button" onClick={() => copy(data.instructions, 'Instructions')}>Copier instructions</button></div></>}
    </section><div className="saas-actions"><Link className="saas-button" to={`/admin/users?caisse_id=${id}`}>Voir les utilisateurs</Link><Link className="saas-button" to={`/admin/screening-lists?caisse_id=${id}`}>Préparer le screening →</Link></div></>}
  </AdminLayout>;
}
