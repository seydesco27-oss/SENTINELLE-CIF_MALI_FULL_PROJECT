import { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { canAccess } from "../auth/access";
import { getAlerts, getStoredUser } from "../services/api";
import "./DemoRail.css";

function withDemo(path, step) {
  return `${path}${path.includes("?") ? "&" : "?"}demo=1&step=${step}`;
}

function buildSteps(context) {
  const base = [
    {
      path: "/dashboard",
      title: "Supervision conformité",
      cue: "Repérer les priorités et ouvrir un dossier réel du périmètre.",
    },
  ];

  if (context) {
    base.push(
      {
        path: `/alertes/${context.alert_id}`,
        title: "Alerte prioritaire",
        cue: "Identifier le client, la transaction et le motif de vigilance.",
      },
      {
        path: `/transactions/${context.transaction_id}`,
        title: "Transaction",
        cue: "Relier l’opération à son contexte institutionnel et client.",
      },
      {
        path: `/transactions/${context.transaction_id}?focus=aml`,
        title: "Analyse AML",
        cue: "Examiner le score déterministe et les règles explicables.",
      },
      {
        path: `/clients/${context.client_id}`,
        title: "Client 360°",
        cue: "Comprendre le dossier client, son rattachement et son exposition.",
      },
      {
        path: `/clients/${context.client_id}?tab=Comptes`,
        title: "Comptes",
        cue: "Consulter les comptes reliés au même client.",
      }
    );
  }

  return [
    ...base,
    {
      path: "/screening",
      title: "Screening",
      cue: "Vérifier une correspondance potentielle sans la qualifier automatiquement.",
    },
    {
      path: "/ml",
      title: "Assistance ML",
      cue: "Comparer le signal ML au score AML, sans les fusionner.",
    },
    ...(context
      ? [
          {
            path: `/transactions/${context.transaction_id}?focus=decision`,
            title: "Décision humaine",
            cue: "Consigner la qualification de l’analyste et sa justification.",
          },
        ]
      : []),
    {
      path: "/audit",
      title: "Traçabilité",
      cue: "Montrer qui a fait quoi, quand et sur quel objet.",
    },
  ].map((step, index) => ({ ...step, id: index + 1 }));
}

export default function DemoRail({ user: userProp = null }) {
  const navigate = useNavigate();
  const location = useLocation();
  const search = new URLSearchParams(location.search);
  const enabled = search.get("demo") === "1";
  const user = userProp || getStoredUser();
  const allowed = canAccess(user, "demo.run");
  const [context, setContext] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!enabled || !allowed) return undefined;
    let cancelled = false;
    getAlerts({ status: "OPEN", limit: 100 })
      .then((response) => {
        if (cancelled) return;
        const candidate = (response?.data || []).find(
          (alert) => alert.id && alert.client_id && alert.transaction_id
        );
        if (candidate) {
          setContext({
            alert_id: Number(candidate.id),
            client_id: Number(candidate.client_id),
            transaction_id: Number(candidate.transaction_id),
          });
        }
      })
      .catch(() => setContext(null))
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [allowed, enabled]);

  const steps = useMemo(() => buildSteps(context), [context]);

  if (!enabled || !allowed) return null;

  const requestedIndex = Math.max(0, Number(search.get("step") || "1") - 1);
  const index = Math.min(requestedIndex, steps.length - 1);
  const step = steps[index] || steps[0];
  const next = steps[index + 1];

  return (
    <aside className="demo-rail" aria-label="Parcours de démonstration">
      <div>
        <span>MODE DÉMO</span>
        <strong>{index + 1} / {steps.length} · {step.title}</strong>
        <p>{loading ? "Sélection d’un dossier réel du périmètre…" : step.cue}</p>
      </div>
      <div className="demo-actions">
        {index > 0 && (
          <button onClick={() => navigate(withDemo(steps[index - 1].path, index))}>
            Précédent
          </button>
        )}
        {next ? (
          <button
            className="primary"
            disabled={loading && index === 0}
            onClick={() => navigate(withDemo(next.path, index + 2))}
          >
            Étape suivante →
          </button>
        ) : (
          <button className="primary" onClick={() => navigate("/dashboard")}>
            Terminer
          </button>
        )}
        <button className="close" onClick={() => navigate(location.pathname)}>
          Quitter
        </button>
      </div>
    </aside>
  );
}
