<?php
/** Per-company operations UI and narrowly scoped role setup. */
defined('ABSPATH')||exit;
final class MAD4B_Tenant_Operations_Workspace {
    const SLUG='mad4b-company-workspace';
    const ROLE_VERSION='rev127-20261028-v1';
    const CAP_CONTENT='mad4b_propose_company_content';
    const CAP_PRICING='mad4b_manage_company_pricing';
    private static function clean($text){return sanitize_text_field((string)$text);}
    public static function register(){
        add_action('admin_menu',[__CLASS__,'menu'],25);
        add_action('admin_post_mad4b_workspace_propose',[__CLASS__,'propose']);
        add_action('admin_post_mad4b_workspace_review',[__CLASS__,'review']);
    }
    public static function install_roles(){
        if(!defined('WP_CLI')||!WP_CLI||get_current_user_id()!==0
            ||!MAD4B_Tenant_Operation_Authority::local_write())
            return new WP_Error('mad4b_company_roles_authorized_cli_required');
        $p=MAD4B_Tenant_Sync::profile();
        if(empty($p['ready'])||empty(MAD4B_SCP_Audit::storage_status()['ready']))
            return new WP_Error('mad4b_company_roles_authority_missing');
        if(get_option('mad4b_tenant_workspace_role_version')===self::ROLE_VERSION)
            return ['status'=>'already_installed','version'=>self::ROLE_VERSION];
        $a=MAD4B_SCP_Audit::record('mad4b/company-workspace-roles-intent',[
            'site_uuid'=>$p['site_uuid'],'version'=>self::ROLE_VERSION,
            'administrator_privilege_granted'=>false,
            'woocommerce_management_cap_granted'=>false],'intent');
        if(is_wp_error($a))return $a;
        foreach(['administrator','master_admin','editor','author','company_translation_manager'] as $name){
            $role=get_role($name);
            if($role)$role->add_cap(self::CAP_CONTENT,true);
        }
        foreach(['administrator','master_admin'] as $name) {
            $role=get_role($name);
            if($role)$role->add_cap(self::CAP_PRICING,true);
        }
        $pricer=get_role('company_pricing_manager');
        if(!$pricer){
            add_role('company_pricing_manager','Company Pricing Manager',
                ['read'=>true,self::CAP_PRICING=>true]);
        }else $pricer->add_cap(self::CAP_PRICING,true);
        update_option('mad4b_tenant_workspace_role_version',self::ROLE_VERSION,false);
        $r=MAD4B_SCP_Audit::record('mad4b/company-workspace-roles-result',[
            'site_uuid'=>$p['site_uuid'],'version'=>self::ROLE_VERSION,
            'pricing_role_created'=>true,'production_mutation'=>false],'ok');
        if(is_wp_error($r))return $r;
        return ['status'=>'installed','version'=>self::ROLE_VERSION];
    }
    public static function content(){return current_user_can(self::CAP_CONTENT);}
    public static function pricing(){
        return current_user_can(self::CAP_PRICING) || current_user_can('manage_woocommerce');
    }
    public static function menu(){
        if(self::content()||self::pricing()||MAD4B_Tenant_Workflow::reviewer()){
            add_menu_page('Company Workspace','Company Workspace','read',self::SLUG,
                [__CLASS__,'page'],'dashicons-building',34);
        }
        if(self::content())add_submenu_page(self::SLUG,'Content Library','Content Library',
            self::CAP_CONTENT,self::SLUG,[__CLASS__,'page']);
        if(MAD4B_Tenant_Workflow::reviewer())add_submenu_page(self::SLUG,'Approvals','Approvals',
            'mad4b_manage_company_operations','mad4b-company-approvals',[__CLASS__,'approvals']);
        // Pricing link delegates to scoped native pricing policy editor.
        if(self::pricing())add_submenu_page(self::SLUG,'Pricing Policies','Pricing Policies',
            self::CAP_PRICING,'mad4b-tenant-pricing',
            ['MAD4B_Tenant_Sync','page']);
    }
    public static function input_value($field,$raw,$post_id) {
        $ctx=MAD4B_Tenant_Override_Policy::context($post_id);
        if(!$ctx)return new WP_Error('mad4b_workspace_unknown_content');
        $def=$ctx['definitions'][$field]??null;
        if(!$def)return new WP_Error('mad4b_workspace_field_not_registered');
        if($def['type']==='text'){
            if(!is_string($raw)||strlen($raw)>20000)return new WP_Error('mad4b_workspace_text_invalid');
            return sanitize_textarea_field($raw);
        }
        if(!in_array($def['type'],['media_ref','media_collection'],true)
          ||!is_string($raw)||!preg_match('/^[0-9, ]{1,600}$/D',trim($raw)))
            return new WP_Error('mad4b_workspace_input_invalid');
        $parts=explode(',',trim($raw));
        if(count($parts)>60)return new WP_Error('mad4b_workspace_too_many_media');
        $ids=[];
        foreach($parts as $part){
            $part=trim($part);
            if(!preg_match('/^[1-9][0-9]*$/D',$part))return new WP_Error('mad4b_workspace_media_id_invalid');
            $id=(int)$part;
            if(in_array($id,$ids,true))return new WP_Error('mad4b_workspace_duplicate_media');
            $ids[]=$id;
        }
        if($def['type']==='media_ref'&&count($ids)!==1)
            return new WP_Error('mad4b_workspace_one_image_required');
        $items=array_map(static fn($id)=>[
            'scope'=>'tenant','site_uuid'=>$ctx['site_uuid'],'attachment_id'=>$id
        ],$ids);
        return $def['type']==='media_ref'?$items[0]:$items;
    }
    public static function propose(){
        if(!self::content()||get_current_user_id()<1)wp_die('Company content access denied',403);
        $id=absint($_POST['post_id']??0);
        check_admin_referer('mad4b_workspace_propose_'.$id);
        if(!$id||!current_user_can('edit_post',$id))wp_die('Post access denied',403);
        $field=sanitize_key(wp_unslash($_POST['field']??''));
        $value=self::input_value($field,wp_unslash($_POST['value']??''),$id);
        if(is_wp_error($value))wp_die(esc_html($value->get_error_code()),400);
        $revision=filter_var(wp_unslash($_POST['expected_revision']??null),FILTER_VALIDATE_INT);
        if($revision===false||$revision<0)wp_die('Invalid revision',400);
        $result=MAD4B_Tenant_Workflow::submit($id,$field,$value,$revision);
        if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),409);
        wp_safe_redirect(add_query_arg([
            'page'=>self::SLUG,'post_id'=>$id,'submitted'=>$result['request_id']
        ],admin_url('admin.php')));exit;
    }
    public static function review(){
        if(!MAD4B_Tenant_Workflow::reviewer())wp_die('Company approver required',403);
        $id=absint($_POST['request_id']??0);
        check_admin_referer('mad4b_workspace_review_'.$id);
        $decision=sanitize_key(wp_unslash($_POST['decision']??''));
        $result=MAD4B_Tenant_Workflow::review($id,$decision);
        if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),409);
        wp_safe_redirect(add_query_arg([
            'page'=>'mad4b-company-approvals','reviewed'=>$id,
            'result'=>$result['status']
        ],admin_url('admin.php')));exit;
    }
    private static function nav(){
        echo '<nav style="display:flex;gap:14px;flex-wrap:wrap;margin:18px 0">';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page='.self::SLUG)).'">Content</a>';
        if(MAD4B_Tenant_Workflow::reviewer())
            echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-company-approvals')).'">Approvals</a>';
        if(self::pricing())
            echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-tenant-pricing')).'">Pricing</a>';
        echo '</nav>';
    }
    public static function page(){
        if(!self::content()&&!self::pricing()&&!MAD4B_Tenant_Workflow::reviewer())
            wp_die('No company workspace permission',403);
        echo '<div class="wrap"><h1>Company Operations Workspace</h1>';
        echo '<p>Manage your company content and submit local image, SEO and banner changes for review. Hub ownership and cross-company publication are not available here.</p>';
        self::nav();
        if(!self::content()){
            echo '<p>Pricing-only account. Select Pricing to manage local pricing previews.</p></div>';return;
        }
        $id=absint($_GET['post_id']??0);
        if(isset($_GET['submitted']))echo '<div class="notice notice-success"><p>Change submitted for a second-person review. Live content has not been modified yet.</p></div>';
        if(!$id){
            echo '<h2>My Change Requests</h2>';
            $own=MAD4B_Tenant_Workflow::my_requests();
            if($own){
                echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>Request</th><th>Content</th><th>Field</th><th>Status</th></tr></thead><tbody>';
                foreach($own as $item)
                    echo '<tr><td>#'.(int)$item['id'].'</td><td>'.esc_html(get_the_title((int)$item['post_id'])).'</td><td>'.esc_html($item['field_path']).'</td><td>'.esc_html($item['request_state']).'</td></tr>';
                echo '</tbody></table>';
            }else echo '<p>No change requests submitted yet.</p>';
            echo '<h2>Company Content Library</h2>';
            echo '<p>Find an existing company page or post by title or exact WordPress ID before selecting the local fields you want to preserve.</p>';
            echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'">'
                .'<input type="hidden" name="page" value="'.esc_attr(self::SLUG).'">'
                .'<input type="search" name="content_search" maxlength="120" '
                .'placeholder="Title or post ID" value="'.esc_attr(sanitize_text_field(wp_unslash($_GET['content_search']??''))).'">'
                .'<button type="submit" class="button">Find company content</button></form>';
            $search=sanitize_text_field(wp_unslash($_GET['content_search']??''));
            $query=['post_type'=>['post','page','tours-and-activities','properties','transportations'],
                'post_status'=>['publish','draft','private'],'posts_per_page'=>30,
                'orderby'=>'modified','order'=>'DESC','suppress_filters'=>true];
            if($search!==''){
                if(ctype_digit($search))$query['post__in']=[absint($search)];
                else $query['s']=mb_substr($search,0,120);
            }
            $posts=get_posts($query);
            echo '<table class="widefat striped"><thead><tr><th>Content</th><th>Type</th><th>Language</th><th>Customizations</th></tr></thead><tbody>';
            $count=0;
            foreach($posts as $post){
                if(!current_user_can('edit_post',$post->ID))continue;
                $ctx=MAD4B_Tenant_Override_Policy::context($post->ID);
                if(!$ctx)continue;
                $count++;
                $url=admin_url('admin.php?page='.self::SLUG.'&post_id='.$post->ID);
                echo '<tr><td><a href="'.esc_url($url).'">'.esc_html($post->post_title).'</a></td><td>'.esc_html($post->post_type).'</td><td>'.esc_html($ctx['language']).'</td><td><a class="button" href="'.esc_url($url).'">Propose customization</a></td></tr>';
            }
            if(!$count)echo '<tr><td colspan="4">No editable content is available for this role.</td></tr>';
            echo '</tbody></table></div>';return;
        }
        if(!current_user_can('edit_post',$id)){echo '<p>Not authorized for this content.</p></div>';return;}
        $ctx=MAD4B_Tenant_Override_Policy::context($id);
        if(!$ctx){echo '<p>Content classification or language is not ready.</p></div>';return;}
        echo '<h2>'.esc_html($ctx['post']->post_title).' · '.esc_html($ctx['language']).'</h2>';
        MAD4B_Tenant_Preservation::render($id);
        echo '<p>Current post: <a href="'.esc_url(get_edit_post_link($id)).'">WordPress editor</a>. Submit a single field change below; an independent company approver must accept it.</p>';
        $global='local-'.substr(hash('sha256',$ctx['site_uuid'].'|'.$ctx['post']->post_type.'|'.$id),0,32);
        $map=MAD4B_Tenant_Sparse_Store::mapping($global,$ctx['language']);
        if(is_wp_error($map)){echo '<p>Local mapping unavailable.</p></div>';return;}
        $records=$map?MAD4B_Tenant_Sparse_Store::override_rows($map):[];
        if(is_wp_error($records))$records=[];
        echo '<table class="widefat striped"><thead><tr><th>Company field</th><th>Current state</th><th>Propose change</th></tr></thead><tbody>';
        foreach($ctx['definitions'] as $field=>$def){
            if(!in_array($field,['title','excerpt','seo_title','seo_description','seo_focus_keyword',
                'featured_media_global_id','gallery_media','offer_banners'],true))continue;
            $stored=$records[$field]??null;
            $value='';
            if($stored){
                if($def['type']==='text')$value=(string)$stored['value'];
                elseif(is_array($stored['value'])){
                    $items=$def['type']==='media_ref'?[$stored['value']]:$stored['value'];
                    $ids=[];foreach($items as $asset)if(is_array($asset))$ids[]=(int)($asset['attachment_id']??0);
                    $value=implode(',',$ids);
                }
            }
            echo '<tr><td><strong>'.esc_html($def['label']).'</strong><br><code>'.esc_html($field).'</code></td>';
            echo '<td>'.($stored?'Company override · Revision '.(int)$stored['revision']:'Inherited / native').'</td>';
            echo '<td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_workspace_propose_'.$id);
            echo '<input type="hidden" name="action" value="mad4b_workspace_propose">';
            echo '<input type="hidden" name="post_id" value="'.(int)$id.'">';
            echo '<input type="hidden" name="field" value="'.esc_attr($field).'">';
            echo '<input type="hidden" name="expected_revision" value="'.(int)($stored['revision']??0).'">';
            if($def['type']==='text')
                echo '<textarea name="value" rows="2" cols="42">'.esc_textarea($value).'</textarea>';
            else
                echo '<input name="value" type="text" placeholder="Local image attachment IDs" value="'.esc_attr($value).'" size="28">';
            echo ' <button type="submit" class="button">Submit for review</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    public static function approvals(){
        if(!MAD4B_Tenant_Workflow::reviewer())wp_die('Approver permission required',403);
        echo '<div class="wrap"><h1>Company Content Approvals</h1>';
        self::nav();
        if(isset($_GET['reviewed']))echo '<div class="notice notice-success"><p>Review recorded and checked.</p></div>';
        echo '<p>A different user from the submitter must approve or reject each content change. Approved values modify only company-owned sparse fields.</p>';
        $queue=MAD4B_Tenant_Workflow::queue();
        echo '<table class="widefat striped"><thead><tr><th>Request</th><th>Post</th><th>Field</th><th>Proposed change</th><th>Submitted by</th><th>Status</th><th>Decision</th></tr></thead><tbody>';
        foreach($queue as $row) {
            echo '<tr><td>#'.(int)$row['id'].'</td><td>'.esc_html(get_the_title((int)$row['post_id'])).'</td>';
            echo '<td><code>'.esc_html($row['field_path']).'</code></td>';
            $full=MAD4B_Tenant_Workflow::get((int)$row['id']);
            $value=$full?json_decode($full['value_json'],true):null;
            $preview=is_string($value)?$value:wp_json_encode($value,JSON_UNESCAPED_SLASHES);
            echo '<td><code>'.esc_html(mb_substr((string)$preview,0,280)).'</code></td>';
            echo '<td>WP User #'.(int)$row['submitted_by'].'</td>';
            echo '<td>'.esc_html($row['request_state']).'</td><td>';
            if($row['request_state']==='submitted' && (int)$row['submitted_by']!==get_current_user_id()){
                echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
                wp_nonce_field('mad4b_workspace_review_'.$row['id']);
                echo '<input type="hidden" name="action" value="mad4b_workspace_review">';
                echo '<input type="hidden" name="request_id" value="'.(int)$row['id'].'">';
                echo '<button class="button button-primary" name="decision" value="approve">Approve</button> ';
                echo '<button class="button" name="decision" value="reject">Reject</button></form>';
            } else echo 'Requires separate reviewer or reconciliation';
            echo '</td></tr>';
        }
        if(!$queue)echo '<tr><td colspan="7">No pending company requests.</td></tr>';
        echo '</tbody></table></div>';
    }
}
MAD4B_Tenant_Operations_Workspace::register();
