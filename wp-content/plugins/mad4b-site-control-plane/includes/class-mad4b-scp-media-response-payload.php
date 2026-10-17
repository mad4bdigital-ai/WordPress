<?php
/** Read-only WordPress attachment response formatting, separate from mutations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Media_Response_Payload {
	public static function from_post( $post, $include_details = true ) {
		$file = get_attached_file( $post->ID, true );
		$metadata = wp_get_attachment_metadata( $post->ID );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$encoded = wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$filesize = $file && is_file( $file )
			? (int) filesize( $file )
			: ( isset( $metadata['filesize'] ) ? absint( $metadata['filesize'] ) : 0 );

		$width = isset( $metadata['width'] ) ? absint( $metadata['width'] ) : 0;
		$height = isset( $metadata['height'] ) ? absint( $metadata['height'] ) : 0;
		$payload = array(
			'id' => (int) $post->ID,
			'parent_id' => (int) $post->post_parent,
			'author_id' => (int) $post->post_author,
			'status' => (string) $post->post_status,
			'date_gmt' => (string) $post->post_date_gmt,
			'modified_gmt' => (string) $post->post_modified_gmt,
			'title' => (string) $post->post_title,
			'caption' => (string) $post->post_excerpt,
			'alt' => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'mime_type' => (string) $post->post_mime_type,
			'is_image' => (bool) wp_attachment_is_image( $post->ID ),
			'url' => (string) wp_get_attachment_url( $post->ID ),
			'file' => $file ? basename( $file ) : '',
			'file_extension' => $file ? strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) : '',
			'filesize' => $filesize,
			'width' => $width,
			'height' => $height,
			'aspect_ratio' => $width > 0 && $height > 0 ? round( $width / $height, 6 ) : 0,
			'generated_size_count' => isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? count( $metadata['sizes'] ) : 0,
			'embedded_metadata_present' => ! empty( $metadata['image_meta'] ) && is_array( $metadata['image_meta'] ),
			'metadata_sha256' => hash( 'sha256', false === $encoded ? '' : $encoded ),
			'detail_level' => $include_details ? 'full' : 'summary',
		);
		if ( $include_details ) {
			$payload['description'] = (string) $post->post_content;
			$payload['sizes'] = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
			$payload['image_meta'] = isset( $metadata['image_meta'] ) && is_array( $metadata['image_meta'] ) ? $metadata['image_meta'] : array();
			$payload['metadata'] = $metadata;
		}
		return $payload;
	}

}
