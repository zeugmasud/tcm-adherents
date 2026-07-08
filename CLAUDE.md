# CLAUDE.md — Contexte projet pour Claude Code

## Quoi
Plugin WordPress **sur-mesure** de gestion multi-saison du **Tennis Club Mimet**
(adhérents, règlements, commandes, créneaux, inscriptions). Remplace deux apps
AppSheet + un Google Sheet. Slug/dossier : `tcm-adherents`.

## Environnements
- **Dev** : `https://dev.tcmimet.fr` (Plesk, panel `titan.zwa.fr`). OPcache `revalidate_freq=0` → déploiement pris en compte immédiatement.
- **Prod** : `https://www.tcmimet.fr` — **migrée sur ce plugin, EN LIGNE**. Ne toucher qu'après validation dev ; toute écriture prod est sensible (données de mineurs, RGPD).
- **Déploiement** : mirror FTPS via `lftp` — `bash deploy.sh` (dev) et `bash deploy-prod.sh` (prod, confirmation « PROD »). Identifiants dans `.env` NON versionné (jamais en clair). Se lance depuis le **Mac** (le sandbox n'atteint pas le FTPS). Décalage de synchro Dropbox possible → relancer le déploiement si un fichier ne « prend » pas.
- Connecteur **Royal MCP** actif sur dev et prod (accès données/ACF/Elementor par API) ; il NE modifie PAS les fichiers de code. Peut tomber → le reconnecter dans Customize → Connectors.

## Stack (contrainte forte)
- WordPress 7.0, PHP 8.0+.
- **ACF Pro** (obligatoire) : tous les champs sont déclarés en PHP via `acf_add_local_field_group` (versionnables). Le plugin affiche une notice admin si ACF Pro est absent.
- **Option B (privacy-first)** : les CPT sont `public => false` (données de mineurs). Le **back-office est rendu PAR LE PLUGIN** (shortcodes + template shell `tcm-crm-shell`), pas par Elementor. **Elementor Pro** est réservé au **site public** (accueil, tarifs, contact, inscription).
- Connexion front : modale (`TCM_Front_Login`) qui poste **nativement vers wp-login.php** (seule méthode fiable derrière le proxy Plesk + plugin de consentement cookies). Marche sur mobile ; sur desktop la session s'ouvre mais retombe sur wp-login (voir mémoire du projet).

## Modèle de données (6 CPT + 2 taxonomies)
- CPT : `tcm_personne` (identité stable, clé `cle_dedup`) → `tcm_adherent` (1/personne×saison) → `tcm_reglement`, `tcm_commande` ; `tcm_creneau` ↔ `tcm_inscription`.
- Relations : champs ACF `post_object` stockés côté enfant (1→N).
- Taxonomies (pour filtrage natif Elementor, qui ne filtre PAS par champ ACF) : `tcm_saison`, `tcm_dossier` (Complet/Incomplet). Alimentées via `TCM_Taxonomies::sync_adherent()`.

## Logique métier clé
- **Dédoublonnage** : `TCM_Dedup::make_key()` = `remove_accents(nom+' '+prenom)` normalisé + `|` + DOB (Ymd). L'email n'est PAS une clé d'identité (partagé en famille, souvent vide).
- **Corrections de réconciliation** : `TCM_Import::corrections()` (variantes d'orthographe / champs inversés — ex. Grousset Ricou, Talpaert).
- **Attention normalisation** : `remove_accents()` (WordPress) gère l'apostrophe typographique `’` et les ligatures autrement que Python — s'y fier plutôt qu'à une analyse externe.
- **Import CSV** : `TCM_Import` — parseur `fgetcsv` sur flux (gère les champs multi-lignes entre guillemets). Écran admin copier-coller + WP-CLI `wp tcm import`. Boutons Réinitialiser et Réindexer.
- **Places restantes / dossier complet** : `TCM_Logic`.
- **HelloAsso** : `TCM_HelloAsso` — webhook REST signé HMAC-SHA256 → `tcm_reglement` (CB). Chèque/espèces = saisie manuelle.
- **Formulaire d'inscription** : `TCM_Form_Ingest` — hook `elementor_pro/forms/new_record` → CPT (à câbler côté Elementor).

## Back-office — shortcodes & modules livrés (rendus par le plugin)
- **`[tcm_stats]`** (`TCM_Dashboard::sc_stats`) — KPI du tableau de bord, par défaut **saison la plus récente comparée à la précédente** (dynamique via `TCM_Taxonomies::current_saison()/previous_saison()`). 4 lignes : A) Adhésions · Dossiers complets · Dossiers incomplets · ADOC ; B) Adultes · Enfants · Hommes · Femmes (+ camemberts) ; C) Cours validés · Cours en attente ; D) Encaissé. **Démographie = dossiers complets uniquement** ; adultes/enfants sur l'âge calculé (date de naissance). Certaines tuiles sont des **liens** vers les listes filtrées.
- **`[tcm_chart type=…]`** (`TCM_Chart`) — Chart.js (CDN). `ages` (adultes/enfants, dossiers complets), `sexes` (femmes/hommes), `saisons` (histogramme des métriques de la **saison sélectionnée**, valeurs affichées sur les barres). `dossiers` (donut complet/incomplet) existe encore mais retiré de la page.
- **`[tcm_crm]`** (`TCM_Dashboard::sc_crm`) — liste maître-détail des adhérents (`/back-office-adherents/`), filtres **saison** + **dossier** (Complet/Incomplet), recherche, badge nb de cours, lien vers `[tcm_fiche]`.
- **`[tcm_fiche]`** — fiche adhérent à onglets (coordonnées, règlements, commandes, cours, historique) + calculette.
- **`[tcm_planning]`** (`TCM_Planning`) — `/creneaux/` : bascule **« Par cours » / « Par adhérent »** (`?vue`). Vue par cours = maître-détail créneaux + inscrits ; vue par adhérent = inscriptions triées par adhérent, filtre **statut** (`?statut=confirme|attente`). Inscription statut ACF `statut` = `confirme` / `attente`.
- **`[tcm_recap]`**, **`[tcm_reglements]`**, **`[tcm_helloasso]`**, **`[tcm_sidebar]`**, **`[tcm_inscription]`** (formulaire public).
- **Maintenance** (`TCM_Maintenance`, menu admin) : normalisation noms/tél, import ADOC par CSV, **détecteur de doublons de saison** (une personne = plusieurs fiches adhérent).
- **Facture / attestation PDF** (`TCM_Facture`, Dompdf), **Import complet** (`TCM_Import_Full`, wipe & reload JSON — RGPD).

