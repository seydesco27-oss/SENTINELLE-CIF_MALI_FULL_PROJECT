import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { getScreeningLists, getScreeningBatches, importScreeningList, runScreeningBatch } from '../services/api';
import { apiError } from '../services/adminHelpers';

const SOURCES = {
  1: { label: 'ONU', url: 'https://scsanctions.un.org/resources/xml/en/consolidated.xml', formats: 'XML consolidé', portal: 'https://main.un.org/securitycouncil/en/content/un-sc-consolidated-list' },
  2: { label: 'UE', url: 'https://webgate.ec.europa.eu/fsd/fsf/public/files/xmlFullSanctionsList/content', formats: 'XML ou CSV européen', portal: 'https://data.europa.eu/data/datasets/consolidated-list-of-persons-groups-and-entities-subject-to-eu-financial-sanctions' },
  3: { label: 'OFAC', url: 'https://www.treasury.gov/ofac/downloads/sdnlist.txt', formats: 'SDN XML, CSV ou TXT', portal: 'https://sanctionslist.ofac.treas.gov/Home/SdnList' },
};
const date = value => value ? new Date(value).toLocaleString('fr-FR') : '—';

export default function AdminScreeningLists(props) {
  const [params] = useSearchParams();
  const [lists, setLists] = useState([]);
  const [options, setOptions] = useState({ caisses: [], agencies: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [selected, setSelected] = useState('1');
  const [file, setFile] = useState(null);
  const [importing, setImporting] = useState(false);
  const [history, setHistory] = useState([]);
  const [historyLoading, setHistoryLoading] = useState(true);
  const [refresh, setRefresh] = useState(0);
  const [scope, setScope] = useState({ caisse_id: params.get('caisse_id') || '', agency_id: '', batch_size: 50, force: true });
  const [running, setRunning] = useState(false);
  const [result, setResult] = useState(null);
  const fileInput = useRef(null);
  const uploadSection = useRef(null);
  useEffect(() => {
    let active = true;
    getScreeningLists().then(r => { if (active) { setLists(r.data); setOptions(r.options); } })
      .catch(e => { if (active) setError(apiError(e)); }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [refresh]);
  useEffect(() => {
    let active = true;
    getScreeningBatches(selected).then(r => { if (active) setHistory(r.data); })
      .catch(e => { if (active) setError(apiError(e)); }).finally(() => { if (active) setHistoryLoading(false); });
    return () => { active = false; };
  }, [selected, refresh]);
  async function importFile(e) {
    e.preventDefault(); if (!file) return;
    setImporting(true); setError(''); setNotice('');
    try { const r = await importScreeningList(selected, file); setNotice(`${r.message} ${r.data.imported.toLocaleString('fr-FR')} entrées traitées.`); setFile(null); if (fileInput.current) fileInput.current.value = ''; setScope(s => ({ ...s, force: true })); }
    catch (e) { setError(apiError(e)); }
    finally { setImporting(false); setHistoryLoading(true); setRefresh(n => n + 1); }
  }
  async function run(next = false) {
    setRunning(true); setError('');
    try {
      const r = await runScreeningBatch({ ...scope, caisse_id: scope.caisse_id ? Number(scope.caisse_id) : null, agency_id: scope.agency_id ? Number(scope.agency_id) : null, batch_size: Number(scope.batch_size), after_id: next ? result.next_after_id : 0 });
      setResult({ ...r.data, message: r.message, processed_total: (next ? result.processed_total : 0) + r.data.processed });
    } catch (e) { setError(apiError(e)); }
    finally { setRunning(false); }
  }
  function setFilter(key, value) { setScope(s => ({ ...s, [key]: value, ...(key === 'caisse_id' ? { agency_id: '' } : {}) })); setResult(null); }
  const agencies = options.agencies.filter(a => !scope.caisse_id || Number(a.caisse_id) === Number(scope.caisse_id));
  return <AdminLayout {...props}>
    <header className="saas-heading"><div><p className="saas-eyebrow">RÉFÉRENTIELS / CONTRÔLES</p><h1>Listes & Screening</h1><p>Actualisez les référentiels, puis contrôlez les clients du périmètre choisi.</p></div><span className="saas-tag">3 sources officielles</span></header>
    {error && <div className="saas-notice error" role="alert">{error}</div>}
    {notice && <div className="saas-notice success" role="status">{notice}</div>}
    {loading ? <p className="saas-empty">Chargement des listes…</p> : <div className="saas-list-cards">{lists.map(list => <section className="saas-card saas-list-card" key={list.id}><div className="saas-list-card-top"><span className="saas-tag">{SOURCES[list.id].label}</span><span>{list.active_count.toLocaleString('fr-FR')} entrées actives</span></div><h2>{list.name}</h2><p>{list.source_organization}</p><small>Mise à jour : {list.last_update ? new Date(list.last_update).toLocaleDateString('fr-FR') : 'Non renseignée'}</small><div className="saas-actions"><a href={SOURCES[list.id].url} className="saas-button" target="_blank" rel="noopener noreferrer">Source officielle ↗</a><button className="saas-button primary" disabled={importing} onClick={() => { if (selected !== String(list.id)) { setSelected(String(list.id)); setHistoryLoading(true); } uploadSection.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }}>Importer</button></div><a className="saas-source-alternative" href={SOURCES[list.id].portal} target="_blank" rel="noopener noreferrer">Portail de téléchargement alternatif ↗</a></section>)}</div>}
    <div className="saas-screening-grid">
      <section ref={uploadSection} className="saas-card saas-access"><h2>1. Importer une liste</h2><p className="saas-muted">Téléchargez le fichier officiel, puis sélectionnez-le ici. Les entrées reconnues sont ajoutées ou actualisées.</p><form onSubmit={importFile}>
        <label className="saas-field">Liste cible<select disabled={importing} value={selected} onChange={e => { setSelected(e.target.value); setHistoryLoading(true); }}>{lists.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}</select></label>
        <label className="saas-upload">{file ? file.name : 'Choisir le fichier de données'}<small>{SOURCES[selected].formats} · 50 Mo maximum</small><input ref={fileInput} required type="file" accept=".xml,.csv,.txt" disabled={importing} onChange={e => setFile(e.target.files?.[0] || null)} /></label>
        <button className="saas-button primary" disabled={!file || importing || running}>{importing ? 'Import et normalisation…' : 'Importer et publier la liste'}</button>
      </form></section>
      <section className="saas-card saas-access"><h2>2. Lancer le screening</h2><p className="saas-muted">Le contrôle s’applique uniquement aux clients de la caisse ou de l’agence sélectionnée.</p><div className="saas-form-grid">
        <label>Caisse<select disabled={running} value={scope.caisse_id} onChange={e => setFilter('caisse_id', e.target.value)}><option value="">Choisir une caisse</option>{options.caisses.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
        <label>Agence<select disabled={running} value={scope.agency_id} onChange={e => setFilter('agency_id', e.target.value)}><option value="">Toutes celles de la caisse</option>{agencies.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}</select></label>
        <label>Clients par lot<input disabled={running} type="number" min="1" max="500" value={scope.batch_size} onChange={e => setFilter('batch_size', e.target.value)} /></label>
      </div><label className="saas-checkbox"><input disabled={running} type="checkbox" checked={scope.force} onChange={e => setFilter('force', e.target.checked)} />Recontrôler aussi les clients déjà vérifiés aujourd’hui</label>
      <div className="saas-actions"><button className="saas-button primary" disabled={running || importing || (!scope.caisse_id && !scope.agency_id)} onClick={() => run(false)}>{running ? 'Screening en cours…' : 'Lancer screening'}</button>{result?.has_more && <button className="saas-button" disabled={running} onClick={() => run(true)}>Traiter le lot suivant →</button>}</div>
      {result && <div role="status" className="saas-notice success">{result.processed_total} clients contrôlés sur ce lancement. {result.has_more ? 'D’autres clients restent à traiter.' : 'Périmètre parcouru.'}{result.processed_total === 0 && <p>{result.message}</p>}</div>}
      </section>
    </div>
    <section className="saas-card"><div className="saas-toolbar"><h2>Historique · {SOURCES[selected].label}</h2><span className="saas-muted">30 derniers imports</span></div>{historyLoading ? <p className="saas-empty">Chargement de l’historique…</p> : <div className="saas-table-scroll"><table className="saas-table"><thead><tr><th>Fichier</th><th>Date</th><th>État</th><th>Lignes lues</th><th>Entrées importées</th></tr></thead><tbody>{history.map(b => <tr key={b.id}><td><strong>{b.source_file}</strong>{b.import_status === 'FAILED' && <small>{b.notes}</small>}</td><td>{date(b.imported_at || b.retrieved_at)}</td><td><span className={`saas-batch-state state-${b.import_status.toLowerCase()}`}>{b.import_status}</span></td><td>{b.record_count_raw}</td><td>{b.record_count_imported}</td></tr>)}</tbody></table>{history.length === 0 && <p className="saas-empty">Aucun import enregistré pour cette liste.</p>}</div>}</section>
  </AdminLayout>;
}
