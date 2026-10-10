<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Optional CSO application surface. Authority remains in native MAD4B dispatchers. */
final class MAD4B_SCP_CSO_Gateway {
    private static $booted = false;
    private static $registered = array();
    private static $attempted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        MAD4B_SCP_CSO_Scope::boot();
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'discovery' ) ) return;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 46 );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
    }

    private static function routes() {
        // The existing seven CSO01 read contracts keep their names and callbacks.
        // Private writes are never published as read tools or given a new grant.
        return array(
            'capability_catalog' => array( 'discovery', 'read', array( 'query','limit','offset' ) ),
            'form_prepare' => array( 'forms', 'read', array( 'ability_name','target' ) ),
            'field_suggest' => array( 'forms', 'read', array( 'form','field','query','offset' ) ),
            'typed_validate' => array( 'forms', 'read', array( 'form','values' ) ),
            'field_help' => array( 'forms', 'read', array( 'form','field' ) ),
            'form_presentation' => array( 'forms', 'read', array( 'ability_name','target' ) ),
            'draft' => array( 'forms', 'private', array( 'operation','id','expected_revision','form','values' ) ),
            'change_plan' => array( 'single_write', 'private', array( 'form','values','target_revision' ) ),
            'approval_plan' => array( 'single_write', 'private', array( 'plan','reason','agent_public_id' ) ),
            'change_commit' => array( 'single_write', 'private', array( 'plan','governance' ) ),
            'change_verify' => array( 'single_write', 'private', array( 'plan' ) ),
            'change_status' => array( 'single_write', 'private', array( 'plan','ticket_id' ) ),
            'change_history' => array( 'single_write', 'read', array( 'operation_id','limit','offset' ) ),
            'undo_plan' => array( 'single_write', 'plan', array( 'operation_id','preparation' ) ),
            'bulk_plan' => array( 'bulk', 'private', array( 'plans','selection','canary_size' ) ),
            'bulk_commit' => array( 'bulk', 'private', array( 'plan','governance','checkpoint','max_items' ) ),
            'workflow_compile' => array( 'workflow', 'private', array( 'nodes' ) ),
            'workflow_run' => array( 'workflow', 'private', array( 'plan','governance','max_nodes' ) ),
            'trigger_plan' => array( 'workflow', 'plan', array( 'source_ability','workflow' ) ),
            'secret_session' => array( 'secrets', 'private', array( 'provider_id','field_ref','mode','consent','ttl' ) ),
            'secret_status' => array( 'secrets', 'read', array( 'session_ref' ) ),
            'secret_rotation_plan' => array( 'secrets', 'plan', array( 'provider_id','field_ref' ) ),
            'content_plan' => array( 'forms', 'plan', array( 'scope','capability','target','values','preparation' ) ),
            'object_contract' => array( 'forms', 'plan', array( 'scope','capability','target','values','read_preparation' ) ),
            'multisite_plan' => array( 'multisite', 'plan', array( 'scope','sites' ) ),
            'promotion_plan' => array( 'production_proposal', 'plan', array( 'scope','artifact','destination','staging_bundle','preparation','changes' ) ),
            'monitor_plan' => array( 'operations', 'plan', array( 'scope','opt_in','conditions','interval_seconds','ttl_seconds' ) ),
            'doctor_plan' => array( 'operations', 'private', array( 'scope','domain','diagnostic_input','preparation' ) ),
            'drift_plan' => array( 'operations', 'plan', array( 'scope','capability','reference','target','desired_values','observation_capability','observation_input','observation_path','preparation' ) ),
            'metrics_plan' => array( 'operations', 'plan', array( 'scope','opt_in','hours' ) ),
            'accessibility_report' => array( 'forms', 'read', array( 'scope','capability','target' ) ),
            'template_plan' => array( 'forms', 'private', array( 'form','recipe' ) ),
        );
    }

    /**
     * Only handlers that exist and are backed by the existing CSO01 read
     * contracts may be advertised. All other proposed CSO routes remain
     * unregistered and fail closed instead of causing runtime class errors.
     */
    private static function implemented() {
        return array(
            'capability_catalog', 'form_prepare', 'typed_validate',
            'field_help', 'field_suggest', 'form_presentation', 'secret_session',
            'secret_status', 'secret_rotation_plan', 'draft', 'change_plan', 'approval_plan', 'change_commit', 'change_verify', 'change_status', 'bulk_plan', 'workflow_compile', 'doctor_plan', 'template_plan'
        );
    }

    public static function read_tools() { return self::$registered; }

    private static function argument_schema( array $keys ) {
        $properties = array();
        $objects = array( 'scope','target','form','values','plan','governance','selection','checkpoint','artifact','destination','staging_bundle','preparation','read_preparation','reference','desired_values','diagnostic_input','observation_input','recipe','workflow' );
        $arrays = array( 'plans','nodes','sites','conditions','changes' );
        $integers = array( 'limit','offset','canary_size','max_items','max_nodes','ttl','interval_seconds','ttl_seconds','hours' );
        foreach ( $keys as $key ) {
            if ( in_array( $key, $objects, true ) ) $properties[$key] = array( 'type'=>'object','maxProperties'=>128 );
            elseif ( in_array( $key, $arrays, true ) ) $properties[$key] = array( 'type'=>'array','maxItems'=>100,'items'=>array( 'type'=>'object' ) );
            elseif ( in_array( $key, $integers, true ) ) $properties[$key] = array( 'type'=>'integer','minimum'=>0,'maximum'=>604800 );
            elseif ( in_array( $key, array( 'opt_in','consent' ), true ) ) $properties[$key] = array( 'type'=>'boolean' );
            else $properties[$key] = array( 'type'=>'string','maxLength'=>512 );
        }
        return array( 'type'=>'object','properties'=>$properties,'additionalProperties'=>false );
    }

    public static function register_abilities() {
        if ( self::$attempted || ! MAD4B_SCP_CSO_Scope::enabled( 'discovery' ) || ! function_exists('wp_register_ability') || ! function_exists('wp_has_ability') ) return;
        self::$attempted = true;
        $candidates = array();
        foreach ( self::routes() as $action=>$route ) {
            if ( ! in_array( $action, self::implemented(), true ) ||
                'private' === $route[1] || 'secret_status' === $action ||
                ! MAD4B_SCP_CSO_Scope::enabled($route[0]) ) continue;
            $name = 'cso/' . str_replace('_','-',$action);
            if ( wp_has_ability($name) ) return; // Never adopt another registrar's callback.
            $candidates[$name] = array($action,$route);
        }
        $pending = array();
        foreach ( $candidates as $name=>$row ) {
            $action = $row[0];
            $result = wp_register_ability($name,array(
                'label'=>'CSO ' . str_replace('_',' ',$action),
                'description'=>'Bounded CSO preparation; capability discovery and plans do not authorize changes.',
                'category'=>'mad4b-read', 'input_schema'=>self::argument_schema($row[1][2]),
                'output_schema'=>array('type'=>'object'),
                'permission_callback'=>array(__CLASS__,'can_read'),
                'execute_callback'=>static function($args) use($action) { return self::dispatch(array('action'=>$action,'arguments'=>$args)); },
                'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read','non_authorizing'=>true),'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true)),
            ));
            if ( ! is_object($result) || is_wp_error($result) || ! wp_has_ability($name) ) return;
            $pending[] = $name;
        }
        // Partial callbacks remain permission-denied, including direct Ability invocation.
        self::$registered = $pending;
    }

    public static function can_read() { return ! empty(self::$registered) && ! is_wp_error(MAD4B_SCP_CSO_Scope::current()); }

    public static function register_rest() {
        register_rest_route('mad4b/v1','/conversational-site-operations',array(
            'methods'=>'POST','permission_callback'=>array(__CLASS__,'rest_permission'),
            'callback'=>static function($request) {
                $body=$request->get_body();
                if ( ! is_string($body) || strlen($body)>131072 ) return MAD4B_SCP_CSO_Scope::error('REQUEST_BOUNDS');
                $result=self::dispatch($request->get_json_params(), true);
                if ( is_wp_error($result) ) return $result;
                $response=new WP_REST_Response($result);
                $response->header('Cache-Control','private, no-store');
                $response->header('Vary','Cookie');
                $response->header('X-Content-Type-Options','nosniff');
                return $response;
            },
        ));
    }

    public static function rest_permission($request) {
        // Remote OAuth continues to use the existing protected MCP resource. This
        // first-party endpoint accepts only WordPress cookie + REST nonce + Origin.
        if ( ! MAD4B_SCP_CSO_Scope::enabled('discovery') || ! is_user_logged_in() || '' !== trim((string)$request->get_header('authorization')) || ! wp_verify_nonce((string)$request->get_header('x-wp-nonce'),'wp_rest') ) return MAD4B_SCP_CSO_Scope::error('FIRST_PARTY_SESSION_REQUIRED');
        $scope=MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error($scope) ) return $scope;
        $origin=untrailingslashit((string)$request->get_header('origin'));
        $parts=wp_parse_url($scope['origin']);
        $expected=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
        return hash_equals($expected,$origin) ? true : MAD4B_SCP_CSO_Scope::error('ORIGIN_MISMATCH');
    }

    public static function dispatch($input, $first_party = false) {
        if ( ! is_array($input) || array_diff(array_keys($input),array('action','arguments')) || ! is_string($input['action']??null) || ! is_array($input['arguments']??null) || true !== MAD4B_SCP_CSO_Scope::bounded($input) ) return MAD4B_SCP_CSO_Scope::error('REQUEST_SHAPE');
        $action=$input['action']; $args=$input['arguments']; $routes=self::routes();
        if ( ! isset($routes[$action]) || ! MAD4B_SCP_CSO_Scope::enabled('discovery') || ! MAD4B_SCP_CSO_Scope::enabled($routes[$action][0]) ) return MAD4B_SCP_CSO_Scope::error('FEATURE_DISABLED');
        if ( ! in_array( $action, self::implemented(), true ) )
            return MAD4B_SCP_CSO_Scope::error( 'IMPLEMENTATION_NOT_CERTIFIED' );
        if ( 'private' === $routes[$action][1] &&
            ( true !== $first_party || ! MAD4B_SCP_CSO_Scope::first_party_session() ) )
            return MAD4B_SCP_CSO_Scope::error( 'FIRST_PARTY_PRIVATE_SESSION_REQUIRED' );
        if ( array_diff(array_keys($args),$routes[$action][2]) ) return MAD4B_SCP_CSO_Scope::error('UNKNOWN_ARGUMENT');
        $scope=MAD4B_SCP_CSO_Scope::current(); if ( is_wp_error($scope) ) return $scope;
        // Schema metadata and sealed contracts may legitimately name secrets;
        // only ordinary submitted values/search text enter the credential guard.
        foreach ( array('values','desired_values','query','reason','diagnostic_input','observation_input') as $key ) if ( isset($args[$key]) && true !== MAD4B_SCP_CSO_Scope::safe_data($args[$key]) ) return MAD4B_SCP_CSO_Scope::error('SECRET_INPUT_DENIED');
        try { $result=self::invoke($action,$args); } catch (\Throwable $e) { return MAD4B_SCP_CSO_Scope::error('SERVICE_INPUT_OR_DEPENDENCY_INVALID'); }
        $current=MAD4B_SCP_CSO_Scope::assert_current($scope); if ( is_wp_error($current) ) return $current;
        if ( is_wp_error($result) ) {
            $data=$result->get_error_data(); $reason=is_array($data)?($data['reason']??'SERVICE_DENIED'):'SERVICE_DENIED';
            return MAD4B_SCP_CSO_Scope::error(is_string($reason)&&preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$reason)?$reason:'SERVICE_DENIED');
        }
        return is_array($result) && true === MAD4B_SCP_CSO_Scope::bounded($result) ? $result : MAD4B_SCP_CSO_Scope::error('OUTPUT_CONTRACT_INVALID');
    }

    private static function invoke($action,array $a) {
        switch($action) {
            case 'capability_catalog': return MAD4B_SCP_CSO_Registry::catalog($a['query']??'', $a['limit']??12, $a['offset']??0);
            case 'form_prepare': return MAD4B_SCP_CSO_Forms::schema($a['ability_name']??'', $a['target']??array());
            case 'field_suggest': return MAD4B_SCP_CSO_Forms::suggest($a['form']??array(), $a['field']??'', $a['query']??'', $a['offset']??0);
            case 'typed_validate': return MAD4B_SCP_CSO_Forms::validate($a['form']??array(), $a['values']??array());
            case 'field_help': return MAD4B_SCP_CSO_Forms::explain($a['form']??array(), $a['field']??'');
            case 'form_presentation': return MAD4B_SCP_CSO_Form_UI::presentation($a);
            case 'draft': return self::draft($a);
            case 'change_plan': return MAD4B_SCP_CSO_Changes::plan($a['form']??array(),$a['values']??array(),$a['target_revision']??'');
            case 'approval_plan': return MAD4B_SCP_CSO_Changes::approval_plan($a['plan']??array(),$a['reason']??'',$a['agent_public_id']??'');
            case 'change_commit': return MAD4B_SCP_CSO_Changes::commit($a['plan']??array(),$a['governance']??array());
            case 'change_verify': return MAD4B_SCP_CSO_Changes::verify($a['plan']??array());
            case 'change_status': return MAD4B_SCP_CSO_Native_Executor::status($a['plan']??array(),$a['ticket_id']??'');
            case 'change_history': return MAD4B_SCP_CSO_Changes::history($a);
            case 'undo_plan': return MAD4B_SCP_CSO_Changes::undo_plan($a);
            case 'bulk_plan': return MAD4B_SCP_CSO_Bulk::plan($a['plans']??array(),$a['selection']??array(),$a['canary_size']??1);
            case 'bulk_commit': return MAD4B_SCP_CSO_Bulk::commit($a['plan']??array(),$a['governance']??array(),$a['checkpoint']??array(),$a['max_items']??1);
            case 'workflow_compile': return MAD4B_SCP_CSO_Workflows::compile($a['nodes']??array());
            case 'workflow_run': return MAD4B_SCP_CSO_Workflows::run($a['plan']??array(),$a['governance']??array(),$a['max_nodes']??1);
            case 'trigger_plan': return MAD4B_SCP_CSO_Triggers::plan($a['source_ability']??'',$a['workflow']??array());
            case 'secret_session': return MAD4B_SCP_CSO_Secrets::session($a);
            case 'secret_status': return MAD4B_SCP_CSO_Secrets::status($a);
            case 'secret_rotation_plan': return MAD4B_SCP_CSO_Secrets::rotation_plan($a);
            case 'content_plan': return MAD4B_SCP_CSO_Domain_Plans::content_plan($a);
            case 'object_contract': return MAD4B_SCP_CSO_Domain_Plans::object_contract($a);
            case 'multisite_plan': return MAD4B_SCP_CSO_Domain_Plans::multisite_plan($a);
            case 'promotion_plan': return MAD4B_SCP_CSO_Domain_Plans::promotion_plan($a);
            case 'monitor_plan': return MAD4B_SCP_CSO_Operations::monitor_plan($a);
            case 'doctor_plan': return MAD4B_SCP_CSO_Operations::doctor_plan($a);
            case 'drift_plan': return MAD4B_SCP_CSO_Operations::drift_plan($a);
            case 'metrics_plan': return MAD4B_SCP_CSO_Operations::metrics_plan($a);
            case 'accessibility_report': return MAD4B_SCP_CSO_Operations::accessibility_report($a);
            case 'template_plan': return MAD4B_SCP_CSO_Templates::plan($a['form']??array(),$a['recipe']??array());
        }
        return MAD4B_SCP_CSO_Scope::error('ACTION_UNSUPPORTED');
    }

    private static function draft(array $a) {
        switch($a['operation']??'') {
            case 'create': return MAD4B_SCP_CSO_Drafts::create($a['form']??array(),$a['values']??array());
            case 'load': return MAD4B_SCP_CSO_Drafts::load($a['id']??'');
            case 'save': return MAD4B_SCP_CSO_Drafts::save($a['id']??'',$a['expected_revision']??0,$a['form']??array(),$a['values']??array());
            case 'delete': return MAD4B_SCP_CSO_Drafts::delete($a['id']??'',$a['expected_revision']??0);
        }
        return MAD4B_SCP_CSO_Scope::error('DRAFT_ACTION_INVALID');
    }
}
