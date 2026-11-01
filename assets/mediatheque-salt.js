/* Médiathèque Claire */
( function ( $, wp, MLT ) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view ) {
		return;
	}

	MLT = MLT || { mimes: [], i18n: {} };
	var t = MLT.i18n || {};

	/* ---- Réglages (taille + infos), mémorisés dans le navigateur -------- */

	var settings = { size: 200, info: true };
	try {
		$.extend( settings, JSON.parse( localStorage.getItem( 'mlt-settings' ) ) || {} );
	} catch ( e ) {}

	function applySettings() {
		document.documentElement.style.setProperty( '--mlt-size', settings.size + 'px' );
		document.documentElement.classList.toggle( 'mlt-hide-info', ! settings.info );
		try {
			localStorage.setItem( 'mlt-settings', JSON.stringify( settings ) );
		} catch ( e ) {}
	}
	applySettings();

	/* ---- Ratio --------------------------------------------------------- */

	var RATIOS = [ [1,1], [5,4], [4,3], [3,2], [16,10], [16,9], [2,1], [21,9], [4,5], [3,4], [2,3], [10,16], [9,16], [1,2] ];

	function ratioOf( w, h ) {
		var r = w / h;
		for ( var i = 0; i < RATIOS.length; i++ ) {
			var k = RATIOS[ i ][ 0 ] / RATIOS[ i ][ 1 ];
			if ( Math.abs( r - k ) / k < 0.015 ) {
				return RATIOS[ i ][ 0 ] + ':' + RATIOS[ i ][ 1 ];
			}
		}
		return r > 1 ? ( t.landscape || 'Paysage' ) : ( t.portrait || 'Portrait' );
	}

	/* ---- Décoration des vignettes ------------------------------------- */

	function decorate( view ) {
		var el = view.el;
		if ( ! el || ! el.classList || ! el.classList.contains( 'attachment' ) ) {
			return; // ignore le panneau de détails, etc.
		}

		// Plus d'infobulle au survol.
		el.removeAttribute( 'title' );
		view.$( '[title]' ).removeAttr( 'title' );

		var model = view.model;
		var $preview = view.$( '.attachment-preview' );
		if ( ! model || ! $preview.length || model.get( 'uploading' ) ) {
			return;
		}

		$preview.find( '.mlt-badge, .mlt-info' ).remove();

		var w = parseInt( model.get( 'width' ), 10 );
		var h = parseInt( model.get( 'height' ), 10 );
		if ( w && h ) {
			$( '<span class="mlt-info"/>' )
				.append( $( '<span/>' ).text( w + ' × ' + h ) )
				.append( $( '<b/>' ).text( ratioOf( w, h ) ) )
				.appendTo( $preview );
		}
	}

	var proto = wp.media.view.Attachment.prototype;
	var originalRender = proto.render;
	proto.render = function () {
		var out = originalRender.apply( this, arguments );
		decorate( this );
		return out;
	};

	/* ---- Filtre par format dans la grille ----------------------------- */

	var All = wp.media.view.AttachmentFilters && wp.media.view.AttachmentFilters.All;
	if ( All && MLT.mimes && MLT.mimes.length ) {
		var originalCreate = All.prototype.createFilters;
		All.prototype.createFilters = function () {
			originalCreate.apply( this, arguments );
			var base = this.filters.image;
			if ( ! base ) {
				return;
			}
			var self = this;
			MLT.mimes.forEach( function ( m, i ) {
				self.filters[ 'mlt_' + i ] = {
					text: '↳ ' + m.label,
					props: $.extend( {}, base.props, { type: m.mime } ),
					priority: ( base.priority || 20 ) + ( i + 1 ) / 100
				};
			} );
		};
	}

	/* ---- Contrôles dans la barre d'outils ----------------------------- */

	function buildControls() {
		var $c = $( '<div class="mlt-controls"/>' );

		var $range = $( '<input type="range" min="120" max="360" step="10"/>' ).val( settings.size );
		$range.on( 'input', function () {
			settings.size = parseInt( this.value, 10 );
			applySettings();
			$( '.mlt-controls input[type=range]' ).not( this ).val( settings.size );
		} );

		var $info = $( '<input type="checkbox"/>' ).prop( 'checked', settings.info );
		$info.on( 'change', function () {
			settings.info = this.checked;
			applySettings();
			$( '.mlt-controls input[type=checkbox]' ).not( this ).prop( 'checked', settings.info );
		} );

		$c.append( $( '<label/>' ).text( t.size || 'Taille' ).append( $range ) );
		$c.append( $( '<label/>' ).append( $info ).append( document.createTextNode( t.infos || 'Infos' ) ) );
		return $c;
	}

	function injectControls() {
		$( '.attachments-browser .media-toolbar-secondary' ).each( function () {
			if ( ! $( this ).children( '.mlt-controls' ).length ) {
				$( this ).append( buildControls() );
			}
		} );
	}

	var pending = false;
	new MutationObserver( function () {
		if ( pending ) {
			return;
		}
		pending = true;
		window.requestAnimationFrame( function () {
			pending = false;
			injectControls();
		} );
	} ).observe( document.documentElement, { childList: true, subtree: true } );

}( jQuery, window.wp, window.MLT ) );
