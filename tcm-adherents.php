<?php
/**
 * Plugin Name:       TC Mimet — Gestion des adhérents
 * Plugin URI:        https://dev.tcmimet.fr
 * Description:       Gestion multi-saison des adhérents, règlements, commandes, créneaux et inscriptions du Tennis Club Mimet. Modèle de données en CPT + ACF Pro, logique métier sur-mesure (dédoublonnage Nom+DOB, agrégation multi-saison, webhook HelloAsso, import historique).
 * Version:           0.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            ZWA
 * Author URI:        https://zwa.fr
 * Text Domain:       tcm-adherents
 * Domain Path:       /languages
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Accès direct interdit.
}

// -----------------------------------------------------------------------------
// Constantes
// -----------------------------------------------------------------------------
define( 'TCM_VERSION', '0.3.0' );
define( 'TCM_FILE', __FILE__ );
define( 'TCM_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCM_URL', plugin_dir_url( __FILE__ ) );
define( 'TCM_BASENAME', plugin_basename( __FILE__ ) );

// Slugs des CPT — centralisés pour éviter les fautes de frappe ailleurs.
define( 'TCM_CPT_PERSONNE', 'tcm_personne' );
define( 'TCM_CPT_ADHERENT', 'tcm_adherent' );
define( 'TCM_CPT_REGLEMENT', 'tcm_reglement' );
define( 'TCM_CPT_COMMANDE', 'tcm_commande' );
define( 'TCM_CPT_CRENEAU', 'tcm_creneau' );
define( 'TCM_CPT_INSCRIPTION', 'tcm_inscription' );

// -----------------------------------------------------------------------------
// Chargement des classes
// -----------------------------------------------------------------------------
require_once TCM_PATH . 'includes/class-tcm-cpt.php';
require_once TCM_PATH . 'includes/class-tcm-taxonomies.php';
require_once TCM_PATH . 'includes/class-tcm-acf-fields.php';
require_once TCM_PATH . 'includes/class-tcm-titles.php';
require_once TCM_PATH . 'includes/class-tcm-dedup.php';
require_once TCM_PATH . 'includes/class-tcm-logic.php';
require_once TCM_PATH . 'includes/class-tcm-season.php';
require_once TCM_PATH . 'includes/class-tcm-helloasso.php';
require_once TCM_PATH . 'includes/class-tcm-import.php';
require_once TCM_PATH . 'includes/class-tcm-form-ingest.php';
require_once TCM_PATH . 'includes/class-tcm-roles.php';
require_once TCM_PATH . 'includes/class-tcm-settings.php';
require_once TCM_PATH . 'includes/class-tcm-access.php';
require_once TCM_PATH . 'includes/class-tcm-frontoffice.php';
require_once TCM_PATH . 'includes/class-tcm-shell.php';
require_once TCM_PATH . 'includes/class-tcm-dashboard.php';
require_once TCM_PATH . 'includes/class-tcm-chart.php';
require_once TCM_PATH . 'includes/class-tcm-facture.php';
require_once TCM_PATH . 'includes/class-tcm-crud.php';
require_once TCM_PATH . 'includes/class-tcm-planning.php';
require_once TCM_PATH . 'includes/class-tcm-normalize.php';
require_once TCM_PATH . 'includes/class-tcm-inscription.php';
require_once TCM_PATH . 'includes/class-tcm-import-full.php';
require_once TCM_PATH . 'includes/class-tcm-import-history.php';
require_once TCM_PATH . 'includes/class-tcm-maintenance.php';
require_once TCM_PATH . 'includes/class-tcm-front-login.php';
require_once TCM_PATH . 'includes/class-tcm-cheque.php';
require_once TCM_PATH . 'includes/class-tcm-log.php';
require_once TCM_PATH . 'includes/class-tcm-plugin.php';

// -----------------------------------------------------------------------------
// Activation / désactivation
// -----------------------------------------------------------------------------
register_activation_hook( __FILE__, function () {
	// Les CPT doivent exister avant de vider les règles de réécriture.
	( new TCM_CPT() )->register();
	( new TCM_Taxonomies() )->register();
	TCM_Roles::add_roles_and_caps();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
	// NB : on ne supprime NI les rôles NI les données à la désactivation (sécurité).
} );

// -----------------------------------------------------------------------------
// Démarrage
// -----------------------------------------------------------------------------
add_action( 'plugins_loaded', function () {
	TCM_Plugin::instance()->boot();
} );
