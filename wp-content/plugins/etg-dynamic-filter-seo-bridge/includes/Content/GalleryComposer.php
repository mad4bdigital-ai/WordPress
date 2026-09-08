<?php
namespace ETG\DynamicFilterSEOBridge\Content;

final class GalleryComposer {
    public function ids( array $context, string $mode = 'combined' ): array {
        $mode = sanitize_key( $mode );
        if ( '' === $mode ) { $mode = 'combined'; }
        $collections = $this->collections( $context );
        if ( isset( $collections[ $mode ] ) ) { return $collections[ $mode ]; }
        if ( in_array( $mode, $this->activeRoles( $context ), true ) ) { return $this->roleIds( $context, $mode, true ); }
        return array();
    }

    public function collections( array $context ): array {
        $rolesPriority = $this->orderedRoles( $context, false );
        $rolesGallery = $this->orderedRoles( $context, true );
        $combined = array(); $galleriesOnly = array(); $primaryImages = array(); $termBuckets = array(); $roleBuckets = array();

        foreach ( $rolesGallery as $role ) {
            $roleBuckets[ $role ] = array();
            foreach ( $this->termSet( $context, $role ) as $index => $term ) {
                $all = $this->termIds( $term, true );
                $gallery = $this->termIds( $term, false );
                $bucketKey = $role . ':' . (string) ( $term['term_id'] ?? $index );
                if ( $all ) { $termBuckets[ $bucketKey ] = $all; $roleBuckets[ $role ] = array_merge( $roleBuckets[ $role ], $all ); }
                $combined = array_merge( $combined, $all );
                $galleriesOnly = array_merge( $galleriesOnly, $gallery );
                $primary = (int) ( $term['image_id'] ?? 0 );
                if ( ! $primary && $all ) { $primary = (int) reset( $all ); }
                if ( $primary ) { $primaryImages[] = $primary; }
            }
            $roleBuckets[ $role ] = $this->uniqueIds( $roleBuckets[ $role ] );
        }

        $priority = array();
        foreach ( $rolesGallery as $role ) { if ( ! empty( $roleBuckets[ $role ] ) ) { $priority = $roleBuckets[ $role ]; break; } }

        $rolePriorityCombined = array();
        foreach ( $rolesPriority as $role ) { $rolePriorityCombined = array_merge( $rolePriorityCombined, $this->roleIds( $context, $role, true ) ); }

        return array(
            'combined' => $this->uniqueIds( $combined ),
            'all_terms' => $this->uniqueIds( $combined ),
            'priority' => $this->uniqueIds( $priority ),
            'galleries_only' => $this->uniqueIds( $galleriesOnly ),
            'primary_images' => $this->uniqueIds( $primaryImages ),
            'balanced' => $this->uniqueIds( $this->roundRobin( $termBuckets ) ),
            'role_priority' => $this->uniqueIds( $rolePriorityCombined ),
        );
    }

    public function trace( array $context, string $mode = 'combined', int $limit = 30 ): array {
        $mode = sanitize_key( $mode ); if ( '' === $mode ) { $mode = 'combined'; }
        $limit = max( 1, min( 100, $limit ) ); $ids = array_slice( $this->ids( $context, $mode ), 0, $limit ); $rows = array();
        foreach ( $ids as $id ) {
            $sources = array();
            foreach ( $this->activeRoles( $context ) as $role ) {
                foreach ( $this->termSet( $context, $role ) as $term ) {
                    foreach ( (array) ( $term['media_sources']['image'] ?? array() ) as $source ) {
                        if ( in_array( (int) $id, array_map( 'absint', (array) ( $source['ids'] ?? array() ) ), true ) ) { $sources[] = $this->traceSource( $role, $term, 'image', $source ); }
                    }
                    foreach ( (array) ( $term['media_sources']['gallery'] ?? array() ) as $source ) {
                        if ( in_array( (int) $id, array_map( 'absint', (array) ( $source['ids'] ?? array() ) ), true ) ) { $sources[] = $this->traceSource( $role, $term, 'gallery', $source ); }
                    }
                }
            }
            $rows[] = array( 'id'=>(int)$id, 'sources'=>$sources, 'authorizing'=>false );
        }
        return array( 'contract'=>'etg.dfsb.media-trace.v1', 'authorizing'=>false, 'mode'=>$mode, 'count'=>count($rows), 'items'=>$rows );
    }

    public function render( array $context, array $attributes = array() ): string {
        $attributes = array_merge( array( 'mode' => 'combined', 'limit' => 9, 'size' => 'large' ), $attributes );
        $mode = sanitize_key( (string) $attributes['mode'] ); if ( '' === $mode ) { $mode = 'combined'; }
        $limit = max( 1, min( 30, absint( $attributes['limit'] ) ) ); $size = sanitize_key( (string) $attributes['size'] );
        $ids = array_slice( $this->ids( $context, $mode ), 0, $limit ); if ( ! $ids ) { return ''; }
        $images = array();
        foreach ( $ids as $id ) { $image = wp_get_attachment_image( $id, $size ?: 'large', false, array( 'loading' => 'lazy', 'decoding' => 'async' ) ); if ( $image ) { $images[] = '<figure class="etg-filter-gallery__item">' . $image . '</figure>'; } }
        return $images ? '<div class="etg-filter-gallery etg-filter-gallery--' . esc_attr( $mode ) . '">' . implode( '', $images ) . '</div>' : '';
    }

