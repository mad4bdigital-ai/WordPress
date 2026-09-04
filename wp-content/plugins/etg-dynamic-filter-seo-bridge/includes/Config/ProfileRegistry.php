<?php
namespace ETG\DynamicFilterSEOBridge\Config;

final class ProfileRegistry {
	private $config;
	private $profiles;
	private $normalizationErrors = array();

	public function __construct( Configuration $config ) { $this->config = $config; }

	public function all(): array {
		if ( null !== $this->profiles ) { return $this->profiles; }
		$raw = (string) $this->config->get( 'profiles_json', '' );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) { $decoded = array(); }
		if(count($decoded)>50){$this->normalizationErrors[]='profile_count_limit_exceeded';}
		$out = array();
		foreach ( array_slice( $decoded, 0, 50 ) as $profile ) {
			if ( ! is_array( $profile ) ) { continue; }
			$profile = $this->normalizeProfile( $profile );
			if ( '' === $profile['id'] ) { $this->normalizationErrors[]='profile_id_empty'; continue; }
			if ( isset( $out[ $profile['id'] ] ) ) { $this->normalizationErrors[]='duplicate_profile_id:' . $profile['id']; continue; }
			$out[ $profile['id'] ] = $profile;
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered=(array)apply_filters('etg_filter_seo_surface_profiles',$out,$this->config);
			$normalized=array();
			foreach(array_slice($filtered,0,50,true) as $key=>$candidate){
				if(!is_array($candidate)){continue;} if(empty($candidate['id'])&&is_string($key)){$candidate['id']=$key;}
				$candidate=$this->normalizeProfile($candidate);$id=(string)$candidate['id'];if(''===$id){$this->normalizationErrors[]='filtered_profile_id_empty';continue;}
				if(isset($normalized[$id])){$this->normalizationErrors[]='duplicate_filtered_profile_id:'.$id;continue;} $normalized[$id]=$candidate;
			}
			$out=$normalized;
		}
		$this->profiles = $out;
		return $out;
	}

	public function get( string $id ): array {
		$profiles = $this->all();
		$id = sanitize_key( $id );
		return isset( $profiles[ $id ] ) ? (array) $profiles[ $id ] : array();
	}

	public function allowedTaxonomies(): array {
		$out = array();
		foreach ( $this->all() as $profile ) {
			foreach ( array_keys( (array) $profile['taxonomy_rules'] ) as $taxonomy ) { $out[] = sanitize_key( $taxonomy ); }
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	public function resolve( array $parsed ): array {
		$archive = sanitize_title( (string) ( $parsed['archive'] ?? '' ) );
		$archivePath = $this->normalizeArchivePath( (string) ( $parsed['archive_path'] ?? '' ) );
		$provider = sanitize_key( (string) ( $parsed['provider'] ?? '' ) );
		$queryId = sanitize_key( (string) ( $parsed['query_id'] ?? '' ) );
		$profiles = $this->all();
		$archiveMatches = array();
		foreach ( $profiles as $profile ) {
			if ( $this->archiveMatches( $profile, $archive, $archivePath ) ) { $archiveMatches[] = $profile; }
		}
		if ( ! $archiveMatches ) { return $this->resolution( false, false, 'archive_not_profiled' ); }
		$enabled = array_values( array_filter( $archiveMatches, static function ( $profile ) { return ! empty( $profile['enabled'] ); } ) );
		if ( ! $enabled ) { return $this->resolution( false, false, 'profile_disabled', $archiveMatches[0] ); }
		$providerMatches = array();
		foreach ( $enabled as $profile ) { if ( $this->profileSupportsProvider( $profile, $provider ) ) { $providerMatches[] = $profile; } }
		if ( ! $providerMatches ) { return $this->resolution( true, false, 'provider_not_profiled', $enabled[0] ); }
		$routeMatches = array();
		foreach ( $providerMatches as $profile ) { if ( $this->profileSupportsRoute( $profile, $provider, $queryId ) ) { $routeMatches[] = $profile; } }
		if ( ! $routeMatches ) { return $this->resolution( true, false, 'query_not_profiled', $providerMatches[0] ); }
		if ( 1 !== count( $routeMatches ) ) { return $this->resolution( true, false, 'ambiguous_profile', $routeMatches[0] ); }
		$profile = $routeMatches[0];
		$rules = (array) $profile['taxonomy_rules'];
		foreach ( array_keys( (array) ( $parsed['filters'] ?? array() ) ) as $taxonomy ) {
			if ( ! isset( $rules[ sanitize_key( (string) $taxonomy ) ] ) ) { return $this->resolution( true, false, 'taxonomy_not_profiled', $profile ); }
		}
		return $this->resolution( true, true, 'profile_matched', $profile );
	}

	public function validationErrors(): array {
		$errors = (array) $this->normalizationErrors;
		$profiles = $this->all();
		if ( ! $profiles ) { return array( 'profiles_empty_or_invalid' ); }
		$seenKeys = array();
		$enabledCount = 0;
		foreach ( $profiles as $id => $profile ) {
			if ( empty( $profile['enabled'] ) ) { continue; }
			$enabledCount++;
			if ( empty( $profile['archive_slugs'] ) && empty( $profile['archive_paths'] ) ) { $errors[] = 'profile:' . $id . ':empty_archive_authority'; }
			if ( empty( $profile['inherit_global_defaults'] ) && empty( $profile['archive_paths'] ) ) { $errors[] = 'profile:' . $id . ':exact_archive_paths_required'; }
			if ( empty( $profile['routes'] ) && ( empty( $profile['providers'] ) || empty( $profile['query_ids'] ) ) ) { $errors[] = 'profile:' . $id . ':empty_route_authority'; }
			if ( empty( $profile['inherit_global_defaults'] ) && empty( $profile['routes'] ) ) { $errors[] = 'profile:' . $id . ':exact_routes_required'; }
			if ( empty( $profile['taxonomy_rules'] ) ) { $errors[] = 'profile:' . $id . ':empty_taxonomy_rules'; }
			if ( empty( $profile['allowed_taxonomy_sets'] ) ) { $errors[] = 'profile:' . $id . ':empty_allowed_taxonomy_sets'; }
			if ( ! empty( $profile['require_post_type_binding'] ) && empty( $profile['post_types'] ) ) { $errors[] = 'profile:' . $id . ':post_type_binding_without_post_types'; }
			if ( ! empty( $profile['require_post_type_binding'] ) && ! in_array( (string) ( $profile['post_type_authority'] ?? '' ), array( 'query_builder', 'main_query', 'either', 'both' ), true ) ) { $errors[] = 'profile:' . $id . ':invalid_post_type_authority'; }
			$roles = array();
			foreach ( (array) $profile['taxonomy_rules'] as $taxonomy => $rule ) {
				$role = sanitize_key( (string) ( $rule['role'] ?? '' ) );
				if ( '' === $role ) { $errors[] = 'profile:' . $id . ':empty_role:' . $taxonomy; continue; }
				if ( isset( $roles[ $role ] ) ) { $errors[] = 'profile:' . $id . ':duplicate_role:' . $role; }
				$roles[ $role ] = true;
			}
			$archives=array();
			foreach((array)$profile['archive_slugs'] as $archive){$archives[]=$this->canonicalArchiveAuthority('/'.sanitize_title((string)$archive).'/');}
			foreach((array)$profile['archive_paths'] as $path){$archives[]=$this->canonicalArchiveAuthority((string)$path);}
			$archives=array_values(array_unique(array_filter($archives)));
			$routes=(array)$profile['routes'];
			if(!$routes&&!empty($profile['inherit_global_defaults'])){foreach((array)$profile['providers'] as $provider){foreach((array)$profile['query_ids'] as $query){$routes[]=array('provider'=>$provider,'query_id'=>$query);}}}
			foreach($archives as $archiveAuthority){foreach($routes as $route){
				$key='path:'.$archiveAuthority.'|'.(string)($route['provider']??'').'|'.(string)($route['query_id']??'');
				if(isset($seenKeys[$key])&&$seenKeys[$key]!==$id){$errors[]='ambiguous_profile_match:'.$key;}
				else{$seenKeys[$key]=$id;}
			}}
		}
		if ( 0 === $enabledCount ) { $errors[] = 'no_enabled_profiles'; }
		return array_values( array_unique( $errors ) );
	}

	public function discovery(): array {
		$out = array( 'post_types' => array(), 'taxonomies' => array() );
		if ( function_exists( 'get_post_types' ) ) {
			$objects = get_post_types( array( 'public' => true ), 'objects' );
			foreach ( array_slice( is_array( $objects ) ? $objects : array(), 0, 100, true ) as $name => $object ) {
				$out['post_types'][ sanitize_key( (string) $name ) ] = array( 'label' => isset( $object->label ) ? (string) $object->label : (string) $name );
			}
		}
		if ( function_exists( 'get_taxonomies' ) ) {
			$objects = get_taxonomies( array( 'public' => true ), 'objects' );
			foreach ( array_slice( is_array( $objects ) ? $objects : array(), 0, 150, true ) as $name => $object ) {
				$out['taxonomies'][ sanitize_key( (string) $name ) ] = array(
					'label' => isset( $object->label ) ? (string) $object->label : (string) $name,
					'object_type' => array_values( array_map( 'sanitize_key', isset( $object->object_type ) ? (array) $object->object_type : array() ) ),
				);
			}
		}
		return $out;
	}

	public function blueprint( string $postType, array $taxonomies, string $profileId = '' ): array {
		$postType=sanitize_key($postType);
		$profileId=sanitize_key($profileId?:$postType);
		$discovery=$this->discovery();
		$warnings=array();
		if(''===$postType||!isset($discovery['post_types'][$postType])){$warnings[]='post_type_not_discovered';}
		$rules=array();$priority=10;$accepted=array();
		foreach(array_slice($taxonomies,0,20) as $taxonomy){
			$taxonomy=sanitize_key((string)$taxonomy);if(''===$taxonomy){continue;}
			$object=(array)($discovery['taxonomies'][$taxonomy]??array());
			if(!$object){$warnings[]='taxonomy_not_discovered:'.$taxonomy;continue;}
			if($postType&&!in_array($postType,(array)($object['object_type']??array()),true)){$warnings[]='taxonomy_not_attached:'.$taxonomy;continue;}
			$rules[$taxonomy]=array('role'=>$taxonomy,'priority'=>$priority,'gallery_priority'=>$priority,'index_single'=>false,'min_results'=>3,'required_meta_key'=>'','required_meta_values'=>array(),'meta_constraint_scope'=>'single','field_map'=>array());
			$accepted[]=$taxonomy;$priority+=10;
		}
		$profile=array(
			'id'=>$profileId,'enabled'=>false,'inherit_global_defaults'=>false,'post_types'=>$postType?array($postType):array(),'require_post_type_binding'=>true,'post_type_authority'=>'query_builder',
			'archive_slugs'=>array(),'archive_paths'=>array(),'providers'=>array(),'query_ids'=>array(),'routes'=>array(),'max_filters'=>min(10,max(1,count($accepted))),
			'composition_mode'=>'generic','canonical_mode'=>'filtered','require_exact_combination_approval'=>true,'require_exact_for_single'=>false,
			'allowed_taxonomy_sets'=>array(),'min_results_by_depth'=>array('1'=>3,'2'=>3,'3'=>3),'taxonomy_rules'=>$rules,'indexable_combinations'=>array(),
			'content'=>array('required'=>true,'require_meta_description'=>true,'min_chars'=>80),
		);
		return array('contract'=>'etg.dfsb.profile-blueprint.v1','synthetic'=>true,'authorizing'=>false,'warnings'=>array_values(array_unique($warnings)),'profile'=>$profile);
	}

	public static function taxonomySetSignature( array $filters ): string {
		$taxonomies = array_values( array_filter( array_map( 'sanitize_key', array_keys( $filters ) ) ) );
		sort( $taxonomies, SORT_STRING );
		return implode( '+', $taxonomies );
	}

	private function normalizeProfile( array $profile ): array {
		$id = sanitize_key( (string) ( $profile['id'] ?? '' ) );
		if(count((array)($profile['routes']??array()))>20){$this->normalizationErrors[]='profile:'.$id.':route_limit_exceeded';}
		if(count((array)($profile['taxonomy_rules']??array()))>50){$this->normalizationErrors[]='profile:'.$id.':taxonomy_rule_limit_exceeded';}
		if(count((array)($profile['indexable_combinations']??array()))>5000){$this->normalizationErrors[]='profile:'.$id.':combination_registry_limit_exceeded';}
		$out = array(
			'id' => $id,
			'enabled' => $this->boolValue( $profile['enabled'] ?? false ),
			'inherit_global_defaults' => $this->boolValue( $profile['inherit_global_defaults'] ?? false ),
			'post_types' => $this->listValue( $profile['post_types'] ?? array(), 'sanitize_key' ),
			'require_post_type_binding' => $this->boolValue( $profile['require_post_type_binding'] ?? false ),
			'post_type_authority' => $this->enumValue( $profile['post_type_authority'] ?? 'query_builder', array( 'query_builder', 'main_query', 'either', 'both' ), 'query_builder' ),
			'archive_slugs' => $this->listValue( $profile['archive_slugs'] ?? array(), 'sanitize_title' ),
			'archive_paths' => $this->pathList( $profile['archive_paths'] ?? array() ),
			'providers' => $this->listValue( $profile['providers'] ?? array(), 'sanitize_key' ),
			'query_ids' => $this->listValue( $profile['query_ids'] ?? array(), 'sanitize_key' ),
			'routes' => $this->routesValue( $profile['routes'] ?? array() ),
			'max_filters' => $this->boundedInt( $profile['max_filters'] ?? 3, 1, 10 ),
			'composition_mode' => $this->enumValue( $profile['composition_mode'] ?? 'generic', array( 'generic', 'travel' ), 'generic' ),
			'canonical_mode' => $this->enumValue( $profile['canonical_mode'] ?? 'filtered', array( 'filtered', 'archive' ), 'filtered' ),
			'require_exact_combination_approval' => $this->boolValue( $profile['require_exact_combination_approval'] ?? true ),
			'require_exact_for_single' => $this->boolValue( $profile['require_exact_for_single'] ?? false ),
			'indexable_combinations' => array_slice( $this->lineList( $profile['indexable_combinations'] ?? array() ), 0, 5000 ),
			'allowed_taxonomy_sets' => array(),
			'min_results_by_depth' => array(),
			'taxonomy_rules' => array(),
			'content' => array(),
		);
		foreach ( array_slice( (array) ( $profile['taxonomy_rules'] ?? array() ), 0, 50, true ) as $taxonomy => $rule ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( '' === $taxonomy || ! is_array( $rule ) ) { continue; }
			$requiredMetaValues = $this->listValue( $rule['required_meta_values'] ?? array(), 'sanitize_title' );
			$scope = sanitize_key( (string) ( $rule['meta_constraint_scope'] ?? 'single' ) );
			$out['taxonomy_rules'][ $taxonomy ] = array(
				'role' => sanitize_key( (string) ( $rule['role'] ?? $taxonomy ) ) ?: $taxonomy,
				'priority' => $this->boundedInt( $rule['priority'] ?? 100, 0, 10000 ),
				'gallery_priority' => $this->boundedInt( $rule['gallery_priority'] ?? ( $rule['priority'] ?? 100 ), 0, 10000 ),
				'index_single' => $this->boolValue( $rule['index_single'] ?? false ),
				'min_results' => $this->boundedInt( $rule['min_results'] ?? 1, 1, 1000000 ),
				'required_meta_key' => sanitize_key( (string) ( $rule['required_meta_key'] ?? '' ) ),
				'required_meta_values' => $requiredMetaValues,
				'meta_constraint_scope' => in_array( $scope, array( 'single', 'always' ), true ) ? $scope : 'single',
				'field_map' => $this->fieldMapValue( $rule['field_map'] ?? array() ),
			);
		}
		foreach ( (array) ( $profile['allowed_taxonomy_sets'] ?? array() ) as $set ) {
			$signature = $this->normalizeTaxonomySet( $set );
			if ( '' !== $signature ) { $out['allowed_taxonomy_sets'][] = $signature; }
		}
		$out['allowed_taxonomy_sets'] = array_values( array_unique( $out['allowed_taxonomy_sets'] ) );
		foreach ( (array) ( $profile['min_results_by_depth'] ?? array() ) as $depth => $minimum ) {
			$depth = is_numeric( $depth ) ? (int) $depth : 0;
			if ( $depth < 1 || $depth > 10 ) { continue; }
			$out['min_results_by_depth'][ (string) $depth ] = $this->boundedInt( $minimum, 1, 1000000 );
		}
		$content = is_array( $profile['content'] ?? null ) ? $profile['content'] : array();
		$out['content'] = array(
			'required' => $this->boolValue( $content['required'] ?? true ),
			'require_meta_description' => $this->boolValue( $content['require_meta_description'] ?? true ),
			'min_chars' => $this->boundedInt( $content['min_chars'] ?? 80, 0, 10000 ),
		);
		if ( ! empty( $out['inherit_global_defaults'] ) ) { $out = $this->applyGlobalDefaults( $out ); }
		$out = $this->filterAllowedTaxonomySets( $out );
		return $out;
	}

	private function applyGlobalDefaults( array $profile ): array {
		$profile['archive_slugs'] = (array) $this->config->get( 'archive_slugs', $profile['archive_slugs'] );
		$profile['providers'] = (array) $this->config->get( 'providers', $profile['providers'] );
		$profile['query_ids'] = (array) $this->config->get( 'query_ids', $profile['query_ids'] );
		$profile['archive_paths']=array(); foreach($profile['archive_slugs'] as $slug){$profile['archive_paths'][]=$this->normalizeArchivePath('/'.$slug.'/');}
		$profile['routes']=array(); foreach($profile['providers'] as $provider){foreach($profile['query_ids'] as $query){$profile['routes'][]=array('provider'=>$provider,'query_id'=>$query);}}
		$profile['max_filters'] = (int) $this->config->get( 'max_filters', $profile['max_filters'] );
		$allowed = (array) $this->config->get( 'allowed_taxonomies', array_keys( $profile['taxonomy_rules'] ) );
		$rules = array(); $priority = 100;
		foreach ( $allowed as $taxonomy ) {
			$taxonomy = sanitize_key( (string) $taxonomy ); if ( '' === $taxonomy ) { continue; }
			if ( isset( $profile['taxonomy_rules'][ $taxonomy ] ) ) { $rules[ $taxonomy ] = $profile['taxonomy_rules'][ $taxonomy ]; }
			else { $rules[ $taxonomy ] = array( 'role'=>$taxonomy,'priority'=>$priority,'gallery_priority'=>$priority,'index_single'=>false,'min_results'=>3,'required_meta_key'=>'','required_meta_values'=>array(),'meta_constraint_scope'=>'single' ); $priority += 10; }
		}
		$profile['taxonomy_rules'] = $rules;
		if ( isset( $profile['taxonomy_rules']['location_jet'] ) ) {
			$profile['taxonomy_rules']['location_jet']['min_results'] = (int) $this->config->get( 'min_results_location', 1 );
			$profile['taxonomy_rules']['location_jet']['required_meta_values'] = (array) $this->config->get( 'indexable_location_levels', array( 'city', 'landmark' ) );
		}
		if ( isset( $profile['taxonomy_rules']['tour-types_jet'] ) ) {
			$profile['taxonomy_rules']['tour-types_jet']['index_single'] = (bool) $this->config->get( 'index_single_tour_type', false );
			$singleType = 'tour-types_jet';
			$sets = (array) $profile['allowed_taxonomy_sets'];
			if ( $profile['taxonomy_rules']['tour-types_jet']['index_single'] && ! in_array( $singleType, $sets, true ) ) { $sets[] = $singleType; }
			if ( ! $profile['taxonomy_rules']['tour-types_jet']['index_single'] ) { $sets = array_values( array_diff( $sets, array( $singleType ) ) ); }
			$profile['allowed_taxonomy_sets'] = array_values( array_unique( $sets ) );
		}
		$profile['min_results_by_depth']['1'] = (int) $this->config->get( 'min_results_location', 1 );
		$profile['min_results_by_depth']['2'] = (int) $this->config->get( 'min_results_pair', 3 );
		$profile['min_results_by_depth']['3'] = (int) $this->config->get( 'min_results_triple', 3 );
		$profile['require_exact_combination_approval'] = (bool) $this->config->get( 'require_exact_combination_approval', true );
		if ( empty( $profile['indexable_combinations'] ) ) { $profile['indexable_combinations'] = (array) $this->config->get( 'indexable_combinations', array() ); }
		$profile['content'] = array(
			'required' => (bool) $this->config->get( 'require_content_readiness', true ),
			'require_meta_description' => (bool) $this->config->get( 'require_meta_description', true ),
			'min_chars' => (int) $this->config->get( 'min_content_chars', 80 ),
		);
		$profile['canonical_mode'] = (string) $this->config->get( 'canonical_mode', 'filtered' );
		return $profile;
	}


	private function filterAllowedTaxonomySets( array $profile ): array {
		$rules = array_fill_keys( array_keys( (array) ( $profile['taxonomy_rules'] ?? array() ) ), true );
		$sets = array();
		foreach ( (array) ( $profile['allowed_taxonomy_sets'] ?? array() ) as $set ) {
			$signature = $this->normalizeTaxonomySet( $set );
			if ( '' === $signature ) { continue; }
			$valid = true;
			foreach ( explode( '+', $signature ) as $taxonomy ) {
				if ( ! isset( $rules[ $taxonomy ] ) ) { $valid = false; break; }
			}
			if ( $valid ) { $sets[] = $signature; }
		}
		$profile['allowed_taxonomy_sets'] = array_values( array_unique( $sets ) );
		return $profile;
	}

	private function canonicalArchiveAuthority( string $path ): string {
		$path=$this->normalizeArchivePath($path);if(''===$path){return '';}
		foreach($this->activeLanguageCodes() as $language){$prefix='/'.$language.'/';if(0===strpos($path,$prefix)){$path=$this->normalizeArchivePath('/'.ltrim(substr($path,strlen($prefix)),'/'));break;}}
		return $path;
	}
	private function archiveMatches( array $profile, string $archive, string $archivePath ): bool {
		foreach ( (array) ( $profile['archive_paths'] ?? array() ) as $authority ) {
			$authority=$this->normalizeArchivePath((string)$authority);
			if ( ''!==$authority && $this->archivePathEqualsAuthority( $archivePath, $authority ) ) { return true; }
		}
		return ! empty( $profile['inherit_global_defaults'] ) && in_array($archive,(array)($profile['archive_slugs']??array()),true);
	}
	private function archivePathEqualsAuthority( string $archivePath, string $authority ): bool {
		$archivePath=$this->normalizeArchivePath($archivePath);
		if ( ''===$archivePath || ''===$authority ) { return false; }
		if ( $archivePath===$authority ) { return true; }
		foreach ( $this->activeLanguageCodes() as $language ) {
			$prefix='/'.$language.'/';
			if ( 0===strpos($archivePath,$prefix) ) {
				$without='/'.ltrim(substr($archivePath,strlen($prefix)),'/');
				$without=$this->normalizeArchivePath($without);
				if ( $without===$authority ) { return true; }
			}
		}
		return false;
	}
	private function activeLanguageCodes(): array {
		if ( ! function_exists('apply_filters') ) { return array(); }
		$languages=apply_filters('wpml_active_languages',null,array('skip_missing'=>0));
		if ( ! is_array($languages) ) { return array(); }
		$out=array(); foreach(array_keys($languages) as $code){$code=sanitize_key((string)$code);if(''!==$code){$out[]=$code;}}
		return array_values(array_unique($out));
	}
	private function profileSupportsProvider( array $profile, string $provider ): bool { foreach((array)($profile['routes']??array()) as $route){if((string)($route['provider']??'')===$provider){return true;}} return !empty($profile['inherit_global_defaults'])&&in_array($provider,(array)($profile['providers']??array()),true); }
	private function profileSupportsRoute( array $profile, string $provider, string $queryId ): bool { $routes=(array)($profile['routes']??array()); if($routes){foreach($routes as $route){if((string)($route['provider']??'')===$provider&&(string)($route['query_id']??'')===$queryId){return true;}} return false;} return !empty($profile['inherit_global_defaults'])&&in_array($provider,(array)($profile['providers']??array()),true)&&in_array($queryId,(array)($profile['query_ids']??array()),true); }
	private function routesValue( $value ): array { $out=array(); foreach(array_slice((array)$value,0,20) as $route){if(!is_array($route)){continue;} $provider=sanitize_key((string)($route['provider']??''));$query=sanitize_key((string)($route['query_id']??''));if(''!==$provider&&''!==$query){$out[]=array('provider'=>$provider,'query_id'=>$query);}} return array_values(array_unique($out,SORT_REGULAR)); }
	private function pathList( $value ): array { if(is_string($value)){$value=preg_split('/[\r\n,]+/',$value);} $out=array();foreach((array)$value as $path){$path=$this->normalizeArchivePath((string)$path);if(''!==$path){$out[]=$path;}} return array_values(array_unique($out)); }
	private function normalizeArchivePath( string $path ): string { $path=parse_url($path,PHP_URL_PATH);$path=is_string($path)?rawurldecode($path):'';$path=preg_replace('#/+#u','/',$path);$path=preg_replace('#[^\p{L}\p{N}_\-/]#u','',$path);if(!is_string($path)){return '';}return ''===$path?'':'/'.trim(strtolower($path),'/').'/'; }
	private function resolution( bool $inScope, bool $valid, string $reason, array $profile = array() ): array {
		return array( 'in_scope'=>$inScope, 'scope_valid'=>$valid, 'reason'=>$reason, 'profile_id'=>(string)($profile['id']??''), 'profile'=>$profile, 'configuration_revision'=>$this->config->revision() );
	}
	private function fieldMapValue( $value ): array {
		$value=is_array($value)?$value:array();
		$allowed=array('seo_title','meta_description','focus_keyword','short_description','image','gallery','location_level');
		$out=array();
		foreach($allowed as $canonical){
			if(!array_key_exists($canonical,$value)){continue;}
			$fields=$this->listValue($value[$canonical],'sanitize_key');
			if($fields){$out[$canonical]=array_slice($fields,0,20);}
		}
		return $out;
	}
	private function enumValue( $value, array $allowed, string $default ): string { $value=sanitize_key((string)$value); return in_array($value,$allowed,true)?$value:$default; }
	private function boolValue( $value ): bool { if ( is_string( $value ) ) { return in_array( strtolower( trim( $value ) ), array( '1','true','yes','on' ), true ); } return (bool) $value; }
	private function boundedInt( $value, int $min, int $max ): int { $value = is_numeric( $value ) ? (int) $value : $min; return max( $min, min( $max, $value ) ); }
	private function listValue( $value, string $sanitizer ): array { if ( is_string( $value ) ) { $value = preg_split( '/[\r\n,]+/', $value ); } $out=array(); foreach ( (array) $value as $item ) { $item=call_user_func($sanitizer,(string)$item); if(''!==$item){$out[]=$item;} } return array_values(array_unique($out)); }
	private function lineList( $value ): array { if ( is_string( $value ) ) { $value=preg_split('/[\r\n]+/',$value); } $out=array(); foreach((array)$value as $line){$line=strtolower(trim((string)$line)); if(''!==$line){$out[]=$line;}} return array_values(array_unique($out)); }
	private function normalizeTaxonomySet( $set ): string { if ( is_string( $set ) ) { $set = preg_split( '/[+;,]+/', $set ); } $items=array_values(array_filter(array_map('sanitize_key',(array)$set))); sort($items,SORT_STRING); return implode('+',array_unique($items)); }
}
