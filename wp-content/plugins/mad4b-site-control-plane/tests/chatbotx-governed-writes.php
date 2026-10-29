<?php
declare(strict_types=1);
/**
 * Isolated fail-closed contract test. Never loads WordPress or mutates a DB.
 */
define('ABSPATH', '/nonexistent-wp/');
final class WP_Error {
    public $code;
    public $message;
    public function __construct($code, $message) { $this->code = (string)$code; $this->message = (string)$message; }
    public function get_error_code() {return $this->code;}
}
function is_wp_error($e): bool {return $e instanceof WP_Error;}
function current_user_can($permission): bool {return true;}
final class MAD4B_SCP_Policy {
    public static function can_mutate(): bool {return false;}
}
class CMB_Business_Context {
    public static function draftable_source_types(): array {return array('example-tour','example-hotel');}
}
require dirname(__DIR__).'/includes/adapters/class-mad4b-scp-chatbotx-governed-writes.php';
if (MAD4B_SCP_ChatbotX_Governed_Writes::draftable_cpt_types() !== array('example-tour','example-hotel')) {
    fwrite(STDERR, "FAIL: provider-owned CPT draft scope was not used\n");exit(1);
}
echo "PASS: provider-scoped CPT targets are not hardcoded in Control Plane\n";
$cases=[
    ['support_create_ticket', ['customer_id'=>12,'mailbox_id'=>2,'title'=>'Synthetic','content'=>'Synthetic','idempotency_key'=>'synthetic-unique-idempotent-A']],
    ['support_add_note',['ticket_id'=>16,'content'=>'Synthetic','idempotency_key'=>'synthetic-unique-idempotent-B']],
    ['cpt_create_draft',['post_type'=>'tours-and-activities','title'=>'Synthetic','idempotency_key'=>'synthetic-unique-idempotent-C']]
];
foreach($cases as [$method,$input]) {
    // Direct invocation must ALSO be guarded; a caller cannot skip SCP.
    $result = MAD4B_SCP_ChatbotX_Governed_Writes::$method($input);
    if (!is_wp_error($result) || $result->get_error_code()!=='cmb_mutation_gate_closed') {
        fwrite(STDERR, "FAIL: $method bypassed site mutation policy
");
        exit(1);
    }
    echo "PASS: direct $method denied by site mutation gate
";
}
