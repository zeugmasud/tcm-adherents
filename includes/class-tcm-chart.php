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
		// Par défaut : saison la plus récente (comme les KPI). 'all' = toutes saisons.
		if ( isset( $_GET['saison'] ) ) {
			$saison = sanitize_text_field( wp_unslash( $_GET['saison'] ) );
		} else {
			$saison = TCM_Taxonomies::current_saison();
		}
		if ( 'all' === $saison ) {
			$saison = '';
		}

		if ( 'saisons' === $atts['type'] ) {
			$config = $this->config_saisons( $saison );
		} elseif ( 'ages' === $atts['type'] ) {
			$config = $this->config_ages( $saison );
		} elseif ( 'sexes' === $atts['type'] ) {
			$config = $this->config_sexes( $saison );
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
				if ( ! el || ! window.Chart ) { return; }
				var plugins = [];
				if ( cfg.type === 'bar' ) {
					// Affiche la valeur au-dessus de chaque barre.
					plugins.push({
						afterDatasetsDraw: function ( chart ) {
							var ctx = chart.ctx;
							chart.data.datasets.forEach( function ( ds, di ) {
								var meta = chart.getDatasetMeta( di );
								meta.data.forEach( function ( bar, i ) {
									var v = ds.data[i];
									if ( v === null || v === undefined ) { return; }
									ctx.save();
									ctx.fillStyle = '#333';
									ctx.font = '600 12px sans-serif';
									ctx.textAlign = 'center';
									ctx.textBaseline = 'bottom';
									ctx.fillText( v, bar.x, bar.y - 4 );
									ctx.restore();
								} );
							} );
						}
					});
				}
				new Chart( el, Object.assign( {}, cfg, { plugins: plugins } ) );
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

	/**
	 * Histogramme des métriques (tuiles) pour la saison sélectionnée.
	 * Une barre par indicateur. L'Encaissé (€) est exclu (échelle différente).
	 */
	private function config_saisons( string $saison ): array {
		$s = ( new TCM_Dashboard() )->compute_stats( '' !== $saison ? $saison : 'all' );

		$series = array(
			array( 'Adhésions', (int) $s['adhesions'], '#7fae78' ),
			array( 'Dossiers complets', (int) $s['complets'], '#5bb3a8' ),
			array( 'Dossiers incomplets', (int) $s['incomplets'], '#e0a53a' ),
			array( 'ADOC validés', (int) $s['adoc'], '#5b8def' ),
			array( 'Adultes', (int) $s['adultes'], '#8fb4f4' ),
			array( 'Enfants', (int) $s['enfants'], '#b48fe0' ),
			array( 'Hommes', (int) $s['hommes'], '#7cc6c0' ),
			array( 'Femmes', (int) $s['femmes'], '#e59ac4' ),
			array( 'Cours validés', (int) $s['cours_valides'], '#7fae78' ),
			array( 'Cours en attente', (int) $s['cours_attente'], '#e0a53a' ),
		);
		$labels = array();
		$values = array();
		$colors = array();
		foreach ( $series as $row ) {
			$labels[] = $row[0];
			$values[] = $row[1];
			$colors[] = $row[2];
		}

		return array(
			'type' => 'bar',
			'data' => array(
				'labels'   => $labels,
				'datasets' => array( array(
					'label'           => 'Saison ' . ( '' !== $saison ? $saison : 'toutes' ),
					'data'            => $values,
					'backgroundColor' => $colors,
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

	/**
	 * IDs des adhérents publiés d'une saison ('' = toutes).
	 * $complet = true : uniquement les dossiers complets.
	 */
	private function adherent_ids( string $saison, bool $complet = false ): array {
		$tax = array();
		if ( '' !== $saison ) {
			$tax[] = array( 'taxonomy' => TCM_Taxonomies::TAX_SAISON, 'field' => 'name', 'terms' => $saison );
		}
		if ( $complet ) {
			$tax[] = array( 'taxonomy' => TCM_Taxonomies::TAX_DOSSIER, 'field' => 'name', 'terms' => 'Complet' );
		}
		if ( count( $tax ) > 1 ) {
			$tax['relation'] = 'AND';
		}
		return get_posts( array(
			'post_type'      => TCM_CPT_ADHERENT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => $tax ?: array(),
		) );
	}

	/** Camembert adultes / enfants (âge calculé) — dossiers complets uniquement. */
	private function config_ages( string $saison ): array {
		$adultes = 0;
		$enfants = 0;
		foreach ( $this->adherent_ids( $saison, true ) as $a ) {
			$pid = (int) get_field( 'personne', $a );
			$age = $this->age_from( get_field( 'date_naissance', $pid ) );
			if ( null !== $age ) {
				if ( $age >= 18 ) {
					$adultes++;
				} else {
					$enfants++;
				}
			}
		}
		return $this->doughnut( array( 'Adultes', 'Enfants' ), array( $adultes, $enfants ), array( '#8fb4f4', '#f7b267' ) );
	}

	/** Camembert femmes / hommes (déduit de la civilité) — dossiers complets uniquement. */
	private function config_sexes( string $saison ): array {
		$femmes = 0;
		$hommes = 0;
		foreach ( $this->adherent_ids( $saison, true ) as $a ) {
			$pid  = (int) get_field( 'personne', $a );
			$sexe = $this->sexe_from( get_field( 'civilite', $pid ) );
			if ( 'g' === $sexe ) {
				$hommes++;
			} elseif ( 'f' === $sexe ) {
				$femmes++;
			}
		}
		return $this->doughnut( array( 'Femmes', 'Hommes' ), array( $femmes, $hommes ), array( '#e59ac4', '#7cc6c0' ) );
	}

	/** Fabrique une config Chart.js en anneau (doughnut) générique. */
	private function doughnut( array $labels, array $data, array $colors ): array {
		return array(
			'type' => 'doughnut',
			'data' => array(
				'labels'   => $labels,
				'datasets' => array( array(
					'data'            => $data,
					'backgroundColor' => $colors,
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

	/** Âge en années depuis une date stockée en Ymd, ou null si indisponible. */
	private function age_from( $ymd ): ?int {
		$ymd = preg_replace( '/\D/', '', (string) $ymd );
		if ( 8 !== strlen( (string) $ymd ) ) {
			return null;
		}
		$naissance = DateTime::createFromFormat( 'Ymd', $ymd );
		return $naissance ? (int) $naissance->diff( new DateTime( 'today' ) )->y : null;
	}

	/** Sexe déduit de la civilité : 'g' (M.) ou 'f' (Mme), '' sinon. */
	private function sexe_from( $civilite ): string {
		$c = strtolower( trim( (string) $civilite ) );
		if ( 'm.' === $c || 'm' === $c || 'monsieur' === $c ) {
			return 'g';
		}
		if ( 'mme' === $c || 'mme.' === $c || 'madame' === $c || 'mlle' === $c ) {
			return 'f';
		}
		return '';
	}
}
