<?php
/**
 * Plugin Name: Médiathèque Salt and Pine
 * Description: Une médiathèque plus lisible : grandes vignettes non recadrées, taille réglable, plus d'infobulle au survol, format / dimensions / ratio affichés sur chaque image et filtre par format.
 * Version:     1.0.1
 * Author:      Julien Pipier
 * License:     GPL-2.0-or-later
 * Text Domain: mediatheque-salt
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'MLT_VERSION', '1.0.0' );
define( 'MLT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Libellé court d'un type MIME : image/jpeg → JPG, image/svg+xml → SVG…
 */
function mlt_mime_label( $mime ) {
	$sub = strtolower( (string) substr( strrchr( $mime, '/' ), 1 ) );
	$map = array(
		'jpeg'                     => 'JPG',
		'pjpeg'                    => 'JPG',
		'svg+xml'                  => 'SVG',
		'x-icon'                   => 'ICO',
		'vnd.microsoft.icon'       => 'ICO',
		'tiff'                     => 'TIFF',
	);
	return isset( $map[ $sub ] ) ? $map[ $sub ] : strtoupper( $sub );
}

/**
 * Ratio lisible (16:9, 4:3…) ou orientation si aucun ratio courant ne correspond.
 */
function mlt_ratio_label( $w, $h ) {
	$w = (int) $w;
	$h = (int) $h;
	if ( ! $w || ! $h ) {
		return '';
	}
	$known = array( array( 1, 1 ), array( 5, 4 ), array( 4, 3 ), array( 3, 2 ), array( 16, 10 ), array( 16, 9 ), array( 2, 1 ), array( 21, 9 ), array( 4, 5 ), array( 3, 4 ), array( 2, 3 ), array( 10, 16 ), array( 9, 16 ), array( 1, 2 ) );
	$r     = $w / $h;
	foreach ( $known as $k ) {
		if ( abs( $r - $k[0] / $k[1] ) / ( $k[0] / $k[1] ) < 0.015 ) {
			return $k[0] . ':' . $k[1];
		}
	}
	return $r > 1 ? __( 'Paysage', 'mediatheque-salt' ) : __( 'Portrait', 'mediatheque-salt' );
}

/** Types MIME image réellement présents dans la médiathèque. */
function mlt_image_mimes() {
	$mimes = array_keys( (array) get_available_post_mime_types( 'attachment' ) );
	$mimes = array_values( array_filter( $mimes, function ( $m ) {
		return 0 === strpos( $m, 'image/' );
	} ) );
	sort( $mimes );
	return $mimes;
}

/* -------------------------------------------------------------------------
 * Assets : grille (page Médiathèque + fenêtre « Ajouter un média »)
 * ---------------------------------------------------------------------- */

function mlt_enqueue_media_assets() {
	wp_enqueue_style( 'mediatheque-salt', MLT_URL . 'assets/mediatheque-salt.css', array(), MLT_VERSION );
	wp_enqueue_script( 'mediatheque-salt', MLT_URL . 'assets/mediatheque-salt.js', array( 'media-views' ), MLT_VERSION, true );

	$mimes = array();
	foreach ( mlt_image_mimes() as $m ) {
		$mimes[] = array( 'mime' => $m, 'label' => mlt_mime_label( $m ) );
	}
	wp_localize_script( 'mediatheque-salt', 'MLT', array(
		'mimes' => $mimes,
		'i18n'  => array(
			'size'      => __( 'Taille', 'mediatheque-salt' ),
			'infos'     => __( 'Infos', 'mediatheque-salt' ),
			'landscape' => __( 'Paysage', 'mediatheque-salt' ),
			'portrait'  => __( 'Portrait', 'mediatheque-salt' ),
		),
	) );
}
add_action( 'wp_enqueue_media', 'mlt_enqueue_media_assets' );

// Notre script doit patcher les vues avant que la grille de upload.php ne soit construite.
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'upload.php' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'mediatheque-salt', MLT_URL . 'assets/mediatheque-salt.css', array(), MLT_VERSION );
	$scripts = wp_scripts();
	if ( isset( $scripts->registered['media-grid'] ) && ! in_array( 'mediatheque-salt', $scripts->registered['media-grid']->deps, true ) ) {
		$scripts->registered['media-grid']->deps[] = 'mediatheque-salt';
	}
}, 5 );

/* -------------------------------------------------------------------------
 * Vue liste : colonne « Format » + filtre par format
 * ---------------------------------------------------------------------- */

add_filter( 'manage_media_columns', function ( $columns ) {
	$out = array();
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'title' === $key ) {
			$out['mlt_format'] = __( 'Format', 'mediatheque-salt' );
		}
	}
	if ( ! isset( $out['mlt_format'] ) ) {
		$out['mlt_format'] = __( 'Format', 'mediatheque-salt' );
	}
	return $out;
} );

add_action( 'manage_media_custom_column', function ( $column, $post_id ) {
	if ( 'mlt_format' !== $column ) {
		return;
	}
	$mime  = get_post_mime_type( $post_id );
	$label = mlt_mime_label( $mime );
	$meta  = wp_get_attachment_metadata( $post_id );
	$w     = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
	$h     = isset( $meta['height'] ) ? (int) $meta['height'] : 0;

	$size = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;
	if ( ! $size ) {
		$file = get_attached_file( $post_id );
		$size = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
	}

	printf( '<span class="mlt-badge mlt-badge--inline mlt-%1$s">%2$s</span>', esc_attr( strtolower( $label ) ), esc_html( $label ) );
	if ( $w && $h ) {
		printf( '<div class="mlt-col-dims">%1$d × %2$d <strong>%3$s</strong></div>', $w, $h, esc_html( mlt_ratio_label( $w, $h ) ) );
	}
	if ( $size ) {
		printf( '<div class="mlt-col-size">%s</div>', esc_html( size_format( $size, 1 ) ) );
	}
}, 10, 2 );

add_action( 'restrict_manage_posts', function ( $post_type ) {
	if ( 'attachment' !== $post_type ) {
		return;
	}
	$current = isset( $_GET['mlt_mime'] ) ? sanitize_text_field( wp_unslash( $_GET['mlt_mime'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	echo '<label for="mlt-mime" class="screen-reader-text">' . esc_html__( 'Filtrer par format', 'mediatheque-salt' ) . '</label>';
	echo '<select name="mlt_mime" id="mlt-mime">';
	echo '<option value="">' . esc_html__( 'Tous les formats', 'mediatheque-salt' ) . '</option>';
	foreach ( mlt_image_mimes() as $m ) {
		printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $m ), selected( $current, $m, false ), esc_html( mlt_mime_label( $m ) ) );
	}
	echo '</select>';
} );

add_action( 'pre_get_posts', function ( $query ) {
	global $pagenow;
	if ( ! is_admin() || ! $query->is_main_query() || 'upload.php' !== $pagenow || empty( $_GET['mlt_mime'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$mime = sanitize_text_field( wp_unslash( $_GET['mlt_mime'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( in_array( $mime, mlt_image_mimes(), true ) ) {
		$query->set( 'post_mime_type', $mime );
	}
} );