## Conventions de code
- Préfixe `tcm_` / `TCM_` partout. Une classe par fichier dans `includes/`.
- Commentaires en **français**. `if ( ! defined( 'ABSPATH' ) ) exit;` en tête de chaque fichier.
- Menu admin regroupé sous `tcm-adherents` ; le menu parent s'enregistre en **priorité 9** (avant les sous-menus, sinon accès refusé).
- Nonce + `current_user_can('tcm_manage')` sur toute action d'écriture admin.

## État courant (prod en ligne)
- Prod migrée et opérationnelle. Ordres de grandeur : ~193 personnes ; saison **2027** = 67 adhésions (45 dossiers complets, 22 incomplets), saison 2026 plus fournie.
- Back-office rendu par shortcodes (Option B) : tableau de bord, CRM adhérents, planning, maintenance — tous livrés et vérifiés en live.
- Pages back-office sous template `tcm-crm-shell` : `tableau-de-bord` (`[tcm_stats]` + `[tcm_chart type=saisons]`), `back-office-adherents` (`[tcm_crm]`), `creneaux` (`[tcm_planning]`), `recap`.

## À faire (backlog)
- Câblage formulaire d'inscription Elementor → `TCM_Form_Ingest`.
- Config webhook HelloAsso (secret + URL dans Réglages).
- Rebranchement synchro ADOC (idAdoc dans le CPT, skill `adoc-synchro`).
- Connexion desktop 100 % fluide (option : page wp-login brandée) — actuellement OK mobile, dégradée desktop.
- Décommissionnement AppSheet / Sheet après validation complète.
