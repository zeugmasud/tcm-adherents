<?php
/**
 * Graphiques du tableau de bord (Chart.js, chargé en CDN).
 *
 *   [tcm_chart type="dossiers"]  Donut : dossiers complets vs incomplets.
 *   [tcm_chart type="saisons"]   Barres : nombre d'adhérents par saison.
 *
 * Filtre optionnel : ?saison=2026 restreint le donut à une saison.
 * Auto-suffisant : charge Chart.js si absent, aucune enqueue nécessaire.
 *
 * @package TCM_Adherents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCM_Chart {

	const CDN = 'https://cdn.jsdelivr.net/npm/chart.js@4';

	public function hooks(): void {
		add_shortcode( 'tcm_chart', array( $this, 'render' ) );
	}

	/** Compte les adhérents publiés correspondant à une tax_query. */
	private function count( array $tax_query ): int {
		$q = new WP_Query( array(
			'post_type'      => TCM_CPT_ADHERENT,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'tax_query'      => $tax_query,
		) );
		return (int) $q->found_posts;
	}

	public function render( $atts ): string {
		if ( ! current_user_can( 'tcm_manage' ) ) {
			return '';
		}
		$atts = shortcode_atts( array( 'type' => 'dossiers', 'hauteur' => 260 ), $atts );
		$saison = isset( $_GET['saison'] ) ? sanitize_text_field( wp_unslash( $_GET['saison'] ) ) : '';

		if ( 'saisons' === $atts['type'] ) {
			$config = $this->config_saisons();
		} else {
			$config = $this->config_dossiers( $saison );
		}

		$id = 'tcm-chart-' . $atts['type'] . '-' . wp_rand( 1000, 9999 );
		$json = wp_json_encode( $config );

		ob_start();
		echo '<div class="tcm-chart" style="max-width:100%;height:' . (int) $atts['hauteur'] . 'px;position:relative;">';
		echo '<canvas id="' . esc_attr( $id ) . '"></canvas>';
		echo '</div>';
		?>
		<script>
		(function(){
			var cfg = <?php echo $json; // phpcs:ignore ?>;
			function draw(){
				var el = document.getElementById('<?php echo esc_js( $id ); ?>');
				if ( el && window.Chart ) { new Chart( el, cfg ); }
			}
			if ( window.Chart ) { draw(); }
			else if ( ! window.__tcmChartLoading ) {
				window.__tcmChartLoading = true;
				var s = document.createElement('script');
				s.src = '<?php echo esc_url( self::CDN ); ?>';
				s.onload = function(){ window.__tcmChartReady = true; draw(); };
				document.head.appendChild(s);
			} else {
				var t = setInterval(function(){ if ( window.Chart ) { clearInterval(t); draw(); } }, 100);
			}
		})();
		</script>
		<?php
		return (string) ob_get_clean();
	}

	private function config_dossiers( string $saison ): array {
		$base = array();
		if ( '' !== $saison ) {
			$base[] = array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'field' => 'name', 'terms' => $saison );
		}
		$complet = $this->count( array_merge( $base, array(
			array( 'taxonomy' => TCM_Taxonomies::TAX_DOSSIER, 'field' => 'name', 'terms' => 'Complet' ),
		) ) );
		$incomplet = $this->count( array_merge( $base, array(
			array( 'taxonomy' => TCM_Taxonomies::TAX_DOSSIER, 'field' => 'name', 'terms' => 'Incomplet' ),
		) ) );

		return array(
			'type' => 'doughnut',
			'data' => array(
				'labels'   => array( 'Complet', 'Incomplet' ),
				'datasets' => array( array(
					'data'            => array( $complet, $incomplet ),
					'backgroundColor' => array( '#8fd0b0', '#f4d08a' ),
					'borderWidth'     => 0,
				) ),
			),
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => false,
				'cutout'              => '62%',
				'plugins'             => array( 'legend' => array( 'position' => 'bottom' ) ),
			),
		);
	}

	private function config_saisons(): array {
		$terms  = get_terms( array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'hide_empty' => false, 'orderby' => 'name' ) );
		$labels = array();
		$values = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$labels[] = $t->name;
				$values[] = $this->count( array( array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'field' => 'term_id', 'terms' => $t->term_id ) ) );
			}
		}
		return array(
			'type' => 'bar',
			'data' => array(
				'labels'   => $labels,
				'datasets' => array( array(
					'label'           => 'Adhérents',
					'data'            => $values,
					'backgroundColor' => '#a9c3f5',
					'borderRadius'    => 6,
				) ),
			),
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => false,
				'plugins'             => array( 'legend' => array( 'display' => false ) ),
				'scales'              => array( 'y' => array( 'beginAtZero' => true, 'ticks' => array( 'precision' => 0 ) ) ),
			),
		);
	}
}
