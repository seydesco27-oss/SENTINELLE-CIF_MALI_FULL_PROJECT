import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { getClients, createClient, getNetwork } from "../services/api";
import "./ClientsList.css";

const RISK_OPTS = [
  { label: "Tous niveaux", value: "ALL" },
  { label: "Critique", value: "CRITICAL" },
  { label: "Élevé", value: "HIGH" },
  { label: "Modéré", value: "MEDIUM" },
  { label: "Faible", value: "LOW" },
];

const TYPE_OPTS = [
  { label: "Tous types", value: "ALL" },
  { label: "Individus", value: "INDIVIDUAL" },
  { label: "Entités", value: "ENTITY" },
];

function RiskBadge({ level }) {
  if (!level) return <span className="cell-muted">—</span>;
  const key = String(level).toUpperCase();
  const label =
    { CRITICAL: "Critique", HIGH: "Élevé", MEDIUM: "Modéré", LOW: "Faible" }[
      key
    ] || level;
  return (
    <span className={`badge risk-${key.toLowerCase()}`}>
      <i />
      {label}
    </span>
  );
}

function PepBadge({ isPep }) {
  const yes = Number(isPep) === 1 || isPep === true;
  return (
    <span className={"pep-badge" + (yes ? " is-pep" : "")}>
      {yes ? "PEP" : "—"}
    </span>
  );
}

function formatAmount(amount) {
  if (amount == null) return "—";
  const n = Number(amount);
  if (Number.isNaN(n)) return String(amount);
  return n.toLocaleString("fr-FR", { maximumFractionDigits: 0 }) + " FCFA";
}

