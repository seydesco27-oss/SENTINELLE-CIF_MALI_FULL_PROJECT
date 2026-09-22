import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import {
  getScreeningRegistry,
  getClientScreening,
  getClientPep,
} from "../services/api";
import "./Screening.css";

function statusLabel(status, matchFound) {
  const s = String(status || "").toUpperCase();
  if (s.includes("ABSENCE") || s === "CLEAR" || s === "NO_MATCH")
    return "Absence de correspondance";
  if (
    s.includes("A_VERIFIER") ||
    s.includes("POTENTIEL") ||
    s.includes("MATCH") ||
    s.includes("CORRESPONDANCE") ||
    Number(matchFound) === 1
  )
    return "À vérifier";
  return status || "—";
}

function formatDateTime(value) {
  if (!value) return "—";
  try {
    return new Date(value).toLocaleString("fr-FR", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  } catch {
    return String(value);
  }
}

export default function Screening({ user, onLogout }) {
  const navigate = useNavigate();
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("ALL");
  const [registry, setRegistry] = useState([]);
  const [selectedClientId, setSelectedClientId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [pepMatches, setPepMatches] = useState([]);
  const [loadingList, setLoadingList] = useState(true);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [error, setError] = useState("");
  const [qualification, setQualification] = useState("");

  /* eslint-disable react-hooks/set-state-in-effect */
  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoadingList(true);
      setError("");
      try {
        const params = { limit: 80 };
        if (search.trim()) params.search = search.trim();
        if (statusFilter !== "ALL") params.status = statusFilter;

        const res = await getScreeningRegistry(params);
        if (cancelled) return;

        if (res?.success) {
          const rows = res.data || [];
          setRegistry(rows);
          if (!selectedClientId && rows.length > 0) {
            setSelectedClientId(rows[0].client_id);
          }
        } else {
          setError(res?.message || "Impossible de charger le registre.");
        }
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Erreur lors du chargement du screening."
          );
        }
      } finally {
        if (!cancelled) setLoadingList(false);
      }
    }

    const timer = setTimeout(load, search ? 350 : 0);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, statusFilter]);

  useEffect(() => {
    if (!selectedClientId) {
      setDetail(null);
      setPepMatches([]);
      return;
    }
    let cancelled = false;
    async function loadDetail() {
      setLoadingDetail(true);
      setQualification("");
      try {
        const [scrRes, pepRes] = await Promise.all([
          getClientScreening(selectedClientId),
          getClientPep(selectedClientId),
        ]);
        if (cancelled) return;
        if (scrRes?.success) setDetail(scrRes.data);
        else setDetail(null);
        if (pepRes?.success) setPepMatches(pepRes.data || []);
        else setPepMatches([]);
      } catch {
        if (!cancelled) {
          setDetail(null);
          setPepMatches([]);
        }
      } finally {
        if (!cancelled) setLoadingDetail(false);
      }
    }
    loadDetail();
    return () => {
      cancelled = true;
    };
  }, [selectedClientId]);
  /* eslint-enable react-hooks/set-state-in-effect */

  const selectedRow = registry.find(
    (r) => String(r.client_id) === String(selectedClientId)
  );

  const summary = detail?.summary || null;
  const sanctionMatches =
    detail?.sanction_matches || detail?.sanctions || [];
  const screenings = detail?.screenings || [];

  const clientName =
    selectedRow?.customer_name ||
    selectedRow?.primary_name ||
    detail?.client?.customer_name ||
    detail?.client?.primary_name ||
    (selectedClientId ? `Client #${selectedClientId}` : "—");

  const controlStatus = statusLabel(
    selectedRow?.status || summary?.final_screening_status,
    selectedRow?.match_found ??
      (pepMatches.length > 0 || sanctionMatches.length > 0 ? 1 : 0)
  );

  const needsReview = controlStatus === "À vérifier";
  const pepStatus =
    pepMatches.length > 0
      ? "Correspondance potentielle"
      : "Absence de correspondance";
  const sanctionsStatus =
    sanctionMatches.length > 0
      ? "Correspondance potentielle"
      : "Absence de correspondance";

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />

        <section className="page-frame screening-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">CONFORMITÉ / SCREENING CLIENT</p>
              <h1>Screening PEP et sanctions</h1>
              <p>
                Résultats de filtrage à confronter aux éléments d’identité du
                client avant toute qualification.
              </p>
            </div>
          </header>

          <div className="screening-guidance">
            <strong>Principe de vigilance</strong>
            <p>
              Une correspondance est un élément de vérification, non une
              confirmation. L’analyste compare les données du dossier client,
              les sources consultées et les éléments de liste avant de
              consigner sa conclusion.
            </p>
          </div>

          {error && <div className="screening-error">{error}</div>}

          <div className="screening-layout">
            {/* REGISTRE */}
            <aside className="screening-register">
              <header>
                <strong>REGISTRE DES CONTRÔLES</strong>
                <span>
                  {registry.length} résultat
                  {registry.length !== 1 ? "s" : ""}
                </span>
              </header>

              <div className="screening-search">
                <input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Client ou ID…"
                />
                <select
                  value={statusFilter}
                  onChange={(e) => setStatusFilter(e.target.value)}
                >
                  <option value="ALL">Tous statuts</option>
                  <option value="REVIEW">À vérifier</option>
                  <option value="CLEAR">Absence de correspondance</option>
                </select>
              </div>

              <div className="screening-list">
                {loadingList ? (
                  <div className="screening-empty">Chargement…</div>
                ) : registry.length === 0 ? (
                  <div className="screening-empty">Aucun résultat</div>
                ) : (
                  registry.map((row) => {
                    const label = statusLabel(row.status, row.match_found);
                    const active =
                      String(row.client_id) === String(selectedClientId);
                    return (
                      <button
                        key={row.client_id || row.id}
                        type="button"
                        className={active ? "selected" : ""}
                        onClick={() => setSelectedClientId(row.client_id)}
                      >
                        <strong>
                          {row.customer_name ||
                            row.primary_name ||
                            `Client #${row.client_id}`}
                        </strong>
                        <span>
                          {row.client_number || `ID-${row.client_id}`}
                        </span>
                        <small
                          className={
                            label === "À vérifier" ? "review" : "clear"
                          }
                        >
                          {label}
                        </small>
                      </button>
                    );
                  })
                )}
              </div>
            </aside>

            {/* DOSSIER */}
            <main className="screening-dossier">
              {!selectedClientId ? (
                <div className="screening-empty">
                  Sélectionnez un contrôle dans le registre.
                </div>
              ) : loadingDetail ? (
                <div className="screening-empty">
                  Chargement du dossier de contrôle…
                </div>
              ) : (
                <>
                  <header className="screening-client-head">
                    <div>
                      <p className="eyebrow">DOSSIER DE CONTRÔLE</p>
                      <h2>{clientName}</h2>
                      <span className="mono">
                        {selectedRow?.client_number ||
                          detail?.client?.client_number ||
                          `Client ID-${selectedClientId}`}
                      </span>
                    </div>
                    <button
                      type="button"
                      className="text-link"
                      onClick={() =>
                        navigate(`/clients/${selectedClientId}`)
                      }
                    >
                      Ouvrir le profil client →
                    </button>
                  </header>

                  <div className="screening-summary">
                    <div>
                      <span>STATUT DU CONTRÔLE</span>
                      <strong
                        className={needsReview ? "is-review" : "is-clear"}
                      >
                        {controlStatus}
                      </strong>
                    </div>
                    <div>
                      <span>DERNIER SCREENING</span>
                      <strong>
                        {formatDateTime(
                          selectedRow?.screening_date ||
                            selectedRow?.last_screened_at ||
                            screenings[0]?.screening_date
                        )}
                      </strong>
                    </div>
                    <div>
                      <span>PEP</span>
                      <strong>{pepStatus}</strong>
                    </div>
                    <div>
                      <span>SANCTIONS</span>
                      <strong>{sanctionsStatus}</strong>
                    </div>
                  </div>

                  <section className="dossier-section">
                    <header className="dossier-section-title">
                      <span>01</span>
                      <div>
                        <h2>Contrôle PEP</h2>
                        <p>
                          Éléments de comparaison disponibles dans le résultat
                          de screening.
                        </p>
                      </div>
                    </header>
                    {pepMatches.length === 0 ? (
                      <article className="data-panel">
                        <p className="muted">
                          Aucune correspondance PEP retournée pour ce client.
                        </p>
                      </article>
                    ) : (
                      <article className="data-panel">
                        <div className="table-scroll">
                          <table className="data-table">
                            <thead>
                              <tr>
                                <th>NOM LISTE</th>
                                <th>FONCTION / CATÉGORIE</th>
                                <th>PAYS</th>
                                <th>SCORE</th>
                              </tr>
                            </thead>
                            <tbody>
                              {pepMatches.map((m, i) => (
                                <tr key={m.id || i}>
                                  <td>
                                    {m.listed_name ||
                                      m.name ||
                                      m.pep_name ||
                                      "—"}
                                  </td>
                                  <td>
                                    {m.position ||
                                      m.category ||
                                      m.role ||
                                      "—"}
                                  </td>
                                  <td>
                                    {m.country || m.country_code || "—"}
                                  </td>
                                  <td className="mono">
                                    {m.match_score != null
                                      ? Number(m.match_score).toFixed(0)
                                      : m.confidence != null
                                        ? Number(m.confidence).toFixed(0)
                                        : "—"}
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      </article>
                    )}
                  </section>

                  <section className="dossier-section">
                    <header className="dossier-section-title">
                      <span>02</span>
                      <div>
                        <h2>Contrôle sanctions</h2>
                        <p>
                          Correspondances potentielles issues des listes
                          consultées.
                        </p>
                      </div>
                    </header>
                    {sanctionMatches.length === 0 ? (
                      <article className="data-panel">
                        <p className="muted">
                          Aucune correspondance sanctions retournée.
                        </p>
                      </article>
                    ) : (
                      <article className="data-panel">
                        <div className="table-scroll">
                          <table className="data-table">
                            <thead>
                              <tr>
                                <th>ENTITÉ LISTE</th>
                                <th>LISTE / SOURCE</th>
                                <th>PAYS</th>
                                <th>SCORE</th>
                              </tr>
                            </thead>
                            <tbody>
                              {sanctionMatches.map((m, i) => (
                                <tr key={m.id || i}>
                                  <td>
                                    {m.listed_name ||
                                      m.name ||
                                      m.entity_name ||
                                      "—"}
                                  </td>
                                  <td>
                                    {m.list_name ||
                                      m.source ||
                                      m.list_code ||
                                      "—"}
                                  </td>
                                  <td>
                                    {m.country || m.country_code || "—"}
                                  </td>
                                  <td className="mono">
                                    {m.match_score != null
                                      ? Number(m.match_score).toFixed(0)
                                      : m.confidence != null
                                        ? Number(m.confidence).toFixed(0)
                                        : "—"}
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      </article>
                    )}
                  </section>

                  <section className="dossier-section">
                    <header className="dossier-section-title">
                      <span>03</span>
                      <div>
                        <h2>Qualification de l’analyste</h2>
                        <p>
                          Brouillon local de démonstration — aucune écriture
                          API dans cette V1.
                        </p>
                      </div>
                    </header>
                    <div className="qualification-box">
                      <label>
                        Conclusion
                        <select
                          value={qualification}
                          onChange={(e) => setQualification(e.target.value)}
                        >
                          <option value="">À renseigner</option>
                          <option value="clear">
                            Absence de correspondance après vérification
                          </option>
                          <option value="confirmed">
                            Correspondance confirmée
                          </option>
                          <option value="review">
                            Poursuivre les vérifications
                          </option>
                        </select>
                      </label>
                      <small>
                        {qualification
                          ? "Brouillon local — aucune décision n’est enregistrée dans l’API."
                          : "Aucune qualification saisie."}
                      </small>
                    </div>
                  </section>
                </>
              )}
            </main>
          </div>
        </section>
      </div>
    </div>
  );
}
