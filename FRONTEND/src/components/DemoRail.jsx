import { useLocation, useNavigate } from "react-router-dom";
import "./DemoRail.css";

const STEPS = [
  { id: 1, path: "/dashboard", title: "Supervision conformité", cue: "Repérer l'alerte prioritaire et ouvrir son dossier." },
  { id: 2, path: "/alertes/3001", title: "Alerte prioritaire", cue: "Identifier le client, la transaction et le motif de vigilance." },
  { id: 3, path: "/transactions/2001", title: "Transaction", cue: "Relier l'opération à son contexte institutionnel et client." },
  { id: 4, path: "/transactions/2001?focus=aml", title: "Analyse AML", cue: "Examiner le score déterministe et les règles explicables." },
  { id: 5, path: "/clients/1001", title: "Client 360°", cue: "Comprendre le dossier client, son rattachement et son exposition." },
  { id: 6, path: "/clients/1001?tab=Comptes", title: "Comptes", cue: "Consulter les comptes reliés au même client." },
  { id: 7, path: "/screening", title: "Screening", cue: "Vérifier une correspondance potentielle sans la qualifier automatiquement." },
  { id: 8, path: "/ml", title: "Assistance ML", cue: "Présenter le signal comportemental comme une aide à l'analyse." },
  { id: 9, path: "/transactions/2001?focus=decision", title: "Décision humaine", cue: "Consigner la qualification de l'analyste comme brouillon de démonstration." },
  { id: 10, path: "/audit", title: "Traçabilité", cue: "Montrer qui a fait quoi, quand et sur quel objet." },
];

function withDemo(path, step) {
  return `${path}${path.includes("?") ? "&" : "?"}demo=1&step=${step}`;
}

export default function DemoRail() {
  const navigate = useNavigate();
  const location = useLocation();
  const search = new URLSearchParams(location.search);

  if (search.get("demo") !== "1") return null;

  const index = Math.max(0, Number(search.get("step") || "1") - 1);
  const step = STEPS[index] || STEPS[0];
  const next = STEPS[index + 1];

  return (
    <aside className="demo-rail" aria-label="Parcours de démonstration">
      <div>
        <span>MODE DÉMO</span>
        <strong>{index + 1} / {STEPS.length} · {step.title}</strong>
        <p>{step.cue}</p>
      </div>
      <div className="demo-actions">
        {index > 0 && (
          <button onClick={() => navigate(withDemo(STEPS[index - 1].path, index))}>
            Précédent
          </button>
        )}
        {next ? (
          <button className="primary" onClick={() => navigate(withDemo(next.path, index + 2))}>
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