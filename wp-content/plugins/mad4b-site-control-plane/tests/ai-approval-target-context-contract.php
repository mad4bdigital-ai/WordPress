<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
class WP_Error {
	private $code;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $v ) ); }
function wp_json_encode( $v, $options = 0 ) { return json_encode( $v, $options ); }
function wp_strip_all_tags( $v ) { return strip_tags( $v ); }
class MAD4B_SCP_Approval_Tickets {
	public static $tickets = array();
	public static function get( $id ) { return self::$tickets[$id] ?? null; }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-semantic-content-field-contracts.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-preflight.php';
function check( $condition, $why ) { if ( ! $condition ) { fwrite(STDERR,"FAIL: ".$why."\n"); exit(1); } }
function err( $x, $code ) { return is_wp_error($x) && $x->get_error_code()===$code; }
$id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$sha = str_repeat('c',64);
$input = array('ticket_id'=>$id,'decision'=>'approve','expected_payload_sha256'=>$sha,
	'expected_classification_sha256'=>str_repeat('d',64),
	'operation_input'=>array('brand_id'=>'are','subject'=>'Royal Lily Nile Cruise Staging test',
		'language'=>'en','country'=>'eg','content_type'=>'tour',
		'target_post_type'=>'tours-and-activities','reason'=>'Draft after Brand Core review'));
MAD4B_SCP_Approval_Tickets::$tickets[$id]=array('ticket_id'=>$id,'status'=>'pending','ticket_class'=>'mutation',
	'server_id'=>'mad4b-write','ability_name'=>'mad4b/content-job-create',
	'provider'=>'core','payload_sha256'=>$sha);
$a = MAD4B_SCP_Semantic_Content_Field_Contracts::classify('mad4b/approval-ai-decide',$input);
check($a['field_contract_known'] && !$a['required'] && !$a['review_required'],'Operational ContentJob maps to exact target without false Brand gate');
check(true===MAD4B_SCP_Context_Preflight::mutation_context_guard('mad4b/approval-ai-decide',$input),'Operational AI ticket can pass Context preflight');
$long = $input;$long['operation_input']['subject']=str_repeat('Long internal intake subject ',10);
check(true===MAD4B_SCP_Context_Preflight::mutation_context_guard('mad4b/approval-ai-decide',$long),'Long operational subject is not public copy');
// Dynamic target class, provider-specific typed keyed semantics.
MAD4B_SCP_Approval_Tickets::$tickets[$id]['ability_name']='mad4b/content-set-meta';
MAD4B_SCP_Approval_Tickets::$tickets[$id]['provider']='core_meta';
$meta=$input;$meta['operation_input']=array('post_id'=>73,'key'=>'price','value'=>'2500');
check(true===MAD4B_SCP_Context_Preflight::mutation_context_guard('mad4b/approval-ai-decide',$meta),'Keyed operational price metadata is allowed');
$meta['operation_input']=array('post_id'=>73,'key'=>'tour_description','value'=>'Attractive Nile cruise itinerary content');
check(err(MAD4B_SCP_Context_Preflight::mutation_context_guard('mad4b/approval-ai-decide',$meta),'mad4b_content_context_receipt_required'),'Brand-bearing keyed meta requires source Context Receipt');
// Brand-bearing core post text must not be exempted by AI decision wrapper.
MAD4B_SCP_Approval_Tickets::$tickets[$id]['ability_name']='mad4b/content-create-post';
MAD4B_SCP_Approval_Tickets::$tickets[$id]['provider']='core';
$post=$input;$post['operation_input']=array('post_type'=>'tours-and-activities','post_status'=>'draft',
	'post_title'=>'Royal Lily Cruise','post_content'=>'Test Nile cruise content');
$brand=MAD4B_SCP_Semantic_Content_Field_Contracts::classify('mad4b/approval-ai-decide',$post);
check($brand['required'] && in_array('operation_input.post_content',$brand['matched_fields'],true),'Brand metadata classified under nested target');
check(err(MAD4B_SCP_Context_Preflight::mutation_context_guard('mad4b/approval-ai-decide',$post),'mad4b_content_context_receipt_required'),'Cannot approve Brand-bearing post without receipt');
$deny=$post;$deny['decision']='reject';
check(true===MAD4B_SCP_Context_Preflight::mutation_context_guard('mad4b/approval-ai-decide',$deny),'Reject pending exact Brand ticket needs no Brand approval');
$bad=$input;$bad['expected_payload_sha256']=str_repeat('e',64);
check(err(MAD4B_SCP_Context_Preflight::mutation_context_guard('mad4b/approval-ai-decide',$bad),'mad4b_content_field_classification_required'),'Wrong payload denied');
$bad=$input;$bad['ticket_id']='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
check(err(MAD4B_SCP_Context_Preflight::mutation_context_guard('mad4b/approval-ai-decide',$bad),'mad4b_content_field_classification_required'),'Missing ticket denied');
MAD4B_SCP_Approval_Tickets::$tickets[$id]['status']='used';
check(err(MAD4B_SCP_Context_Preflight::mutation_context_guard('mad4b/approval-ai-decide',$input),'mad4b_content_field_classification_required'),'Used ticket denied');
MAD4B_SCP_Approval_Tickets::$tickets[$id]['status']='pending';
MAD4B_SCP_Approval_Tickets::$tickets[$id]['ability_name']='unregistered/content-export';
check(err(MAD4B_SCP_Context_Preflight::mutation_context_guard('mad4b/approval-ai-decide',$input),'mad4b_content_field_classification_required'),'Unknown target never auto classified');
MAD4B_SCP_Approval_Tickets::$tickets[$id]['ability_name']='mad4b/approval-plan';
check(err(MAD4B_SCP_Context_Preflight::mutation_context_guard('mad4b/approval-ai-decide',$input),'mad4b_content_field_classification_required'),'Recursive approval target denied');
MAD4B_SCP_Approval_Tickets::$tickets[$id]['ability_name']='mad4b/content-job-create';
MAD4B_SCP_Approval_Tickets::$tickets[$id]['server_id']='mad4b-breakglass';
check(err(MAD4B_SCP_Context_Preflight::mutation_context_guard('mad4b/approval-ai-decide',$input),'mad4b_content_field_classification_required'),'Breakglass server denied');
echo "mad4b.ai-approval-exact-target-context: PASS\n";
