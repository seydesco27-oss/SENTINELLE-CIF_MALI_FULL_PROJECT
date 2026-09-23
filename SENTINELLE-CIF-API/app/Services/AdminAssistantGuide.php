<?php

namespace App\Services;

final class AdminAssistantGuide
{
    public static function answer(string $message): string
    {
        return match (true) {
            preg_match('/listes?|sanctions?|ofac|onu|screening|\bue\b/ui', $message) === 1 => "### Listes et screening\n1. Ouvrez Listes & Screening et téléchargez le fichier depuis la source officielle ONU, UE ou OFAC.\n2. Choisissez la liste correspondante, importez le fichier, puis vérifiez le statut SUCCESS dans l’historique.\n3. **Après la mise à jour, lancez un screening batch** : sélectionnez une caisse ou une agence, cochez le recalcul après import, puis lancez le traitement. Si un lot suivant est proposé, poursuivez jusqu’à la fin.\n\nL’import actualise les listes ; il ne signifie pas que les clients ont déjà été contrôlés.",
            preg_match('/utilisateurs?|supervis|\bco\b|agent|rôles?|roles?/ui', $message) === 1 => "### Utilisateurs initiaux\n- **Admin caisse :** SUPERVISOR au périmètre CAISSE, rattaché à la caisse inscrite.\n- **Conformité :** COMPLIANCE_OFFICER au périmètre CAISSE ou AGENCY selon son mandat.\n- **Agent :** AGENT au périmètre AGENCY strict, rattaché à une agence de cette caisse.\n\nCréez au moins ces trois comptes dans le wizard Structures. Les rattachements se modifient ensuite dans Utilisateurs. ADMIN est réservé à la plateforme SaaS ; aucun rôle ADMIN_CAISSE distinct n’est nécessaire.",
            preg_match('/accès|acces|clé|cle|endpoint|headers?|remettre|connecteur/ui', $message) === 1 => "### Remettre le connecteur\nOuvrez Structures, puis **Voir accès techniques** pour la caisse concernée.\n- API : copiez la clé et l’endpoint, puis téléchargez la mini-documentation JSON avec les headers X-API-Key et X-Caisse-Code.\n- CSV : téléchargez clients.csv, accounts.csv, transactions.csv et le guide d’import.\n- SQL : remettez les instructions de lecture seule et le script des vues de mapping.\n\nLe MVP fournit le contrat d’intégration. La passerelle d’ingestion temps réel et l’import SQL complet ne sont pas encore actifs. Remettez la clé par un canal sécurisé ; ne la collez pas dans cette conversation.",
            preg_match('/intégr|integr|\bapi\b|\bcsv\b|\bsql\b|inscri|déplo|deplo|mode/ui', $message) === 1 => "### Choisir le mode d’intégration\n- **API :** SI capable d’envoyer des flux JSON, avec des mises à jour fréquentes.\n- **CSV :** exports périodiques de clients, comptes et transactions ; séparateur point-virgule, UTF-8.\n- **SQL :** dump des tables sources ou accès en lecture seule, avec mapping vers le référentiel SENTINELLE.\n\nDans Structures → Inscrire une structure : renseignez la caisse et sa première agence, choisissez le mode, le périmètre et les moteurs, puis créez les trois comptes initiaux. La confirmation génère les accès techniques adaptés.\n\nQuel type d’export le SI de la caisse peut-il produire aujourd’hui ?",
            preg_match('/dossier|alerte|lbc|risque|centif|client/ui', $message) === 1 => 'Votre espace ADMIN SaaS couvre le déploiement, les accès techniques, les listes et les utilisateurs. L’analyse d’un dossier LBC-FT relève de la session Conformité habilitée de la caisse.',
            default => 'Je peux vous guider sur l’inscription d’une caisse, le choix API/CSV/SQL, la remise du connecteur, les listes et le screening, ou les utilisateurs initiaux. Précisez l’étape et le mode concernés.',
        };
    }
}
