<?php
namespace ETG\DynamicFilterSEOBridge\Content;

final class GalleryComposer {
	public function ids( array $context, string $mode = 'combined' ): array {
		$terms = isset( $context['terms'] ) ? (array) $context['terms'] : array();
		$roles = $this->orderedRoles( $context, 'priority' === $mode );
		if ( 'priority' === $mode ) {
			foreach ( $roles as $role ) {
				$ids = $this->termIds( isset( $terms[ $role ] ) ? $terms[ $role ] : array() );
				if ( $ids ) { return $ids; }
			}
			return array();
		}
		$ids = array();
		foreach ( $roles as $role ) { $ids = array_merge( $ids, $this->termIds( isset( $terms[ $role ] ) ? $terms[ $role ] : array() ) ); }
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	public function render( array $context, array $attributes = array() ): string {
		$attributes = array_merge( array( 'mode' => 'combined', 'limit' => 9, 'size' => 'large' ), $attributes );
		$mode = in_array( $attributes['mode'], array( 'combined', 'priority' ), true ) ? $attributes['mode'] : 'combined';
		$limit = max( 1, min( 30, absint( $attributes['limit'] ) ) );
		$size = sanitize_key( $attributes['size'] );
		$ids = array_slice( $this->ids( $context, $mode ), 0, $limit );
		if ( ! $ids ) { return ''; }
		$images = array();
		foreach ( $ids as $id ) {
			$image = wp_get_attachment_image( $id, $size ?: 'large', false, array( 'loading' => 'lazy', 'decoding' => 'async' ) );
			if ( $image ) { $images[] = '<figure class="etg-filter-gallery__item">' . $image . '</figure>'; }
		}
		return $images ? '<div class="etg-filter-gallery etg-filter-gallery--' . esc_attr( $mode ) . '">' . implode( '', $images ) . '</div>' : '';
	}

	private function orderedRoles( array $context, bool $galleryPriority ): array {
		$terms=(array)($context['terms']??array());
		$rules=(array)($context['profile']['taxonomy_rules']??array());
		$rows=array();
		foreach($rules as $taxonomy=>$rule){
			$role=sanitize_key((string)($rule['role']??$taxonomy));
			if(''===$role||!isset($terms[$role])){continue;}
			$key=$galleryPriority?'gallery_priority':'priority';
			$rows[]=array('role'=>$role,'priority'=>(int)($rule[$key]??($rule['priority']??100)),'taxonomy'=>(string)$taxonomy);
		}
		usort($rows,static function($a,$b){$cmp=$a['priority']<=>$b['priority'];return 0!==$cmp?$cmp:strcmp($a['taxonomy'],$b['taxonomy']);});
		$roles=array();foreach($rows as $row){$roles[]=$row['role'];}
		if(!$roles){
			$legacy=$galleryPriority?array('style','location','tour_type'):array('location','tour_type','style');
			foreach($legacy as $role){if(isset($terms[$role])){$roles[]=$role;}}
		}
		foreach(array_keys($terms) as $role){if(!in_array($role,$roles,true)){$roles[]=(string)$role;}}
		return $roles;
	}

	private function termIds( array $term ): array {
		$ids = isset( $term['gallery_ids'] ) ? (array) $term['gallery_ids'] : array();
		if ( ! $ids && ! empty( $term['image_id'] ) ) { $ids[] = (int) $term['image_id']; }
		return $ids;
	}
}
