# TC Mimet — Gestion des adhérents (`tcm-adherents`)

Plugin WordPress sur-mesure de gestion multi-saison du Tennis Club Mimet.
Modèle de données en **CPT + ACF Pro**, affichage via **Elementor Pro**, logique métier sur-mesure.

## Pré-requis
- WordPress 6.4+, PHP 8.0+
- **Advanced Custom Fields PRO** actif (sinon les champs ne se chargent pas — notice admin).
- Elementor Pro (affichage + formulaire d'inscription).

## Modèle de données
| CPT | Slug | Rôle |
|-----|------|------|
| Personne | `tcm_personne` | Identité stable (clé Nom+DOB `cle_dedup`) |
| Adhérent | `tcm_adherent` | 1 par personne × saison → `personne`, `saison` |
| Règlement | `tcm_reglement` | → `adherent`, canal (helloasso/chèque/espèces) |
| Commande | `tcm_commande` | → `adherent` |
| Créneau | `tcm_creneau` | Planning (capacité, jour, horaires) |
| Inscription | `tcm_inscription` | → `adherent` + `creneau`, statut confirmé/attente |

Taxonomies de filtrage (Elementor) : `tcm_saison`, `tcm_dossier` (Complet/Incomplet).

## Import (WP-CLI)
```bash
wp tcm import /chemin/export.csv --dry-run   # simulation
wp tcm import /chemin/export.csv             # exécution
```
Ou via **TC Mimet → Importer (CSV)** (copier-coller). Boutons *Vider* et *Réindexer* sur la même page.

---

## Déploiement vers le dev (dev.tcmimet.fr)

Développement **local** avec Claude Code, déploiement **automatique** vers le serveur Plesk.
Deux options, au choix.

### Option A — Plesk Git (recommandée, versionnée)
1. Poussez ce dépôt sur un remote (GitHub/BitBucket).
2. Plesk → *Sites web & Domaines → Git → Ajouter un dépôt → Dépôt distant*.
3. Collez l'URL SSH du remote ; ajoutez la **clé publique générée par Plesk** aux réglages du remote.
4. **Dossier cible** : `httpdocs/wp-content/plugins/tcm-adherents`.
5. Mode **déploiement automatique** : chaque `git push` → Plesk tire et déploie.

> Le dépôt Git est conservé par Plesk à part ; seuls les fichiers du working tree
> sont publiés dans le dossier cible (le `.git` n'est pas déployé).

### Option B — SFTP-on-save (simple)
1. Plesk → *Paramètres d'hébergement → Accès SSH* → `/bin/bash (chrooté)`.
2. Récupérez l'utilisateur système + hôte ; ajoutez votre clé SSH (*SSH Keys*).
3. Extension **SFTP** dans VS Code : source = ce dossier, cible =
   `.../wp-content/plugins/tcm-adherents`, `ignore` = `.git`, `*.zip`.
4. Sync-on-save activé → chaque sauvegarde pousse le fichier.

### Commandes serveur (WP-CLI)
Via le **terminal SSH de Plesk** (WP Toolkit fournit WP-CLI) :
```bash
cd ~/httpdocs && wp tcm import ./export.csv --dry-run
```

---

## Structure
```
tcm-adherents/
├── tcm-adherents.php              # bootstrap, constantes, activation
├── CLAUDE.md                      # contexte projet pour Claude Code
├── README.md
├── .gitignore
└── includes/
    ├── class-tcm-plugin.php       # orchestrateur + menu (parent en priorité 9)
    ├── class-tcm-cpt.php          # 6 CPT
    ├── class-tcm-taxonomies.php   # tcm_saison, tcm_dossier + réindexation
    ├── class-tcm-acf-fields.php   # groupes ACF (PHP)
    ├── class-tcm-titles.php       # titres auto
    ├── class-tcm-dedup.php        # identité Nom+DOB
    ├── class-tcm-logic.php        # places restantes, dossier complet
    ├── class-tcm-season.php       # duplication de saison
    ├── class-tcm-helloasso.php    # webhook HelloAsso (HMAC)
    ├── class-tcm-import.php       # import CSV (fgetcsv multi-lignes) + reset + reindex
    ├── class-tcm-form-ingest.php  # hook Elementor Pro Forms → CPT
    ├── class-tcm-roles.php        # rôles & capacités
    └── class-tcm-settings.php     # réglages (secret HelloAsso, formulaire, saison)
```

## Changelog
- **0.2.4** — Taxonomies `tcm_saison` / `tcm_dossier` (filtrage Elementor natif + colonnes admin) + réindexation.
- **0.2.3** — Parseur CSV `fgetcsv` (champs multi-lignes) ; compteur personnes fiable ; bouton Réinitialiser.
- **0.2.2** — Simulation d'import avec aperçu des compteurs.
- **0.2.1** — Correctif menu (parent en priorité 9).
- **0.2.0** — Import corrigé (vrais en-têtes, virgule, corrections) + écran d'import copier-coller.
- **0.1.0** — Scaffold initial (6 CPT, ACF, dédoublonnage, HelloAsso, duplication saison).