    public function modeOptions( array $context = array() ): array {
        $out = array(
            'combined' => 'Combined media from all active Terms',
            'all_terms' => 'All active Terms',
            'priority' => 'First gallery-priority role with media',
            'galleries_only' => 'Gallery fields only across Terms',
            'primary_images' => 'One primary image per active Term',
            'balanced' => 'Balanced / round-robin across Terms',
            'role_priority' => 'Combined by profile role priority',
        );
        foreach ( $this->activeRoles( $context ) as $role ) { $out[ $role ] = ucwords( str_replace( '_', ' ', $role ) ) . ' only'; }
        return $out;
    }

    private function orderedRoles( array $context, bool $galleryPriority ): array {
        $rolesAvailable = array_fill_keys( $this->activeRoles( $context ), true ); $rules=(array)($context['profile']['taxonomy_rules']??array()); $rows=array();
        foreach($rules as$taxonomy=>$rule){$role=sanitize_key((string)($rule['role']??$taxonomy));if(''===$role||!isset($rolesAvailable[$role])){continue;}$key=$galleryPriority?'gallery_priority':'priority';$rows[]=array('role'=>$role,'priority'=>(int)($rule[$key]??($rule['priority']??100)),'taxonomy'=>(string)$taxonomy);}
        usort($rows,static function($a,$b){$cmp=$a['priority']<=>$b['priority'];return 0!==$cmp?$cmp:strcmp($a['taxonomy'],$b['taxonomy']);});
        $roles=array();foreach($rows as$row){if(!in_array($row['role'],$roles,true)){$roles[]=$row['role'];}}
        if(!$roles){$legacy=$galleryPriority?array('style','location','tour_type'):array('location','tour_type','style');foreach($legacy as$role){if(isset($rolesAvailable[$role])){$roles[]=$role;}}}
        foreach(array_keys($rolesAvailable)as$role){if(!in_array($role,$roles,true)){$roles[]=(string)$role;}}return$roles;
    }

    private function activeRoles( array $context ): array {
        $roles=array();foreach(array_keys((array)($context['term_sets']??array()))as$role){$role=sanitize_key((string)$role);if($role&&!in_array($role,$roles,true)){$roles[]=$role;}}
        foreach(array_keys((array)($context['terms']??array()))as$role){$role=sanitize_key((string)$role);if($role&&!in_array($role,$roles,true)){$roles[]=$role;}}return$roles;
    }

    private function termSet( array $context, string $role ): array {
        $role = sanitize_key( $role );
        $set = (array) ( $context['term_sets'][ $role ] ?? array() );
        $primary = isset( $context['terms'][ $role ] ) && is_array( $context['terms'][ $role ] ) ? (array) $context['terms'][ $role ] : array();
        if ( ! $set && $primary ) { $set = array( $primary ); }

        $out = array();
        foreach ( array_slice( $set, 0, 20 ) as $term ) {
            if ( ! is_array( $term ) || ! $term ) { continue; }
            if ( $primary && $this->sameTerm( $term, $primary ) ) {
                // term_sets is selection authority, while terms[role] may carry the richer
                // governed media/content payload for that same selected Term.
                $term = array_merge( $primary, $term );
            }
            $out[] = $term;
        }
        return $out;
    }

    private function sameTerm( array $left, array $right ): bool {
        $leftId = (int) ( $left['term_id'] ?? 0 );
        $rightId = (int) ( $right['term_id'] ?? 0 );
        if ( $leftId > 0 && $rightId > 0 ) { return $leftId === $rightId; }

        $leftSlug = trim( (string) ( $left['slug'] ?? '' ) );
        $rightSlug = trim( (string) ( $right['slug'] ?? '' ) );
        return '' !== $leftSlug && '' !== $rightSlug && $leftSlug === $rightSlug;
    }

    private function roleIds( array $context, string $role, bool $includePrimary ): array { $ids=array();foreach($this->termSet($context,$role)as$term){$ids=array_merge($ids,$this->termIds($term,$includePrimary));}return$this->uniqueIds($ids); }

    private function termIds( array $term, bool $includePrimary ): array {
        $ids = isset( $term['gallery_ids'] ) ? (array) $term['gallery_ids'] : array();
        if ( ! $includePrimary && ! empty( $term['image_id'] ) ) { $ids = array_values( array_diff( array_map( 'absint', $ids ), array( (int) $term['image_id'] ) ) ); }
        if ( $includePrimary && ! empty( $term['image_id'] ) && ! in_array( (int) $term['image_id'], array_map('absint',$ids), true ) ) { array_unshift( $ids, (int) $term['image_id'] ); }
        return $this->uniqueIds( $ids );
    }

    private function traceSource(string$role,array$term,string$kind,array$source):array{return array('role'=>$role,'term_id'=>(int)($term['term_id']??0),'term_name'=>(string)($term['name']??''),'term_slug'=>(string)($term['slug']??''),'kind'=>$kind,'key'=>(string)($source['key']??''));}

    private function roundRobin( array $buckets ): array {
        $out = array(); $index = 0; $remaining = true; $guard = 0;
        while ( $remaining && $guard < 100 ) { $remaining = false; foreach ( $buckets as $bucket ) { if ( isset( $bucket[ $index ] ) ) { $out[] = (int) $bucket[ $index ]; $remaining = true; } } $index++; $guard++; }
        return $out;
    }

    private function uniqueIds( array $ids ): array { return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ); }
}