export default function ClientsList({ user, onLogout }) {
  const navigate = useNavigate();
  const [clients, setClients] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [search, setSearch] = useState("");
  const [riskFilter, setRiskFilter] = useState("ALL");
  const [typeFilter, setTypeFilter] = useState("ALL");
  const [sortDir, setSortDir] = useState("desc");
  const [reloadTick, setReloadTick] = useState(0);

  // ── Création client (KYC minimal) ──
  const [createOpen, setCreateOpen] = useState(false);
  const [agencies, setAgencies] = useState([]);
  const [form, setForm] = useState({
    client_type: "INDIVIDUAL",
    first_name: "",
    last_name: "",
    company_name: "",
    nationality: "",
    phone: "",
    agency_id: "",
    document_type: "CNI",
    document_number: "",
  });
  const [createBusy, setCreateBusy] = useState(false);
  const [createMsg, setCreateMsg] = useState({ type: "", text: "" });

  useEffect(() => {
    if (!createOpen || agencies.length > 0) return;
    getNetwork()
      .then((res) => {
        if (res?.success) setAgencies(res.data?.agencies || []);
      })
      .catch(() => {});
  }, [createOpen, agencies.length]);

  function updateForm(key, value) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function submitCreateClient() {
    const isIndividual = form.client_type === "INDIVIDUAL";
    if (
      (isIndividual && (!form.first_name.trim() || !form.last_name.trim())) ||
      (!isIndividual && !form.company_name.trim()) ||
      !form.nationality.trim() ||
      !form.phone.trim() ||
      !form.agency_id ||
      !form.document_number.trim()
    ) {
      setCreateMsg({ type: "error", text: "Tous les champs marqués sont obligatoires." });
      return;
    }
    setCreateBusy(true);
    setCreateMsg({ type: "", text: "" });
    try {
      const payload = {
        client_type: form.client_type,
        nationality: form.nationality.trim(),
        phone: form.phone.trim(),
        agency_id: Number(form.agency_id),
        document_type: form.document_type,
        document_number: form.document_number.trim(),
      };
      if (isIndividual) {
        payload.first_name = form.first_name.trim();
        payload.last_name = form.last_name.trim();
      } else {
        payload.company_name = form.company_name.trim();
      }
      const res = await createClient(payload);
      if (res?.success) {
        setCreateMsg({
          type: "success",
          text: `Client ${res.data?.client_number || ""} créé.`,
        });
        setForm({
          client_type: "INDIVIDUAL",
          first_name: "",
          last_name: "",
          company_name: "",
          nationality: "",
          phone: "",
          agency_id: "",
          document_type: "CNI",
          document_number: "",
        });
        setReloadTick((t) => t + 1);
      } else {
        setCreateMsg({ type: "error", text: res?.message || "Échec de la création." });
      }
    } catch (err) {
      setCreateMsg({
        type: "error",
        text:
          err.response?.data?.message ||
          err.response?.data?.error ||
          "Échec de la création du client.",
      });
    } finally {
      setCreateBusy(false);
    }
  }

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError("");
      try {
        const params = {
          per_page: 50,
          sort: "risk_score",
          direction: sortDir,
        };
        if (search.trim()) params.search = search.trim();
        if (riskFilter !== "ALL") params.risk_level = riskFilter;
        if (typeFilter !== "ALL") params.client_type = typeFilter;

        const res = await getClients(params);
        if (cancelled) return;

        if (res?.success) {
          setClients(res.data || []);
          setMeta(res.meta || null);
        } else {
          setError(res?.message || "Erreur lors du chargement des clients.");
        }
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de récupérer la liste des clients."
          );
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    const timer = setTimeout(load, search ? 350 : 0);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [search, riskFilter, typeFilter, sortDir, reloadTick]);

  const pepCount = useMemo(
    () => clients.filter((c) => Number(c.is_pep) === 1 || c.is_pep === true).length,
    [clients]
  );

  const criticalCount = useMemo(
    () =>
      clients.filter(
        (c) => String(c.risk_level || "").toUpperCase() === "CRITICAL"
      ).length,
    [clients]
  );

  const highCount = useMemo(
    () =>
      clients.filter((c) =>
        ["HIGH", "CRITICAL"].includes(String(c.risk_level || "").toUpperCase())
      ).length,
    [clients]
  );

  const totalDisplay = meta?.total ?? clients.length;

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />

        <section className="page-frame clients-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">DONNÉES / PORTEFEUILLE CLIENTS</p>
              <h1>Clients</h1>
              <p>
                Vue consolidée du portefeuille client, tous points d’opération
                confondus.
              </p>
            </div>
            <button
              type="button"
              className="cl-new-btn"
              onClick={() => setCreateOpen((v) => !v)}
            >
              {createOpen ? "Fermer" : "+ Nouveau client"}
            </button>
          </header>

          {createOpen && (
            <div className="cl-create-panel">
              <div className="cl-create-title">CRÉATION CLIENT — KYC MINIMAL</div>
              {createMsg.text && (
                <div className={`cl-create-msg cl-create-msg-${createMsg.type}`}>
                  {createMsg.text}
                </div>
              )}
              <div className="cl-create-grid">
                <select
                  value={form.client_type}
                  onChange={(e) => updateForm("client_type", e.target.value)}
                >
                  <option value="INDIVIDUAL">Personne physique</option>
                  <option value="ENTITY">Personne morale</option>
                </select>

                {form.client_type === "INDIVIDUAL" ? (
                  <>
                    <input
                      type="text"
                      placeholder="Prénom *"
                      value={form.first_name}
                      onChange={(e) => updateForm("first_name", e.target.value)}
                    />
                    <input
                      type="text"
                      placeholder="Nom *"
                      value={form.last_name}
                      onChange={(e) => updateForm("last_name", e.target.value)}
                    />
                  </>
                ) : (
                  <input
                    type="text"
                    placeholder="Raison sociale *"
                    value={form.company_name}
                    onChange={(e) => updateForm("company_name", e.target.value)}
                  />
                )}

                <input
                  type="text"
                  placeholder="Nationalité *"
                  value={form.nationality}
                  onChange={(e) => updateForm("nationality", e.target.value)}
                />
                <input
                  type="text"
                  placeholder="Téléphone *"
                  value={form.phone}
                  onChange={(e) => updateForm("phone", e.target.value)}
                />
                <select
                  value={form.agency_id}
                  onChange={(e) => updateForm("agency_id", e.target.value)}
                >
                  <option value="">Agence * …</option>
                  {agencies.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.code ? `${a.code} — ${a.name}` : a.name}
                    </option>
                  ))}
                </select>
                <select
                  value={form.document_type}
                  onChange={(e) => updateForm("document_type", e.target.value)}
                >
                  <option value="CNI">CNI</option>
                  <option value="PASSPORT">Passeport</option>
                  <option value="RCCM">RCCM</option>
                </select>
                <input
                  type="text"
                  placeholder="N° pièce d’identité *"
                  value={form.document_number}
                  onChange={(e) => updateForm("document_number", e.target.value)}
                />
              </div>
              <div className="cl-create-actions">
                <button
                  type="button"
                  className="cl-create-submit"
                  disabled={createBusy}
                  onClick={submitCreateClient}
                >
                  {createBusy ? "Création…" : "Créer le client"}
                </button>
              </div>
            </div>
          )}

          {error && <div className="cl-error">{error}</div>}

          <div className="cl-summary-strip">
            {[
              {
                label: "TOTAL CLIENTS",
                value:
                  typeof totalDisplay === "number"
                    ? totalDisplay.toLocaleString("fr-FR")
                    : totalDisplay,
                note: meta?.total != null ? "Portefeuille (meta API)" : "Page courante",
              },
              {
                label: "RISQUE CRITIQUE",
                value: criticalCount,
                note: "Page courante",
                danger: true,
              },
              {
                label: "RISQUE ÉLEVÉ+",
                value: highCount,
                note: "HIGH + CRITICAL",
                warn: true,
              },
              {
                label: "CLIENTS PEP",
                value: pepCount,
                note: "Page courante",
                purple: true,
              },
            ].map((item) => (
              <div
                key={item.label}
                className={
                  "cl-summary-cell" +
                  (item.danger ? " is-danger" : "") +
                  (item.warn ? " is-warn" : "") +
                  (item.purple ? " is-purple" : "")
                }
              >
                <div className="cl-summary-label">{item.label}</div>
                <div className="cl-summary-value">{item.value}</div>
                <div className="cl-summary-note">{item.note}</div>
              </div>
            ))}
          </div>

          <div className="cl-toolbar">
            <div className="cl-search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="11" cy="11" r="8" />
                <path d="M21 21l-4.35-4.35" />
              </svg>
              <input
                type="text"
                placeholder="Nom, numéro client…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>

            <div className="cl-filter-inline">
              <span>RISQUE</span>
              <select
                value={riskFilter}
                onChange={(e) => setRiskFilter(e.target.value)}
              >
                {RISK_OPTS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>

            <div className="cl-filter-inline">
              <span>TYPE</span>
              <select
                value={typeFilter}
                onChange={(e) => setTypeFilter(e.target.value)}
              >
                {TYPE_OPTS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>

            <div className="cl-filter-inline">
              <span>TRI SCORE</span>
              <select
                value={sortDir}
                onChange={(e) => setSortDir(e.target.value)}
              >
                <option value="desc">Décroissant</option>
                <option value="asc">Croissant</option>
              </select>
            </div>

            <button
              type="button"
              className="cl-reset"
              onClick={() => {
                setSearch("");
                setRiskFilter("ALL");
                setTypeFilter("ALL");
                setSortDir("desc");
              }}
            >
              Réinitialiser
            </button>
          </div>

          <div className="cl-table-panel">
            {loading ? (
              <div className="cl-loading">Chargement des clients…</div>
            ) : (
              <div className="table-scroll">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>CLIENT</th>
                      <th>N°</th>
                      <th>TYPE</th>
                      <th>RISQUE</th>
                      <th>SCORE</th>
                      <th>PEP</th>
                      <th>ALERTES</th>
                      <th>VOLUME</th>
                    </tr>
                  </thead>
                  <tbody>
                    {clients.length === 0 ? (
                      <tr>
                        <td className="empty-cell" colSpan={8}>
                          Aucun client pour ce périmètre.
                        </td>
                      </tr>
                    ) : (
                      clients.map((c) => {
                        const id = c.client_id || c.id;
                        return (
                          <tr
                            key={id}
                            onClick={() => navigate(`/clients/${id}`)}
                          >
                            <td>
                              <strong>
                                {c.customer_name ||
                                  c.primary_name ||
                                  "—"}
                              </strong>
                            </td>
                            <td className="mono">
                              {c.client_number || "—"}
                            </td>
                            <td>
                              <span
                                className={
                                  "type-chip type-" +
                                  String(c.client_type || "")
                                    .toLowerCase()
                                    .replace(/[^a-z]/g, "")
                                }
                              >
                                {c.client_type === "INDIVIDUAL"
                                  ? "Individu"
                                  : c.client_type === "ENTITY"
                                    ? "Entité"
                                    : c.client_type || "—"}
                              </span>
                            </td>
                            <td>
                              <RiskBadge level={c.risk_level} />
                            </td>
                            <td className="mono">
                              {c.risk_score != null
                                ? Number(c.risk_score).toFixed(0)
                                : "—"}
                            </td>
                            <td>
                              <PepBadge isPep={c.is_pep} />
                            </td>
                            <td className="mono">
                              {c.alert_count != null ? c.alert_count : "—"}
                            </td>
                            <td className="mono">
                              {formatAmount(c.total_volume)}
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>
            )}
            <div className="cl-footer-note">
              {clients.length} affiché
              {clients.length !== 1 ? "s" : ""}
              {meta?.total != null
                ? ` · ${meta.total.toLocaleString("fr-FR")} au total`
                : ""}
              {meta?.current_page != null
                ? ` · page ${meta.current_page}${
                    meta.last_page != null ? `/${meta.last_page}` : ""
                  }`
                : ""}{" "}
              · Source API /clients · Tri score {sortDir}
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
