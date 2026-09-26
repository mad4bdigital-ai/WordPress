<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared, read-only presentation helpers for MAD4B administrator pages. */
final class MAD4B_SCP_Admin_Experience {
	private static $styles_rendered = false;

	public static function styles() {
		if ( self::$styles_rendered ) return;
		self::$styles_rendered = true;
		echo '<style>
		.mad4b-scp-admin-page{max-width:1440px}.mad4b-scp-admin-page>p.description{max-width:980px;font-size:14px;line-height:1.6}
		.mad4b-scp-admin-page .nav-tab-wrapper{margin:18px 0 22px;display:flex;flex-wrap:wrap;gap:2px}
		.mad4b-scp-stage-rail{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:18px 0 22px}
		.mad4b-scp-stage{background:#fff;border:1px solid #dcdcde;border-left:4px solid #8c8f94;border-radius:4px;padding:14px 16px;min-height:86px;box-sizing:border-box}
		.mad4b-scp-stage.is-complete{border-left-color:#00a32a}.mad4b-scp-stage.is-attention{border-left-color:#dba617}.mad4b-scp-stage.is-blocked{border-left-color:#d63638}.mad4b-scp-stage.is-pending{border-left-color:#72aee6}
		.mad4b-scp-stage-number{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#646970;font-weight:600}.mad4b-scp-stage-title{display:block;margin-top:4px;font-size:15px;font-weight:600;color:#1d2327}.mad4b-scp-stage-detail{display:block;margin-top:5px;color:#646970;line-height:1.4}.mad4b-scp-stage a{text-decoration:none}
		.mad4b-scp-card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:18px 0 22px}
		.mad4b-scp-card{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px;box-sizing:border-box}.mad4b-scp-card.is-complete{border-top:3px solid #00a32a}.mad4b-scp-card.is-attention{border-top:3px solid #dba617}.mad4b-scp-card.is-blocked{border-top:3px solid #d63638}.mad4b-scp-card.is-pending{border-top:3px solid #72aee6}
		.mad4b-scp-card-label{display:block;color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.mad4b-scp-card-value{display:block;margin-top:6px;font-size:20px;line-height:1.2;font-weight:600;color:#1d2327}.mad4b-scp-card-help{display:block;margin-top:7px;color:#646970;line-height:1.45}
		.mad4b-scp-panel{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:18px 20px;margin:18px 0}.mad4b-scp-panel>h2:first-child,.mad4b-scp-panel>h3:first-child{margin-top:0}.mad4b-scp-panel>p:last-child{margin-bottom:0}
		.mad4b-scp-next-step{border-left:4px solid #72aee6}.mad4b-scp-next-step.is-complete{border-left-color:#00a32a}.mad4b-scp-next-step.is-attention{border-left-color:#dba617}.mad4b-scp-next-step.is-blocked{border-left-color:#d63638}
		.mad4b-scp-table-wrap{overflow-x:auto;margin:12px 0 22px}.mad4b-scp-table-wrap table{min-width:760px}.mad4b-scp-admin-page code{overflow-wrap:anywhere}.mad4b-scp-admin-page .widefat th{vertical-align:top}
		.mad4b-scp-muted{color:#646970}.mad4b-scp-section-lead{max-width:980px;color:#50575e;line-height:1.6}
		@media (max-width:782px){.mad4b-scp-admin-page .nav-tab{margin-bottom:4px}.mad4b-scp-stage-rail,.mad4b-scp-card-grid{grid-template-columns:1fr}.mad4b-scp-panel{padding:14px}}
		</style>';
	}

	public static function tabs( $page_slug, array $tabs, $active ) {
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'MAD4B page sections', 'mad4b-site-control-plane' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg( array( 'page' => $page_slug, 'tab' => $slug ), admin_url( 'admin.php' ) );
			echo '<a class="nav-tab ' . ( $active === $slug ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '" aria-current="' . ( $active === $slug ? 'page' : 'false' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	public static function stages( array $stages ) {
		echo '<div class="mad4b-scp-stage-rail" aria-label="' . esc_attr__( 'MAD4B staged readiness', 'mad4b-site-control-plane' ) . '">';
		foreach ( $stages as $index => $stage ) {
			$state = isset( $stage['state'] ) && in_array( $stage['state'], array( 'complete', 'attention', 'blocked', 'pending' ), true ) ? $stage['state'] : 'pending';
			$label = isset( $stage['label'] ) ? (string) $stage['label'] : '';
			$detail = isset( $stage['detail'] ) ? (string) $stage['detail'] : '';
			$url = isset( $stage['url'] ) ? (string) $stage['url'] : '';
			echo '<div class="mad4b-scp-stage is-' . esc_attr( $state ) . '">';
			echo '<span class="mad4b-scp-stage-number">' . esc_html( sprintf( __( 'Stage %d', 'mad4b-site-control-plane' ), $index + 1 ) ) . '</span>';
			if ( '' !== $url ) echo '<a href="' . esc_url( $url ) . '">';
			echo '<span class="mad4b-scp-stage-title">' . esc_html( $label ) . '</span>';
			if ( '' !== $url ) echo '</a>';
			if ( '' !== $detail ) echo '<span class="mad4b-scp-stage-detail">' . esc_html( $detail ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	public static function cards( array $cards ) {
		echo '<div class="mad4b-scp-card-grid">';
		foreach ( $cards as $card ) {
			$state = isset( $card['state'] ) && in_array( $card['state'], array( 'complete', 'attention', 'blocked', 'pending' ), true ) ? $card['state'] : 'pending';
			$label = isset( $card['label'] ) ? (string) $card['label'] : '';
			$value = isset( $card['value'] ) ? (string) $card['value'] : '';
			$help = isset( $card['help'] ) ? (string) $card['help'] : '';
			echo '<div class="mad4b-scp-card is-' . esc_attr( $state ) . '">';
			echo '<span class="mad4b-scp-card-label">' . esc_html( $label ) . '</span>';
			echo '<span class="mad4b-scp-card-value">' . esc_html( $value ) . '</span>';
			if ( '' !== $help ) echo '<span class="mad4b-scp-card-help">' . esc_html( $help ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	public static function next_step( $title, $message, $state = 'pending', $url = '', $link_label = '' ) {
		$state = in_array( $state, array( 'complete', 'attention', 'blocked', 'pending' ), true ) ? $state : 'pending';
		echo '<div class="mad4b-scp-panel mad4b-scp-next-step is-' . esc_attr( $state ) . '"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $message ) . '</p>';
		if ( '' !== $url && '' !== $link_label ) echo '<p><a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html( $link_label ) . '</a></p>';
		echo '</div>';
	}

	public static function tab_url( $page_slug, $tab ) {
		return add_query_arg( array( 'page' => $page_slug, 'tab' => $tab ), admin_url( 'admin.php' ) );
	}

	public static function state_from_bool( $ready, $blocked = false ) {
		if ( $ready ) return 'complete';
		return $blocked ? 'blocked' : 'pending';
	}
}
