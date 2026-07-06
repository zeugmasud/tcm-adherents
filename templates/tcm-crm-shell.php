<?php
/**
 * Template de page « TC Mimet — CRM » — app-shell autonome (Option B).
 *
 * Sidebar + zone de contenu, indépendant du thème public (Elementor réservé au
 * site public). Le contenu de la page (shortcodes [tcm_stats], [tcm_crm]…) est
 * rendu dans <main>. Chargé par TCM_Shell::load_template() quand la page a le
 * template `tcm-crm-shell`.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'tcm-crm-shell' ); ?>>
<?php wp_body_open(); ?>

<div class="tcm-shell">

	<?php echo do_shortcode( '[tcm_sidebar]' ); ?>

	<main class="tcm-app">
		<?php
		while ( have_posts() ) :
			the_post();
			the_content();
		endwhile;
		?>
	</main>

</div>

<?php wp_footer(); ?>
</body>
</html>
