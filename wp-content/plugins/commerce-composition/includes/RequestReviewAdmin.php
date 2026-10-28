<?php
declare(strict_types=1);
namespace Commerce\Composition;

final class RequestReviewAdmin
{
    public static function bootstrap():void
    {
        add_action('admin_menu',[self::class,'menu']);
        add_action('admin_post_commerce_review_decision',[self::class,'submit']);
        add_action('admin_post_commerce_review_create_task',[self::class,'createTask']);
        add_action('admin_post_commerce_review_link_contact',[self::class,'linkContact']);
        add_action('admin_post_commerce_inquiry_qualify',[self::class,'qualifyInquiry']);
    }
    public static function menu():void
    {
        // A standalone inbox remains accessible without Woo shop-manager
        // or WordPress administrator privileges.
        add_menu_page('Request Reviews','Request Inbox',
            'commerce_review_requests','commerce-request-reviews',
            [self::class,'page'],'dashicons-clipboard',56);
    }
    public static function page():void
    {
        if(!RequestReview::authorized())wp_die('Forbidden',403);
        echo '<div class="wrap"><h1>Nonfinancial Request Reviews</h1>';
        echo '<p>Operator review only. This does not create a supplier booking, payment or marketing subscription.</p>';
        if(!RequestReview::enabled()){
            echo '<p>Operator review is currently disabled by site policy.</p></div>';
            return;
        }
        $page=max(1,(int)($_GET['paged']??1));
        $list=RequestReview::listing($page,20);
        if(is_wp_error($list)){
            echo '<p>'.esc_html($list->get_error_code()).'</p></div>';
            return;
        }
        if(isset($_GET['review_notice']))
            echo '<div class="notice notice-info"><p>'.
                esc_html(sanitize_text_field(wp_unslash($_GET['review_notice'])))
                .'</p></div>';
        echo '<table class="widefat striped"><thead><tr><th>Request</th><th>Submitted</th><th>Review</th><th>Decision</th><th>Supplier handoff</th></tr></thead><tbody>';
        foreach($list['items'] as $item){
            $id=$item['request_id'];$rev=$item['revision'];
            echo '<tr><td>#'.esc_html((string)$id).'<p><a href="'.
                esc_url(add_query_arg(['page'=>'commerce-request-reviews','lifecycle_id'=>$id],
                    admin_url('admin.php'))).'">Lifecycle gates</a></p></td><td>'.
                esc_html((string)$item['created_utc']).'</td><td>'.
                esc_html($item['review_state']).'</td><td>';
            $allowed=match($item['review_state']){
                'pending_review'=>['under_review'=>'Start review',
                    'needs_information'=>'Needs information','rejected'=>'Reject'],
                'under_review'=>['accepted_for_processing'=>'Accept for processing',
                    'needs_information'=>'Needs information','rejected'=>'Reject'],
                'needs_information'=>['under_review'=>'Resume review',
                    'rejected'=>'Reject'],
                default=>[]
            };
            if(!current_user_can('manage_options') &&
                !current_user_can('commerce_approve_request_processing'))
                unset($allowed['accepted_for_processing'],$allowed['rejected']);
            foreach($allowed as $next=>$label){
                $reason=match($next){
                    'under_review'=>'review_started',
                    'needs_information'=>'information_missing',
                    'accepted_for_processing'=>'review_completed',
                    default=>'source_unavailable'
                };
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:0 .25rem .25rem 0">';
                wp_nonce_field('commerce_review_'.$id.'_'.$rev);
                foreach(['action'=>'commerce_review_decision',
                    'request_id'=>$id,'to_state'=>$next,
                    'expected_revision'=>$rev,'reason_code'=>$reason] as $name=>$value)
                    echo '<input type="hidden" name="'.esc_attr($name).
                        '" value="'.esc_attr((string)$value).'">';
                echo '<button type="submit" class="button">'.
                    esc_html($label).'</button></form>';
            }
            if(!$allowed)echo 'No further transitions';
            $boardOption=get_option('commerce_request_review_board',[]);
            if(is_array($boardOption)&&($boardOption['enabled']??false)===true &&
                in_array($item['review_state'],
                    ['under_review','accepted_for_processing'],true)){
                $linked=FluentBoardsTaskBridge::inspect($id);
                if(!is_wp_error($linked)&&($linked['linked']??false)===true){
                    echo '<span>FluentBoards task #'.
                        esc_html((string)$linked['task_id']).'</span>';
                }else{
                    echo '<form method="post" action="'.
                        esc_url(admin_url('admin-post.php')).'">';
                    wp_nonce_field('commerce_review_task_'.$id);
                    echo '<input type="hidden" name="action" value="commerce_review_create_task">'.
                        '<input type="hidden" name="request_id" value="'.esc_attr((string)$id).'">'.
                        '<button type="submit" class="button">Create follow-up task</button></form>';
                }
            }
            if(current_user_can('manage_options') && RequestContactEvidence::enabled()){
                $proof=RequestContactEvidence::preview($id);
                if(is_wp_error($proof)){
                    echo '<span>CRM identity: not verified</span>';
                }elseif(($proof['already_linked']??false)===true){
                    echo '<span>CRM contact linked #'.
                        esc_html((string)$proof['existing_contact_id']).'</span>';
                }else{
                    echo '<form method="post" action="'.
                        esc_url(admin_url('admin-post.php')).'">';
                    wp_nonce_field('commerce_review_contact_'.$id);
                    echo '<input type="hidden" name="action" value="commerce_review_link_contact">'.
                        '<input type="hidden" name="request_id" value="'.
                        esc_attr((string)$id).'">'.
                        '<button type="submit" class="button">Link verified CRM contact</button></form>';
                }
            }
            echo '</td><td>';
            if(!current_user_can('manage_options')){
                echo 'Supplier handoff: manager approval required';
            }elseif($item['review_state']!=='accepted_for_processing'){
                echo 'Awaiting operator acceptance';
            }else{
                $handoff=RequestSupplierReadiness::preview($id);
                if(is_wp_error($handoff)){
                    echo 'Supplier handoff blocked: '.esc_html($handoff->get_error_code());
                }else{
                    // An approved inquiry alone never authorizes the creation
                    // of a supplier booking; a verified Woo Order Item does.
                    echo 'Awaiting canonical WooCommerce Order and mapped Order Item';
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>Showing page '.esc_html((string)$page).
             '; total requests '.esc_html((string)$list['pagination']['total']).'</p>';
        self::lifecyclePanel();
        self::unqualifiedInquiries();
        echo '</div>';
    }
    /** Bounded non-PII inspection only when an operator selects one Request. */
    private static function lifecyclePanel():void
    {
        $id=absint($_GET['lifecycle_id']??0);
        if($id<1)return;
        $state=LifecycleReadiness::inspect($id);
        echo '<section style="max-width:760px"><h2>Request #'.esc_html((string)$id).' — Lifecycle Gates</h2>';
        if(is_wp_error($state)){
            echo '<p>'.esc_html($state->get_error_code()).'</p></section>';
            return;
        }
        echo '<p><strong>Next:</strong> '.esc_html($state['next_action']).' — '.
            esc_html($state['next_owner']).'. No financial authorization granted.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Gate</th><th>State</th><th>Owner</th><th>Blocker</th></tr></thead><tbody>';
        foreach($state['gates'] as $key=>$gate){
            echo '<tr><td>'.esc_html($key).'</td><td>'.esc_html($gate['state']).
                '</td><td>'.esc_html($gate['owner']).'</td><td>'.
                esc_html(implode(', ',array_slice($gate['reasons'],0,3))).'</td></tr>';
        }
        echo '</tbody></table></section>';
    }
    /**
     * A read-only projection from native Fluent Forms: no fabricated tour,
     * price, Request Registry record, contact or business task.
     */
    private static function unqualifiedInquiries():void
    {
        global $wpdb;
        $ids=get_option('commerce_unqualified_inquiry_form_ids',[]);
        if(!is_array($ids)||!$ids)return;
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids),
            static fn($id)=>$id>0&&$id<=10000)));
        if(!$ids||count($ids)>20)return;
        $table=$wpdb->prefix.'fluentform_submissions';
        $marks=implode(',',array_fill(0,count($ids),'%d'));
        $sql=$wpdb->prepare(
            "SELECT id,form_id,status,created_at FROM $table
             WHERE form_id IN ($marks) AND status NOT IN ('trashed','spam','deleted')
             ORDER BY id DESC LIMIT 30",...$ids);
        $rows=$wpdb->get_results($sql,ARRAY_A);
        echo '<h2>General trip inquiries — pending qualification</h2>';
        echo '<p>Owned by the original Fluent Forms submissions. These are not priced tour bookings or Commerce Requests. The qualified tour intake uses the existing Request Registry.</p>';
        if(!$rows){echo '<p>No outstanding general inquiry entries in the configured forms.</p>';return;}
        echo '<table class="widefat striped"><thead><tr><th>Submission</th><th>Source form</th><th>Received</th><th>Source status</th><th>Qualification / Quote Draft</th></tr></thead><tbody>';
        foreach($rows as $row){
            $fid=(int)$row['form_id'];$sid=(int)$row['id'];
            $valid=SubmissionProvenance::verify($sid,$fid);
            if(is_wp_error($valid))continue;
            echo '<tr><td>#'.esc_html((string)$sid).'</td><td>Form #'.
                esc_html((string)$fid).'</td><td>'.
                esc_html((string)$row['created_at']).'</td><td>'.
                esc_html((string)$row['status']).'</td><td>';
            if(InquiryQualification::enabled()){
                $draft=InquiryQualification::preview($fid,$sid);
                if(!is_wp_error($draft)){
                    echo '<strong>Draft #'.esc_html((string)$draft['qualification_id']).
                        '</strong> — '.esc_html(get_the_title((int)$draft['tour_id'])).
                        '<p>Quote: ';
                    if($draft['quote_state']==='preview_only')
                        echo esc_html(number_format_i18n($draft['preview_amount'],2).' '.$draft['currency']);
                    else echo 'Requires Review ('.esc_html($draft['reason']).')';
                    echo '</p><small>Not approved. No sale or supplier booking.</small>';
                }elseif($draft->get_error_code()==='qualification_not_found'){
                    $selected=(int)($_GET['qualify_form']??0)===$fid &&
                        (int)($_GET['qualify_submission']??0)===$sid;
                    if($selected)self::qualificationForm($fid,$sid);
                    else echo '<a class="button" href="'.esc_url(add_query_arg([
                        'page'=>'commerce-request-reviews','qualify_form'=>$fid,
                        'qualify_submission'=>$sid
                    ],admin_url('admin.php'))).'">Qualify inquiry</a>';
                }else{
                    echo 'Qualification unavailable: '.esc_html($draft->get_error_code());
                }
            }else echo 'Manual qualification in Fluent Forms';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    /** User picks source & rate explicitly, using existing CPTs and Rate records. */
    private static function qualificationForm(int $form,int $submission):void
    {
        $tours=get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish',
            'posts_per_page'=>350,'orderby'=>'title','order'=>'ASC','fields'=>'ids',
            'suppress_filters'=>true]);
        $rates=get_posts(['post_type'=>'tour-rates','post_status'=>'publish',
            'posts_per_page'=>900,'fields'=>'ids','suppress_filters'=>true]);
        $byTour=[];
        foreach($rates as $rate){
            $tour=(int)get_post_meta((int)$rate,'related_tour_id',true);
            if($tour>0)$byTour[$tour][]=(int)$rate;
        }
        $eligible=InquiryQualification::eligibleRateOptions($form,$submission,$byTour);
        if(is_wp_error($eligible)){
            echo '<p>Cannot load eligible rates: '.esc_html($eligible->get_error_code()).'</p>';
            return;
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('commerce_inquiry_qualify_'.$form.'_'.$submission);
        foreach(['action'=>'commerce_inquiry_qualify','form_id'=>$form,'submission_id'=>$submission] as $key=>$value)
            echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">';
        echo '<label class="screen-reader-text">Select service and Rate</label>';
        echo '<select required name="source_pair"><option value="">Choose service / rate</option>';
        foreach($tours as $tour){
            $id=(int)$tour;
            if(get_post_meta($id,'_booking_experience_qa_pricing_fixture',true)!==''
                ||(string)get_post_meta($id,'tour_public_status',true)!=='1')continue;
            $name=get_the_title($id);
            echo '<option value="'.esc_attr($id.':0').'">'.esc_html($name.' — Pricing review').'</option>';
            foreach(($eligible[$id]??[]) as $rate){
                if(get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true)!=='')continue;
                echo '<option value="'.esc_attr($id.':'.$rate).'">'.
                    esc_html($name.' — Rate: '.get_the_title($rate)).'</option>';
            }
        }
        echo '</select><label class="screen-reader-text">Audience</label>'.
            '<select name="audience"><option value="general">General</option>'.
            '<option value="egyptians">Egyptians (requires proof)</option></select>'.
            '<label class="screen-reader-text">Occupancy</label>'.
            '<select name="occupancy"><option value="single">Single</option>'.
            '<option value="double">Double</option><option value="triple">Triple</option></select>'.
            '<button class="button" type="submit">Prepare quote draft</button></form>'.
            '<small>Operator selection only; original inquiry remains in Fluent Forms.</small>';
    }
    public static function qualifyInquiry():void
    {
        if(!RequestReview::authorized()||!InquiryQualification::enabled())
            wp_die('Forbidden',403);
        $form=absint($_POST['form_id']??0);
        $submission=absint($_POST['submission_id']??0);
        check_admin_referer('commerce_inquiry_qualify_'.$form.'_'.$submission);
        $pair=sanitize_text_field(wp_unslash($_POST['source_pair']??''));
        if(!preg_match('/^([1-9][0-9]*):(0|[1-9][0-9]*)$/D',$pair,$match))
            wp_die('Invalid source choice',400);
        $audience=sanitize_key(wp_unslash($_POST['audience']??''));
        $occupancy=sanitize_key(wp_unslash($_POST['occupancy']??''));
        $result=InquiryQualification::qualify($form,$submission,
            (int)$match[1],(int)$match[2]?:null,$audience,$occupancy);
        wp_safe_redirect(add_query_arg([
            'page'=>'commerce-request-reviews',
            'review_notice'=>is_wp_error($result)?$result->get_error_code():
                'quote_draft_prepared_'.$result['qualification_id']
        ],admin_url('admin.php')));
        exit;
    }
    public static function linkContact():void
    {
        if(!current_user_can('manage_options')||
            !RequestReview::enabled()||
            !RequestContactEvidence::enabled())
            wp_die('Forbidden',403);
        $id=absint($_POST['request_id']??0);
        check_admin_referer('commerce_review_contact_'.$id);
        // Neither identity nor opt-in may be supplied by the browser.
        $result=RequestContactEvidence::ensure($id);
        wp_safe_redirect(add_query_arg([
            'page'=>'commerce-request-reviews',
            'review_notice'=>is_wp_error($result)?
                $result->get_error_code():
                'crm_contact_linked'
        ],admin_url('admin.php')));
        exit;
    }
    public static function createTask():void
    {
        if(!RequestReview::authorized()||
            !(current_user_can('commerce_manage_request_tasks') ||
              current_user_can('manage_options'))||
            !RequestReview::enabled()) wp_die('Forbidden',403);
        $id=absint($_POST['request_id']??0);
        check_admin_referer('commerce_review_task_'.$id);
        $result=FluentBoardsTaskBridge::ensure($id);
        wp_safe_redirect(add_query_arg([
            'page'=>'commerce-request-reviews',
            'review_notice'=>is_wp_error($result)?$result->get_error_code():
                'followup_task_'.$result['task_id']
        ],admin_url('admin.php')));
        exit;
    }
    public static function submit():void
    {
        if(!RequestReview::authorized()||!RequestReview::enabled())
            wp_die('Forbidden',403);
        $id=absint($_POST['request_id']??0);
        $revision=absint($_POST['expected_revision']??0);
        check_admin_referer('commerce_review_'.$id.'_'.$revision);
        $next=sanitize_key(wp_unslash($_POST['to_state']??''));
        $reason=sanitize_key(wp_unslash($_POST['reason_code']??''));
        // A POST nonce scopes the browser session, request and revision.
        // A replay is rejected by the transaction even after nonce reuse.
        $key='admin-review:'.$id.':'.$revision.':'.$next;
        $result=RequestReview::decide($id,$next,$revision,$reason,$key);
        wp_safe_redirect(add_query_arg([
            'page'=>'commerce-request-reviews',
            'review_notice'=>is_wp_error($result)?$result->get_error_code():
                'review_updated_'.$result['review_state']
        ],admin_url('admin.php')));
        exit;
    }
}
