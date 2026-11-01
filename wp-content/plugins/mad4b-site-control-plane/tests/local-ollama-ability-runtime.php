<?php
/** Local-only inference: hermetic, no network, models, tokens, DB or remote writes. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['qa31_env'] = 'staging';
$GLOBALS['qa31_admin'] = false;
$GLOBALS['qa31_router'] = array( 'enabled' => true, 'mode' => 'local_ollama', 'model' => 'qa31-local-pinned' );
$GLOBALS['qa31_generated'] = 0;
$GLOBALS['qa31_abilities'] = array();
function add_action( $h, $cb, $priority=10 ) { return true; }
function wp_get_environment_type() { return $GLOBALS['qa31_env']; }
function current_user_can( $cap ) { return $cap === 'manage_options' && $GLOBALS['qa31_admin']; }
function wp_has_ability( $name ) { return isset( $GLOBALS['qa31_abilities'][ $name ] ); }
function wp_register_ability( $name, $args ) { $GLOBALS['qa31_abilities'][ $name ] = $args; return true; }
class WP_Error {
    private $c;
    public function __construct( $c ) { $this->c = $c; }
    public function get_error_code() { return $this->c; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class MAD4B_SCP_Local_AI_Governance {
    const MODEL = 'qa31-local-pinned';
    public static function allowed_tasks() { return array( 'summarize', 'structured_review' ); }
    public static function native_agent_authorized( $task ) { return false; }
}
class MAD4B_SCP_AI_Model_Router {
    public static function settings() { return $GLOBALS['qa31_router']; }
    public static function generate_text( $prompt, $options ) {
        ++$GLOBALS['qa31_generated'];
        return 'LOCAL_ONLY_PROBE';
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-ollama-ability.php';
$fail=static function($text){fwrite(STDERR,"FAIL local_ollama: $text\n");exit(1);};
$ok_input = array( 'task'=>'summarize', 'prompt'=>'test prompt' );
if ( MAD4B_SCP_Local_Ollama_Ability::permission( $ok_input ) ) $fail('Staging permitted');
if ( ! is_wp_error( MAD4B_SCP_Local_Ollama_Ability::execute($ok_input) ) ) $fail('Staging executed');
MAD4B_SCP_Local_Ollama_Ability::register();
if ( isset( $GLOBALS['qa31_abilities'][ MAD4B_SCP_Local_Ollama_Ability::ABILITY ] ) ) $fail('Staging registration');
$GLOBALS['qa31_env'] = 'local';
if ( MAD4B_SCP_Local_Ollama_Ability::permission( $ok_input ) ) $fail('unprivileged Local execution');
$GLOBALS['qa31_admin'] = true;
if ( MAD4B_SCP_Local_Ollama_Ability::permission( array('task'=>'unknown','prompt'=>'test') ) ) $fail('unknown task');
MAD4B_SCP_Local_Ollama_Ability::register();
$args = $GLOBALS['qa31_abilities'][ MAD4B_SCP_Local_Ollama_Ability::ABILITY ] ?? array();
if ( empty($args) || !empty($args['meta']['public']) || !empty($args['meta']['show_in_rest']) ||
     !empty($args['meta']['mcp']['public']) || empty($args['meta']['annotations']['readonly']) ||
     ($args['input_schema']['additionalProperties'] ?? null) !== false ) $fail('registration exposes remote execution');
$previous=count($GLOBALS['qa31_abilities']);
MAD4B_SCP_Local_Ollama_Ability::register();
if (count($GLOBALS['qa31_abilities']) !== $previous) $fail('repeated registration');
$GLOBALS['qa31_router']['model'] = 'wrong-model';
$denied = MAD4B_SCP_Local_Ollama_Ability::execute( $ok_input );
if ( ! is_wp_error($denied) || $denied->get_error_code() !== 'mad4b_local_ollama_pinned_router_required' ) $fail('router model mismatch admitted');
if ($GLOBALS['qa31_generated'] !== 0) $fail('denied task invoked router');
$GLOBALS['qa31_router']['model'] = MAD4B_SCP_Local_AI_Governance::MODEL;
$result = MAD4B_SCP_Local_Ollama_Ability::execute($ok_input);
if ( is_wp_error($result) || ($result['contract']??'')!==MAD4B_SCP_Local_Ollama_Ability::CONTRACT
  || ($result['model']??'')!==MAD4B_SCP_Local_AI_Governance::MODEL
  || $result['domain_mutation_authorized']!==false || $result['publication_authorized']!==false
  || $GLOBALS['qa31_generated']!==1 ) $fail('local read-only response drift');
echo "LOCAL_OLLAMA_ABILITY: PASS\n";
