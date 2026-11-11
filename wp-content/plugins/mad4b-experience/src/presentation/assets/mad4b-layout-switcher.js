( function( $ ) {

	"use strict";

	const JetEngineLayoutSwitcher = {

		activeClass: 'je-layout-switcher__btn--active',
		loadingClass: 'je-layout-switcher--loading',

		elementorInit: function() {
			window.elementorFrontend.hooks.addAction(
				'frontend/element_ready/jet-engine-layout-switcher.default',
				JetEngineLayoutSwitcher.initLayoutSwitcher
			);
		},

		// An AJAX render can replace both the Listing and Layout Switcher DOM.
		// A single document coordinator owns user intent and reattaches observers.
		desiredViews: Object.create( null ),
		globalBound: false,
		jsfBound: false,
		refreshSwitchers: function() {
			$( '.je-layout-switcher' ).each( function() {
				JetEngineLayoutSwitcher.watchLayoutSwitcher( $( this ) );
				const notify = $( this ).data( 'mad4b-layout-schedule' );
				if ( typeof notify === 'function' ) { notify(); }
			} );
			JetEngineLayoutSwitcher.bindJsfEvents();
		},
		bindJsfEvents: function() {
			if ( JetEngineLayoutSwitcher.jsfBound ) { return; }
			const jsf = window.JetSmartFilters;
			if ( !jsf?.events || typeof jsf.events.subscribe !== 'function' ) { return; }
			JetEngineLayoutSwitcher.jsfBound = true;
			jsf.events.subscribe( 'ajaxFilters/updated', function( provider, queryId ) {
				if ( provider !== 'jet-engine' || queryId !== 'tours_query_archive' ) { return; }
				setTimeout( JetEngineLayoutSwitcher.refreshSwitchers, 150 );
				setTimeout( JetEngineLayoutSwitcher.refreshSwitchers, 900 );
				setTimeout( JetEngineLayoutSwitcher.refreshSwitchers, 2100 );
			} );
		},
		commonInit: function() {
			$( document ).on( 'click.JetEngineLayoutSwitcher',
				'.je-layout-switcher__btn', JetEngineLayoutSwitcher.switchLayout );
			if ( JetEngineLayoutSwitcher.globalBound ) { return; }
			JetEngineLayoutSwitcher.globalBound = true;
			$( function() {
				JetEngineLayoutSwitcher.refreshSwitchers();
				// Only rebind when a native switcher node was actually added.
				new MutationObserver( function( records ) {
					const added = records.some( function( record ) {
						return Array.from( record.addedNodes || [] ).some( function( node ) {
							return node.nodeType === 1 && (
								(node.matches && node.matches( '.je-layout-switcher' )) ||
								(node.querySelector && node.querySelector( '.je-layout-switcher' ))
							);
						} );
					} );
					if ( added ) {
						setTimeout( JetEngineLayoutSwitcher.refreshSwitchers, 160 );
						setTimeout( JetEngineLayoutSwitcher.refreshSwitchers, 1050 );
					}
				} ).observe( document.body, { childList: true, subtree: true } );
			} );
			document.addEventListener( 'dfsb/layout-view-committed',
				JetEngineLayoutSwitcher.refreshSwitchers );
			document.addEventListener( 'dfsb/ajax-presentation-updated',
				JetEngineLayoutSwitcher.refreshSwitchers );
			$( window ).on( 'load', JetEngineLayoutSwitcher.refreshSwitchers );
		},

		initLayoutSwitcher: function( $scope ) {
			JetEngineLayoutSwitcher.watchLayoutSwitcher( $scope );
			const $activeBtn = $scope.find( '.' + JetEngineLayoutSwitcher.activeClass );

			if ( ! $activeBtn.length || $activeBtn.data( 'is-default' ) ) {
				return;
			}

			const $wrapper = $scope.find( '.je-layout-switcher' );
			const widgetId = $wrapper.data( 'widget-id' );
			const activeSettings = $activeBtn.data( 'settings' );

			JetEngineLayoutSwitcher.updateElementSettings( widgetId, activeSettings );
		},

        // JetSmartFilters can complete an older AJAX result after a view switch.
        // Reconcile the selected authored listing with the actual rendered
        // template, never infer a new listing from arbitrary CSS or query data.
        // Keep the chosen authored view independent of the native JSF facet
        // state. A JSF AJAX response can swap the Listing back to the default
        // template; a later Layout Switcher cookie callback can also change
        // the highlighted button. Neither is allowed to change user intent.
        watchLayoutSwitcher: function( $scope ) {
            const $wrapper = $scope.hasClass( 'je-layout-switcher' )
                ? $scope.first() : $scope.find( '.je-layout-switcher' ).first();
            if ( ! $wrapper.length || $wrapper.data( 'mad4b-layout-watched' ) ) { return; }
            const widgetId = String( $wrapper.data( 'widget-id' ) || '' );
            const $grid = $( '.elementor-element-' + widgetId );
            if ( ! widgetId || ! $grid.length || typeof MutationObserver !== 'function' ) { return; }
            const activeClass = JetEngineLayoutSwitcher.activeClass;
            const $buttons = $wrapper.find( '.je-layout-switcher__btn' );
            const $first = $buttons.filter( '.' + activeClass ).first();
            if ( ! $first.length ) { return; }
            $wrapper.data( 'mad4b-layout-watched', true );
            const previousChoice = JetEngineLayoutSwitcher.desiredViews[ widgetId ];
            const initialChoice = previousChoice || String( $first.data( 'slug' ) || '' );
            $wrapper.data( 'mad4b-layout-desired', initialChoice );
            JetEngineLayoutSwitcher.desiredViews[ widgetId ] = initialChoice;
            let timer = 0, tries = 0, lastTry = 0;
            function schedule( delay ) {
                if ( timer ) { clearTimeout( timer ); }
                timer = setTimeout( check, typeof delay === 'number' ? delay : 340 );
            }
            function settle() {
                // The native JSF event fires before every Elementor/Listing
                // DOM swap has settled. Bound the follow-up checks.
                schedule( 200 );
                setTimeout( schedule, 850 );
                setTimeout( schedule, 1800 );
                setTimeout( schedule, 3500 );
            }
            function check() {
                timer = 0;
                if ( ! $wrapper[0].isConnected || ! $grid[0].isConnected ) { return; }
                const desiredSlug = String( $wrapper.data( 'mad4b-layout-desired' ) || '' );
                const $desired = $buttons.filter( function() {
                    return String( $( this ).data( 'slug' ) || '' ) === desiredSlug;
                } ).first();
                if ( ! $desired.length ) { return; }
                const settings = $desired.data( 'settings' ) || {};
                const expected = parseInt( settings.lisitng_id, 10 ) || 0;
                const $items = $grid.find( '.jet-listing-grid__items' ).first();
                const match = String( $items.attr( 'class' ) || '' )
                    .match( /(?:^|\s)jet-listing-grid--(\d+)(?:\s|$)/ );
                const actual = match ? parseInt( match[1], 10 ) : 0;
                if ( ! expected || ! actual ) { return; }
                const group = window.JetSmartFilters?.filterGroups?.['jet-engine/tours_query_archive'];
                if ( $wrapper.hasClass( JetEngineLayoutSwitcher.loadingClass ) ||
                    group?.isAjaxLoading ) { schedule( 550 ); return; }
                if ( actual === expected ) {
                    tries = 0;
                    // Reconcile the visual control without changing the
                    // server-signed filter settings or triggering a new request.
                    if ( !$desired.hasClass( activeClass ) ) {
                        $buttons.removeClass( activeClass ).attr( 'aria-pressed', 'false' );
                        $desired.addClass( activeClass ).attr( 'aria-pressed', 'true' );
                        if ( $wrapper.data( 'view' ) === 'elementor' ) {
                            JetEngineLayoutSwitcher.updateElementSettings( widgetId, settings );
                        }
                        JetEngineLayoutSwitcher.addLayoutCSS(
                            $wrapper, widgetId, settings, !!$desired.data( 'is-default' )
                        );
                    }
                    return;
                }
                const now = Date.now();
                if ( now - lastTry > 18000 ) { tries = 0; }
                if ( tries >= 3 ) { return; }
                tries += 1;
                lastTry = now;
                JetEngineLayoutSwitcher.switchLayout( {
                    currentTarget: $desired[0],
                    mad4bForceReconcile: true
                } );
                setTimeout( schedule, 2100 );
            }
            new MutationObserver( schedule ).observe( $grid[0], {
                childList: true, subtree: true,
                attributes: true, attributeFilter: ['class']
            } );
            new MutationObserver( schedule ).observe( $wrapper[0], {
                subtree: true, attributes: true, attributeFilter: ['class']
            } );
            // Per-node observers are local to this DOM instance. Global JSF
            // and document events are subscribed only once by commonInit().
            $wrapper.data( 'mad4b-layout-schedule', settle );
            schedule( 420 );
        },

		switchLayout: function( event ) {
			const $btn = $( event.currentTarget );
			const activeClass = JetEngineLayoutSwitcher.activeClass;
			const $wrapper = $btn.closest( '.je-layout-switcher' );
			const forced = !! ( event && event.mad4bForceReconcile );
			if ( ! forced ) {
				const clickedSlug = String( $btn.data( 'slug' ) || '' );
				if ( clickedSlug ) {
					$wrapper.data( 'mad4b-layout-desired', clickedSlug );
					const widgetId = String( $wrapper.data( 'widget-id' ) || '' );
					if ( widgetId ) { JetEngineLayoutSwitcher.desiredViews[ widgetId ] = clickedSlug; }
				}
			}

			// Keep signed JetEngine AJAX serial, but honor the latest user click.
			// An already-active view is a valid CANCEL intent while another view
			// is loading; this branch must precede the active-button guard.
			if ( $wrapper.hasClass( JetEngineLayoutSwitcher.loadingClass ) ) {
				if ( ! forced ) {
					const requestedSlug = $btn.data( 'slug' );
					$wrapper.data( 'mad4b-layout-pending', requestedSlug );
					$wrapper.attr( 'data-layout-pending', requestedSlug );
				}
				return;
			}
			if ( $btn.hasClass( activeClass ) && ! forced ) {
				return;
			}
			const widgetId = $wrapper.data( 'widget-id' );
			const view     = $wrapper.data( 'view' );

			const defaultListing = $wrapper.data( 'default-listing' );

			const slug = $btn.data( 'slug' );
			const settings = $btn.data( 'settings' );
			const isDefaultLayout = $btn.data( 'is-default' ) || false;

			const $activeBtn = $btn.closest( '.je-layout-switcher__group' ).find( '.' + activeClass );
			let activeSettings = $activeBtn.data( 'settings' );
			if ( forced ) {
				const $actualItems = $( JetEngineLayoutSwitcher.getUniqWrapSelector( view, widgetId ) )
					.find( '.jet-listing-grid__items' ).first();
				const actualMatch = String( $actualItems.attr( 'class' ) || '' )
					.match( /(?:^|\s)jet-listing-grid--(\d+)(?:\s|$)/ );
				const actualListing = actualMatch ? actualMatch[1] : '';
				const known = $wrapper.find( '.je-layout-switcher__btn' ).map( function() {
					return String( $( this ).data( 'settings' )?.lisitng_id || '' );
				} ).get();
				if ( ! actualListing || known.indexOf( actualListing ) === -1 ) { return; }
				activeSettings = Object.assign( {}, activeSettings, { lisitng_id: actualListing } );
			}

			// Complete the current transaction, then process at most one
			// queued intent. Detached widget controls must never be replayed.
			const finish = function () {
				$wrapper.removeClass( JetEngineLayoutSwitcher.loadingClass ).removeAttr( 'aria-busy' );
				const queued = $wrapper.data( 'mad4b-layout-pending' );
				$wrapper.removeData( 'mad4b-layout-pending' ).removeAttr( 'data-layout-pending' );
				if ( ! queued ) { return; }
				const $desired = $wrapper.find( '.je-layout-switcher__btn' ).filter( function () {
					return String( $( this ).data( 'slug' ) ) === String( queued );
				} ).first();
				if ( ! $desired.length || $desired.hasClass( activeClass ) ) { return; }
				window.setTimeout( function () {
					if ( $wrapper[0] && $wrapper[0].isConnected ) {
						JetEngineLayoutSwitcher.switchLayout( { currentTarget: $desired[0] } );
					}
				}, 0 );
			};

			// The native view/cookie state only changes after the new listing
			// has rendered successfully. Otherwise the button and DOM diverge.
			const commit = function () {
				if ( 'elementor' === view ) {
					JetEngineLayoutSwitcher.updateElementSettings( widgetId, settings );
				}
				$btn.closest( '.je-layout-switcher__group' )
					.find( '.' + activeClass ).removeClass( activeClass );
				$btn.addClass( activeClass );
				document.dispatchEvent( new CustomEvent( 'dfsb/layout-view-committed', {
					detail: { widget: widgetId, layout: slug }
				} ) );
				$.ajax( {
					url: window.JetEngineSettings.ajaxlisting,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'jet_engine_ajax',
						jet_engine_action: 'jet_engine_switch_layout',
						widget_id: widgetId,
						layout: slug,
						view: view,
					},
				} );
			};

			const $container = $( JetEngineLayoutSwitcher.getUniqWrapSelector( view, widgetId ) );
			const $listing = $container.find( '.jet-listing-grid__items' ).first();
			const $slider = $listing.parent( '.jet-listing-grid__slider' );
			if ( ! $container.length || ! $listing.length || ! settings || ! activeSettings ) {
				return; // Preserve the last working view, never create an empty one.
			}

			// Load new listings
			if ( settings.lisitng_id && activeSettings.lisitng_id && settings.lisitng_id !== activeSettings.lisitng_id ) {
				let navSettings = $listing.data( 'nav' ) || {};
				navSettings = JetEngine.ensureJSON( navSettings );

				let widgetSettings = navSettings.widget_settings || {};

				// Preserve the complete core-signed base payload. The server resolves
				// the selected authored layout from this widget's published document.
				widgetSettings = Object.assign( {}, widgetSettings );
				if ( ! navSettings.query || ! navSettings.query.signature ||
					String( widgetSettings._id || '' ) !== String( widgetId ) ||
					String( widgetSettings._element_id || '' ) === '' ) {
					// Unsigned or wrong-widget nav data cannot authorize a layout
					// request; leave the current results and active button alone.
					return;
				}

				let ajaxOptions = {
					handler: 'get_listing',
					container: $container,
					masonry: false,
					slider: false,
					append: false,
					query: navSettings.query || {},
					widgetSettings: widgetSettings,
					extraProps: {
						switch_layout: 1,
						layout_document: $container.closest( '.elementor[data-elementor-id]' ).data( 'elementor-id' ),
						layout_widget: widgetId,
						layout_slug: slug,
						initial_listing: defaultListing,
						layout_listing: settings.lisitng_id,
						listing_query_id: $listing.data( 'query-id' ),
					}
				};

				let hasLoadMore = !! widgetSettings.use_load_more && 'false' !== widgetSettings.use_load_more,
					page = parseInt( $listing.data( 'page' ), 10 ) || 0,
					pages = parseInt( $listing.data( 'pages' ), 10 ) || 0;

				if ( hasLoadMore ) {
					ajaxOptions.extraProps.loadMorePages = {
						first: 1,
						last: page
					};
				}

				const filterQueryId = widgetSettings._element_id ? widgetSettings._element_id : 'default';

				if ( window.JetSmartFilters ) {
					// Add the filtered query to the request
					if ( window.JetSmartFilters.filterGroups
						&& window.JetSmartFilters.filterGroups['jet-engine/' + filterQueryId]
						&& window.JetSmartFilters.filterGroups['jet-engine/' + filterQueryId].currentQuery
					) {
						Object.assign( ajaxOptions.extraProps, {
							filtered_query: window.JetSmartFilters.filterGroups['jet-engine/' + filterQueryId].currentQuery,
							filter_provider: 'jet-engine/' + filterQueryId,
						} );
					}

					if ( hasLoadMore && window.JetSmartFilters?.filterGroups?.['jet-engine/' + filterQueryId]?.currentQuery?.jet_paged ) {
						delete ajaxOptions.extraProps.loadMorePages;
					}

					if ( window.JetSmartFilters?.filterGroups?.['jet-engine/' + filterQueryId]?.filters ) {
						const filters = window.JetSmartFilters.filterGroups['jet-engine/' + filterQueryId].filters;

						filters.forEach( function( filter ) {

							if ( 'pagination' === filter.name
								&& filter.isLoadMore
								&& filter.moreActiveIndexes?.length
							) {
								ajaxOptions.extraProps.loadMorePages = {
									first: filter.moreActiveIndexes[0],
									last: filter.dataValue
								};

								page = filter.dataValue;
							}

						} );
					}

					// Keep the JetSmartFilters provider settings byte-for-byte unchanged.
				// These settings already include a valid server-side JSF signature.
				// The layout selector is carried only through JetEngine's separate
				// authored/signed get_listing request (layout_document/widget/slug).
				// Mutating JSF's _layout_listing here invalidates subsequent facet AJAX.

				}

				$wrapper.addClass( JetEngineLayoutSwitcher.loadingClass ).attr( 'aria-busy', 'true' );

				JetEngine.ajaxGetListing( ajaxOptions, function( response ) {
					if ( ! response || response.success !== true ) {
						finish();
						return; // JetEngine calls done even on a JSON error.
					}
					// Update nav data attributes
					const $new_listing = $container.find( '.jet-listing-grid__items' ).first();

					$new_listing
						.data( 'page', page )
						.data( 'pages', pages )
						.attr( 'data-page', page )
						.attr( 'data-pages', pages );

					// Reinit pagination filters.
					JetEngineLayoutSwitcher.reInitPaginationFilters( filterQueryId, page, pages );

					JetEngine.widgetListingGrid( $container );
					JetEngineLayoutSwitcher.addLayoutCSS( $wrapper, widgetId, settings, isDefaultLayout );
					commit();
					finish();
				}, function() {
					finish();
				} );

			} else {
				JetEngineLayoutSwitcher.addLayoutCSS( $wrapper, widgetId, settings, isDefaultLayout );
				JetEngineLayoutSwitcher.reInitMasonry( $listing, widgetId, settings, view );
				JetEngineLayoutSwitcher.reInitSlider( $slider, settings, view );
				commit();
			}

		},

		reInitPaginationFilters: function( filterQueryId, page, pages ) {

			if ( ! window.JetSmartFilters ) {
				return;
			}

			if ( ! window.JetSmartFilters?.filterGroups?.['jet-engine/' + filterQueryId] ) {
				return;
			}

			if ( ! window.JetSmartFilterSettings?.props?.['jet-engine']?.[filterQueryId] ) {
				return;
			}

			const filterGroups = window.JetSmartFilters.filterGroups['jet-engine/' + filterQueryId];
			const paginationFilters = filterGroups.getFiltersByName( 'pagination' );

			window.JetSmartFilterSettings.props['jet-engine'][ filterQueryId ].page = page;
			window.JetSmartFilterSettings.props['jet-engine'][ filterQueryId ].max_num_pages = pages;

			if ( paginationFilters.length ) {
				paginationFilters.forEach( paginationFilter => {
					paginationFilter.reinit();
				} );
			}
		},

		addLayoutCSS: function( $wrapper, widgetId, settings, isDefaultLayout ) {
			let $styleTag = $( '#jet-engine-layout-switcher-custom-css-' + widgetId );
			let inlineCss = '';

			if ( ! $styleTag.length ) {

				$styleTag = $( '<style>', {
					id: 'jet-engine-layout-switcher-custom-css-' + widgetId
				} );

				$wrapper.append( $styleTag );
			}

			const view = $wrapper.data( 'view' );

			if ( isDefaultLayout && 'elementor' === view ) {
				$styleTag.html( inlineCss );
				return;
			}

			const selector = JetEngineLayoutSwitcher.getUniqWrapSelector( view, widgetId ) + ' > .jet-listing-grid > .jet-listing-grid__items';
			const slideSelector = JetEngineLayoutSwitcher.getUniqWrapSelector( view, widgetId ) + ' > .jet-listing-grid > .jet-listing-grid__slider > .jet-listing-grid__items.slick-slider .slick-slide';
			const breakpoints = JetEngineLayoutSwitcher.getActiveBreakpoints( view );

			if ( settings.columns ) {
				const display = ( 'auto' === settings.columns ) ? 'grid' : 'flex';
				const autoColRules = ( 'auto' === settings.columns && settings.column_min_width ) ? ' grid-template-columns: repeat( auto-fill, minmax( ' + settings.column_min_width + 'px, 1fr ) ) !important;' : '';
				const slideCss = ( 'auto' === settings.columns && settings.column_min_width ) ? ' ' + slideSelector + ' { width: ' + settings.column_min_width + 'px !important; }' : '';

				inlineCss += selector + ' { display: ' + display + ' !important; --columns: ' + settings.columns + ' !important;' + autoColRules + ' }' + slideCss;
			}

			Object.keys( breakpoints ).forEach( function( breakpointName ) {

				const columnsName = 'columns' + JetEngineLayoutSwitcher.getBreakpointDivider( view ) + breakpointName;
				const columnMinWidthName = 'column_min_width' + JetEngineLayoutSwitcher.getBreakpointDivider( view ) + breakpointName;

				if ( ! settings[ columnsName ] ) {
					return;
				}

				const dir     = breakpoints[ breakpointName ].direction;
				const value   = breakpoints[ breakpointName ].value;
				const columns = settings[ columnsName ];
				const bpDisplay = ( 'auto' === columns ) ? 'grid' : 'flex';
				const bpAutoColRules = ( 'auto' === columns && settings[ columnMinWidthName ] ) ? ' grid-template-columns: repeat( auto-fill, minmax( ' + settings[ columnMinWidthName ] + 'px, 1fr ) ) !important;' : '';
				const bpSlideCss = ( 'auto' === columns && settings[ columnMinWidthName ] ) ? ' ' + slideSelector + ' { width: ' + settings[ columnMinWidthName ] + 'px !important; }' : '';

				inlineCss += ' @media(' + dir + '-width: ' + value + 'px) { ' + selector + ' { display: ' + bpDisplay + ' !important; --columns: ' + columns + ' !important;' + bpAutoColRules + ' }' + bpSlideCss + ' }';
			} );

			$styleTag.html( inlineCss );
		},

		/**
		 * Update Elementor element `data-settings` attribute
		 */
		updateElementSettings: function( widgetId, settings ) {
			let columnsSettings = {};

			Object.keys( settings ).forEach( function( settingName ) {
				if ( 0 === settingName.indexOf( 'columns' ) ) {
					columnsSettings[ settingName ] = settings[settingName];
				}
			} );

			const $eWidget = $( '.elementor-element-' + widgetId );

			if ( $eWidget.length ) {
				$eWidget.data( 'settings', {
					...$eWidget.data( 'settings' ),
					...columnsSettings
				} );
			}
		},

		reInitMasonry: function( $listing, widgetId, settings, view ) {

			if ( ! $listing.hasClass( 'jet-listing-grid__masonry' ) ) {
				return;
			}

			// Update `data-masonry-grid-options` attr for $masonry selector
			let masonryColumnsSettings = JetEngineLayoutSwitcher.getColumnsSettings( settings, view );
			let masonryOptions = $listing.data( 'masonry-grid-options' );

			masonryOptions.columns = masonryColumnsSettings;

			$listing
				.data( 'masonry-grid-options', masonryOptions )
				.attr( 'data-masonry-grid-options', JSON.stringify( masonryOptions ) );

			JetEngine.runMasonry( $listing );
		},

		reInitSlider: function( $slider, settings, view ) {

			if ( ! $slider.length ) {
				return;
			}

			// Update `data-slider_options` attr for $slider selector
			let sliderColumnsSettings = JetEngineLayoutSwitcher.getColumnsSettings( settings, view );
			let sliderOptions = $slider.data( 'slider_options' );

			if ( 'auto' === sliderColumnsSettings?.desktop
				|| 'auto' === sliderColumnsSettings?.tablet
				|| 'auto' === sliderColumnsSettings?.mobile
			) {
				sliderOptions.slidesToShow = 1;
				sliderOptions.variableWidth = true;
			} else {
				sliderOptions.slidesToShow = sliderColumnsSettings;
				delete sliderOptions.variableWidth;
			}

			$slider
				.data( 'slider_options', sliderOptions )
				.attr( 'data-slider_options', JSON.stringify( sliderOptions ) );

			$slider.find( '> .jet-listing-grid__items' )?.slick?.( 'unslick' );

			JetEngine.initSlider( $slider );
		},

		getColumnsSettings: function( settings, view ) {
			let columnsSettings = {};

			if ( settings.columns ) {
				columnsSettings.desktop = 'auto' === settings.columns ? settings.columns : +settings.columns;
			}

			const breakpoints = JetEngineLayoutSwitcher.getActiveBreakpoints( view );

			Object.keys( breakpoints ).forEach( function( breakpointName ) {
				const columnsName = 'columns' + JetEngineLayoutSwitcher.getBreakpointDivider( view ) + breakpointName;

				if ( settings[ columnsName ] ) {
					columnsSettings[ breakpointName ] = 'auto' === settings[ columnsName ] ? settings[ columnsName ] : +settings[ columnsName ];
				}
			} );

			return columnsSettings;
		},

		getUniqWrapSelector: function( view, widgetId ) {
			return window.JetEngineLayoutSwitcherViews?.[ view ]?.getUniqWrapSelector( widgetId ) || '';
		},

		getActiveBreakpoints: function( view ) {
			return window.JetEngineLayoutSwitcherViews?.[ view ]?.getActiveBreakpoints() || {};
		},

		getBreakpointDivider: function( view ) {
			return window.JetEngineLayoutSwitcherViews?.[ view ]?.getBreakpointDivider() || '_';
		}

	};

	$( window ).on( 'elementor/frontend/init', JetEngineLayoutSwitcher.elementorInit );

	JetEngineLayoutSwitcher.commonInit();

}( jQuery ) );