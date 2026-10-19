/**
 * Apply custom CSS inside editor
 */
( function( $ ) {

	"use strict";

	var JEBlocksCSS = {
		cssInput: null,
		iframeEditor: null,


		init: function() {

			var self = this,
				rawCss = null,
				css  = null;

			self.cssInput = $( '.jet_engine_listing_css' );

			if ( self.cssInput.length ) {
				$( document ).on( 'change', '.jet_engine_listing_css', self.updateStyles );

				rawCss = self.cssInput.val();

				if ( rawCss ) {
					css = self.parseCSS( rawCss );
				}

				$( 'head' ).append( '<style id="jet_engine_listing_styles">' + css + '</style>' );
				
				let iframeCheckCount = 0;

				const iframeCheck = setInterval( () => {
					iframeCheckCount++;

					if ( iframeCheckCount > 50 ) {
						clearInterval( iframeCheck );
						return;
					}

					self.iframeEditor = document.querySelector('iframe[name="editor-canvas"]');

					if ( self.iframeEditor ) {
						clearInterval( iframeCheck );

						if ( self.iframeEditor?.contentDocument?.readyState === 'complete' ) {
							JEBlocksCSS.updateIframeStyles( rawCss );
						} else {
							self.iframeEditor.addEventListener(
							'load',
							() => {
								JEBlocksCSS.updateIframeStyles( rawCss );
							}
						);
						}
					}
				}, 2000 );

				setInterval( () => {
					JEBlocksCSS.updateIframeStyles( self.cssInput.val(), true );
				}, 2000 );
			}

		},
		updateStyles: function() {
			var rawCss = $( this ).val();
			var css = '';

			if ( rawCss ) {
				css = JEBlocksCSS.parseCSS( rawCss );
			}

			$( '#jet_engine_listing_styles' ).text( css );

			JEBlocksCSS.updateIframeStyles( rawCss );
		},
		updateIframeStyles: function( rawCss = '', nonExistingStyleOnly = false ) {
			let iframeEditor = document.querySelector('iframe[name="editor-canvas"]');

			if ( iframeEditor && iframeEditor?.contentDocument?.readyState === 'complete' ) {
				let iframeDoc = iframeEditor.contentDocument;
				let styleEl = iframeDoc.querySelector( 'style#jet_engine_listing_styles' );

				if ( nonExistingStyleOnly && styleEl ) {
					return;
				}

				if ( ! styleEl ) {
					styleEl = iframeDoc.createElement( 'style' );
					styleEl.id = 'jet_engine_listing_styles';
					let iframeBody = iframeDoc.querySelector( 'body' );
					iframeBody.append( styleEl );
				}

				if ( ! styleEl ) {
					return;
				}

				styleEl.innerHTML = rawCss.replace( /selector/g, '.block-editor-iframe__html' );
			}
		},
		parseCSS: function( css ) {
			if ( document.querySelector( '.interface-navigable-region.interface-interface-skeleton__content' ) ) {
				return css.replace( /selector/g, '.interface-navigable-region.interface-interface-skeleton__content' );
			}

			return css.replace( /selector/g, '#editor' );
		}
	};

	JEBlocksCSS.init();

	window.addEventListener( 'load', () => $( '.jet_engine_listing_css' ).trigger( 'change' ) );

}( jQuery ) );
