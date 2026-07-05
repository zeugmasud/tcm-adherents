# CLAUDE.md — Contexte projet pour Claude Code

## Quoi
Plugin WordPress **sur-mesure** de gestion multi-saison du **Tennis Club Mimet**
(adhérents, règlements, commandes, créneaux, inscriptions). Remplace deux apps
AppSheet + un Google Sheet. Slug/dossier : `tcm-adherents`.

## Environnements
- **Dev** : `https://dev.tcmimet.fr` (Plesk, panel `titan.zwa.fr`). C'est ici qu'on développe.
- **Prod** : `https://www.tcmimet.fr` (Salient + WPBakery + ARForms — NE PAS toucher tant que le dev n'est pas validé).
- Déploiement dev : Plesk Git (déploiement auto) ou SFTP. Voir README.

## Stack (contrainte forte)
- WordPress 7.0, PHP 8.0+.
- **ACF Pro** (obligatoire) : tous les champs sont déclarés en PHP via `acf_add_local_field_group` (versionnables). Le plugin affiche une notice admin si ACF Pro est absent.
- **Elementor Pro** pour l'affichage (Loop Grid). Pas de JetEngine/Crocoblock (choix budget asso).
- Connecteur **Royal MCP** actif sur le dev (accès données/ACF/Elementor par API), mais il NE modifie PAS les fichiers de code.

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

## Conventions de code
- Préfixe `tcm_` / `TCM_` partout. Une classe par fichier dans `includes/`.
- Commentaires en **français**. `if ( ! defined( 'ABSPATH' ) ) exit;` en tête de chaque fichier.
- Menu admin regroupé sous `tcm-adherents` ; le menu parent s'enregistre en **priorité 9** (avant les sous-menus, sinon accès refusé).
- Nonce + `current_user_can('tcm_manage')` sur toute action d'écriture admin.

## État courant (dev)
- Données importées : **191 personnes, 248 adhérents** (185 saison 2026, 63 saison 2027). Import vérifié correct.
- En cours : vues back-office Elementor (Loop Grid + filtres Saison/Dossier).

## À faire (backlog)
- Vues Elementor back-office + portail adhérent.
- Câblage formulaire d'inscription Elementor → `TCM_Form_Ingest`.
- Config webhook HelloAsso (secret + URL dans Réglages).
- Rebranchement synchro ADOC (idAdoc dans le CPT).
- Décommissionnement AppSheet / Sheet / ARForms après validation.
