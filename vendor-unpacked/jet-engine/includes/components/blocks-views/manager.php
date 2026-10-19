<?php
/**
 * Blocks (Gutenberg) views manager for JetEngine listings.
 *
 * Bootstraps the editor, renderer, block types, dynamic content,
 * style manager, and all supporting sub-components when the
 * "Blocks Views" performance tweak is active.
 *
 * @package JetEngine
 * @subpackage Blocks_Views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Engine_Blocks_Views' ) ) {

	/**
	 * Manages the Gutenberg (Blocks) listing view type for JetEngine.
	 *
	 * Registers the listing view, loads required files, instantiates the
	 * editor (admin-only), renderer, block types, dynamic content manager,
	 * and the shared Crocoblock Blocks Style Manager.
	 */
	class Jet_Engine_Blocks_Views {

		public $editor;
		public $render;
		public $block_types;
		public $dynamic_content;
		/**
		 * @var \Crocoblock\Blocks_Style\Manager
		 */
		public $style_manager;

		/**
		 * Initialises the Blocks Views component.
		 *
		 * Bails early when the "enable_blocks_views" performance tweak or the
		 * Listings component is inactive. Otherwise loads all sub-components,
		 * wires up WordPress hooks, and sets up the style manager.
		 */
		function __construct() {

			if ( ! \Jet_Engine\Modules\Performance\Module::instance()->is_tweak_active( 'enable_blocks_views' ) ) {
				return;
			}

			if ( ! jet_engine()->components->is_component_active( 'listings' ) ) {
				return;
			}

			add_filter( 'jet-engine/templates/listing-views', array( $this, 'add_listing_view' ), 11 );
			add_filter( 'upload_mimes', array( $this, 'allow_svg' ), 10, 2 );
			add_filter( 'jet-engine/templates/create/data', array( $this, 'inject_listing_settings' ), 0 );

			if ( is_admin() ) {
				require $this->component_path( 'editor.php' );
				$this->editor = new Jet_Engine_Blocks_Views_Editor();
			}

			require $this->component_path( 'render.php' );
			require $this->component_path( 'block-types.php' );
			require $this->component_path( 'ajax-handlers.php' );
			require $this->component_path( 'dynamic-content/manager.php' );
			require $this->component_path( 'components/register.php' );
			require $this->component_path( 'content-setter.php' );
			require $this->component_path( 'core-styles.php' );

			$this->render          = new Jet_Engine_Blocks_Views_Render();
			$this->block_types     = new Jet_Engine_Blocks_Views_Types();
			$this->dynamic_content = new \Jet_Engine\Blocks_Views\Dynamic_Content\Manager();

			new Jet_Engine_Blocks_Views_Ajax_Handlers();
			new \Jet_Engine\Blocks_Views\Components\Register();
			new \Jet_Engine\Blocks_Views\Content_Setter();
			new \Jet_Engine\Blocks_Views\Core_Styles();

			$module_data = jet_engine()->framework->get_included_module_data( 'style-manager.php' );

			//Enable styles migrator in any of plugins when all be ready
			//\Crocoblock\Blocks_Style\Manager::$requires_migration = true;

			$this->style_manager = new \Crocoblock\Blocks_Style\Manager( array(
				'path' => $module_data['path'],
				'url'  => $module_data['url'],
			) );
		}

		/**
		 * Registers the "Blocks (Gutenberg)" listing view type.
		 *
		 * Hooked on `jet-engine/templates/listing-views` (priority 11).
		 *
		 * @param  array $views Associative array of registered listing view types.
		 * @return array
		 */
		public function add_listing_view( $views ) {
			$views['blocks'] = __( 'Blocks (Gutenberg)', 'jet-engine' );
			return $views;
		}

		/**
		 * Allow SVG images uploading for users who can upload files.
		 *
		 * `is_admin()` cannot protect this filter because admin-ajax.php is an
		 * admin request for unauthenticated visitors too. Legacy Forms applies its
		 * own stricter policy and never accepts SVG uploads.
		 *
		 * @param  array            $mimes Allowed MIME types.
		 * @param  int|WP_User|null $user  Current user or null for the current user.
		 * @return array
		 */
		public function allow_svg( $mimes, $user = null ) {

			/**
			 * Disabled by default for security reasons.
			 *
			 * Allowed to enable on demand by calling
			 * `add_filter( 'jet-engine/allow-svg-uploads', '__return_true' )`
			 * in a plugin or theme when the temporary solution is needed until
			 * migration to more complete SVG uploads logic.
			 */
			$allow_add_svg = apply_filters( 'jet-engine/allow-svg-uploads', false );

			if ( ! $allow_add_svg ) {
				return $mimes;
			}

			$user = $user ? $user : wp_get_current_user();

			if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
				return $mimes;
			}

			$mimes['svg'] = 'image/svg+xml';
			return $mimes;
		}

		/**
		 * Returns the absolute path to a file within the blocks-views component directory.
		 *
		 * @param  string $path_inside_component Relative path from the blocks-views root.
		 * @return string Absolute file-system path.
		 */
		public function component_path( $path_inside_component ) {
			return jet_engine()->plugin_path( 'includes/components/blocks-views/' . $path_inside_component );
		}

		/**
		 * Returns the edit URL for a listing template post.
		 *
		 * Used to redirect the user to the block editor after a listing template
		 * is created with the Blocks view type.
		 *
		 * @param  int    $template_id Post ID of the listing template.
		 * @return string Edit URL (not HTML-escaped).
		 */
		public function get_redirect_url( $template_id ) {
			return get_edit_post_link( $template_id, '' );
		}

		/**
		 * Determines whether a listing template uses the Blocks (Gutenberg) view type.
		 *
		 * Reads the `_listing_type` post meta value. When the meta is absent it
		 * inspects the post content for `wp:jet-engine` block markers and, if found,
		 * writes `'blocks'` back to meta to cache the result for subsequent calls.
		 *
		 * @param  int  $listing_id Post ID of the listing template.
		 * @return bool True when the listing uses the Blocks view type.
		 */
		public function is_blocks_listing( $listing_id ) {

			/**
			 * Do not use get_listing_type() method here
			 * because this method itself used inside get_listing_type() to define what listing type was used
			 */
			$meta = get_post_meta( $listing_id, '_listing_type', true );

			if ( ! $meta ) {
				$post    = get_post( $listing_id );
				$content = $post ? $post->post_content : '';

				if ( false !== strrpos( $content, 'wp:jet-engine' ) ) {
					$meta = 'blocks';
					update_post_meta( $listing_id, '_listing_type', $meta );
				}

			}

			return ( 'blocks' === $meta );
		}

		/**
		 * Injects listing settings into template data when creating a Blocks listing.
		 *
		 * Hooked on `jet-engine/templates/create/data` (priority 0). Returns the
		 * data unchanged when the request does not target the Blocks view type or
		 * when `listing_source` is absent.
		 *
		 * @param  array $template_data Template creation data passed through the filter.
		 * @return array Possibly modified template data.
		 */
		public function inject_listing_settings( $template_data ) {

			// phpcs:disable WordPress.Security.NonceVerification
			if ( empty( $_REQUEST['listing_view_type'] ) || 'blocks' !== $_REQUEST['listing_view_type'] ) {
				return $template_data;
			}

			if ( ! isset( $_REQUEST['listing_source'] ) ) {
				return $template_data;
			}
			// phpcs:enable WordPress.Security.NonceVerification

			return $template_data;

		}

	}

}
