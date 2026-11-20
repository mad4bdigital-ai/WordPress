<?php
/**
 * Plugin Name: TripKlik Admin Media Tools
 * Description: Hardened admin media helpers: media folder menu, uploaded-to-post filter restore, and optional selected-media-first layout.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'TK_ADMIN_MEDIA_TOOLS_ENABLED' ) && ! TK_ADMIN_MEDIA_TOOLS_ENABLED ) {
    return;
}

if ( ! function_exists( 'tk_admin_media_tools_bool' ) ) {
    function tk_admin_media_tools_bool( string $constant, bool $default = false ): bool {
        return defined( $constant ) ? (bool) constant( $constant ) : $default;
    }
}

if ( ! function_exists( 'tk_admin_media_tools_allowed_post_screen' ) ) {
    function tk_admin_media_tools_allowed_post_screen(): bool {
        global $pagenow;

        if ( ! is_admin() || ! in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) ) {
            return false;
        }

        $allowed_raw = defined( 'TK_ADMIN_MEDIA_TOOLS_POST_TYPES' ) ? (string) TK_ADMIN_MEDIA_TOOLS_POST_TYPES : '';
        $allowed     = array_values( array_filter( array_map( 'trim', explode( ',', $allowed_raw ) ) ) );

        if ( empty( $allowed ) ) {
            return true;
        }

        $post_type = '';
        if ( isset( $_GET['post_type'] ) ) {
            $post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) );
        }

        if ( ! $post_type && isset( $_GET['post'] ) ) {
            $post = get_post( absint( $_GET['post'] ) );
            if ( $post instanceof WP_Post ) {
                $post_type = $post->post_type;
            }
        }

        return $post_type && in_array( $post_type, $allowed, true );
    }
}

if ( tk_admin_media_tools_bool( 'TK_MEDIA_FOLDER_MENU_ENABLED', true ) ) {
    add_action( 'admin_menu', 'tk_add_media_folders_to_admin_menu', 99 );
    add_filter( 'parent_file', 'tk_highlight_media_folders_menu' );

    function tk_add_media_folders_to_admin_menu(): void {
        if ( ! current_user_can( 'upload_files' ) || ! taxonomy_exists( 'media_folder' ) ) {
            return;
        }

        add_submenu_page(
            'upload.php',
            __( 'إدارة المجلدات (Folders)', 'tk-admin-media-tools' ),
            __( 'إدارة المجلدات (Folders)', 'tk-admin-media-tools' ),
            'upload_files',
            'edit-tags.php?taxonomy=media_folder&post_type=attachment'
        );
    }

    function tk_highlight_media_folders_menu( $parent_file ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && isset( $screen->taxonomy ) && 'media_folder' === $screen->taxonomy ) {
            return 'upload.php';
        }

        return $parent_file;
    }
}

if ( tk_admin_media_tools_bool( 'TK_RESTORE_UPLOADED_FILTER_ENABLED', true ) ) {
    add_action( 'admin_footer', 'tk_restore_uploaded_filter_for_custom_fields', 30 );

    function tk_restore_uploaded_filter_for_custom_fields(): void {
        if ( ! tk_admin_media_tools_allowed_post_screen() ) {
            return;
        }
        ?>
        <script>
        (function($){
            if (window.tkUploadedFilterRestored) return;
            window.tkUploadedFilterRestored = true;

            $(function(){
                if (!window.wp || !wp.media || !wp.media.view || !wp.media.view.AttachmentsBrowser) return;
                if (!wp.media.view.AttachmentFilters || !wp.media.view.AttachmentFilters.Uploaded) return;

                var OriginalAttachmentsBrowser = wp.media.view.AttachmentsBrowser;

                wp.media.view.AttachmentsBrowser = OriginalAttachmentsBrowser.extend({
                    createToolbar: function() {
                        OriginalAttachmentsBrowser.prototype.createToolbar.apply(this, arguments);

                        if (!this.toolbar || this.toolbar.get('filters')) return;
                        if (!wp.media.view.settings || !wp.media.view.settings.post || !wp.media.view.settings.post.id) return;

                        this.toolbar.set('filters', new wp.media.view.AttachmentFilters.Uploaded({
                            controller: this.controller,
                            model: this.collection.props,
                            priority: -80
                        }).render());
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}

if ( tk_admin_media_tools_bool( 'TK_SELECTED_MEDIA_TO_TOP_ENABLED', false ) ) {
    add_action( 'admin_head', 'tk_selected_media_to_top_css' );

    function tk_selected_media_to_top_css(): void {
        if ( ! tk_admin_media_tools_allowed_post_screen() ) {
            return;
        }
        ?>
        <style>
            .media-modal .attachments.tk-selected-media-flex,
            .media-modal .attachments {
                display: flex !important;
                flex-wrap: wrap !important;
                align-content: flex-start;
            }
            .media-modal .attachment {
                float: none !important;
                order: 2;
            }
            .media-modal .attachment.selected {
                order: 1 !important;
            }
            .media-modal .attachments::after,
            .media-modal .attachments::before {
                display: none !important;
            }
        </style>
        <?php
    }
}
