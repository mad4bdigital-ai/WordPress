const { registerPlugin } = wp.plugins;
const { useEffect, useState } = wp.element;

const objectID = window.JetEngineListingData.object_id || 0;

let iframeEditor = null;
let iframeCheckCount = 0;

function debounce( func, timeout = 50 ) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => { func.apply(this, args); }, timeout);
  };
}

/**
 * Get preview settings for the listing from local storage or default values.
 * In the local storage, the settings are stored under the key `jet-engine-listing-preview-settings`.
 * `jet-engine-listing-preview-settings` is an object with the following structure:
 * {
 * 	[objectID]: {
 * 		previewWidth: 800, // Example value
 * 		previewBG: '#ffffff', // Example value
 * 	},
 * }
 * @returns {Object} Preview settings for the listing.
 */
const getPreviewSettings = () => {

	const settings = JSON.parse( localStorage.getItem( 'jet-engine-listing-preview-settings' ) ) || {};

	return settings[ objectID ] || {
		width: 600, // Default width
		previewBG: '#f5f5f5', // Default background color
	};
};

const updatePreview = debounce( () => {
	const previewContent = document.querySelector( '.jet-engine-blocks-views-editor .editor-styles-wrapper .is-root-container' );
	const previewContainer = document.querySelector( '.jet-engine-blocks-views-editor .editor-styles-wrapper' );	

	if ( previewContent && previewContainer ) {
		const previewSettings = getPreviewSettings();

		previewContent.style.width = `${ previewSettings.width }px`;
		previewContent.style.maxWidth = `${ previewSettings.width }px`;
		previewContainer.style.backgroundColor = previewSettings.previewBG;
	}

	return previewContent && previewContainer;
} );

const updateIframePreview = debounce( () => {
	iframeEditor = document.querySelector('iframe[name="editor-canvas"]');

	if ( ! iframeEditor ) {
		return;
	}

	let iframeDoc = iframeEditor.contentDocument;
	let styleID = 'jet_engine_listing_preview_styles';
	let styleEl = iframeDoc.querySelector( 'style#' + styleID );

	if ( ! styleEl && iframeEditor?.contentDocument?.readyState === 'complete' ) {
		styleEl = iframeDoc.createElement( 'style' );
		styleEl.id = styleID;
		let iframeBody = iframeDoc.querySelector( 'body' );
		iframeBody.append( styleEl );
	}

	if ( ! styleEl ) {
		return;
	}

	const previewSettings = getPreviewSettings();

	styleEl.innerHTML = `html.block-editor-iframe__html :where(.wp-block) {max-width: ${previewSettings.width}px;}`;
} );

const updatePreviewSettings = ( newSettings ) => {

	const settings = JSON.parse( localStorage.getItem( 'jet-engine-listing-preview-settings' ) ) || {};

	settings[ objectID ] = {
		...settings[ objectID ],
		...newSettings,
	};

	localStorage.setItem( 'jet-engine-listing-preview-settings', JSON.stringify( settings ) );
};

const ListingSidebar = () => {

	if ( ! wp.editPost ) {
		return;
	}

	const { PluginSidebar } = wp.editPost;
	const { RangeControl } = wp.components;

	const [ previewSettings, setPreviewSettings ] = useState( getPreviewSettings() );

	return (
		<PluginSidebar
			name="jet-engine-listing-sidebar"
			title="Preview Settings"
			icon="admin-generic"
		>
			<div style={ { padding: '16px' } }>
				<RangeControl
					label="Preview Width"
					value={ previewSettings.width }
					min={ 200 }
					max={ 2400 }
					onChange={ ( value ) => {

						setPreviewSettings( {
							...previewSettings,
							...{ width: value },
						} );

						updatePreviewSettings( {
							...previewSettings,
							...{ width: value },
						} );

						updatePreview();
						updateIframePreview();
					} }
				/>
			</div>
		</PluginSidebar>
	);
};

if ( window.JetEngineListingData.isJetEnginePostType ) {
	const iframeCheck = setInterval( () => {
		if ( updatePreview() ) {
			clearInterval( iframeCheck );
			return;
		}

		iframeCheckCount++;

		if ( iframeCheckCount > 100 ) {
			clearInterval( iframeCheck );
			return;
		}

		iframeEditor = document.querySelector('iframe[name="editor-canvas"]');

		if ( iframeEditor ) {
			clearInterval( iframeCheck );

			if ( iframeEditor?.contentDocument?.readyState === 'complete' ) {
				updateIframePreview();
			} else {
				iframeEditor.addEventListener(
				'load',
				() => {
					updateIframePreview();
				}
			);
			}
		}
	}, 500 );

	registerPlugin( 'jet-engine-listing-sidebar', {
		render: ListingSidebar,
		icon: 'admin-generic',
	} );
}
