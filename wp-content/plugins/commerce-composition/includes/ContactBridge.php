<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Explicit cross-plugin relationships. FluentCRM remains the contact owner;
 * Fluent Forms, source CPT, Request Registry, supplier workflow and Woo Orders
 * remain authoritative for their own data.
 */
final class ContactBridge
{
    private static function table():string
    {
        global $wpdb;
        return $wpdb->prefix.'commerce_contact_links';
    }
    public static function install():true|\WP_Error
    {
        global $wpdb;
        $t=self::table();
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))
            return new \WP_Error('contact_bridge_identifier_invalid');
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            contact_id bigint(20) unsigned NOT NULL,
            domain varchar(36) NOT NULL,
            source_key varchar(90) NOT NULL,
            source_id bigint(20) unsigned NOT NULL,
            role varchar(36) NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            created_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY link_identity (contact_id,domain,source_key,source_id,role),
            KEY source_lookup (domain,source_key,source_id),
            KEY contact_lookup (contact_id)
        ) {$wpdb->get_charset_collate()};");
        return self::ready()?true:new \WP_Error('contact_bridge_install_failed');
    }
    public static function ready():bool
    {
        global $wpdb;
        $t=self::table();
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))return false;
        $engine=$wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $t
        ));
        return $engine==='InnoDB';
    }
    /**
     * Owner-configurable source mapping. A CPT slug/meta key is site data,
     * never a hardcoded case in the reusable implementation.
     */
    public static function sources():array
    {
        $config=get_option('commerce_contact_bridge_sources_v1',[]);
        return is_array($config)&&isset($config['sources'])&&
            is_array($config['sources'])?$config['sources']:[];
    }
    private static function source(string $key):array|\WP_Error
    {
        if(!preg_match('/^[a-z][a-z0-9_-]{1,89}$/D',$key))
            return new \WP_Error('contact_bridge_source_key_invalid');
        $map=self::sources();
        $config=$map[$key]??null;
        if(!is_array($config)||!in_array($config['domain']??null,
            ['cpt','fluent_form','request','woo_order'],true))
            return new \WP_Error('contact_bridge_source_not_configured');
        return $config;
    }
    public static function sourceEvidence(string $key,int $id):array|\WP_Error
    {
        if($id<1)return new \WP_Error('contact_bridge_source_id_invalid');
        $source=self::source($key);
        if(is_wp_error($source))return $source;
        $domain=$source['domain'];
        if($domain==='cpt'){
            $type=$source['post_type']??null;
            if(!is_string($type)||!post_type_exists($type))
                return new \WP_Error('contact_bridge_post_type_missing');
            $post=get_post($id);
            if(!$post||$post->post_type!==$type||in_array($post->post_status,
                ['trash','auto-draft','inherit'],true))
                return new \WP_Error('contact_bridge_post_not_valid');
            $projection=[];
            $rules=$source['field_projection']??[];
            if(!is_array($rules)||count($rules)>30)
                return new \WP_Error('contact_bridge_projection_schema_invalid');
            foreach($rules as $field=>$spec) {
                if(!is_string($field)||!preg_match('/^[a-z][a-z0-9_-]{0,59}$/D',$field)||
                    !is_array($spec)||!is_string($spec['meta_key']??null)||
                    !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,119}$/D',$spec['meta_key']))
                    return new \WP_Error('contact_bridge_projection_field_invalid');
                $value=get_post_meta($id,$spec['meta_key'],true);
                // Source-of-truth metadata is private. The CRM shows only
                // existence; never supplier IDs, contract data or raw values.
                $present=$value!=='' && $value!==null && $value!==[];
                $projection[]=[
                    'key'=>$field,'label'=>is_string($spec['label']??null)
                        ?sanitize_text_field($spec['label']):$field,
                    'state'=>$present?'linked':'not_set',
                ];
            }
            return ['domain'=>'cpt','source_type'=>$type,'source_id'=>$id,
                'status'=>$post->post_status,
                'label'=>get_post_type_object($type)->labels->singular_name,
                'field_projection'=>$projection];
        }
        global $wpdb;
        if($domain==='fluent_form'){
            $t=$wpdb->prefix.'fluentform_submissions';
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT id,form_id,status FROM $t WHERE id=%d",$id),ARRAY_A);
            $ids=$source['form_ids']??[];
            if(!is_array($row)||!is_array($ids)||
                !in_array((int)$row['form_id'],array_map('intval',$ids),true))
                return new \WP_Error('contact_bridge_form_not_allowed');
            return ['domain'=>'fluent_form','source_type'=>(string)$row['form_id'],
                'source_id'=>$id,'status'=>(string)$row['status'],'label'=>'Form submission'];
        }
        if($domain==='request'){
            $t=$wpdb->prefix.'commerce_request_batches';
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT id,state,pricing_state FROM $t WHERE id=%d",$id),ARRAY_A);
            if(!is_array($row)||($row['state']??null)!=='pending_review')
                return new \WP_Error('contact_bridge_request_not_active');
            return ['domain'=>'request','source_type'=>'request_registry',
                'source_id'=>$id,'status'=>$row['state'],
                'pricing_state'=>$row['pricing_state'],'label'=>'Booking request'];
        }
        if($domain==='woo_order'){
            $order=wc_get_order($id);
            if(!$order||$order->get_type()!=='shop_order')
                return new \WP_Error('contact_bridge_not_commercial_order');
            return ['domain'=>'woo_order','source_type'=>'shop_order',
                'source_id'=>$id,'status'=>$order->get_status(),
                'label'=>'Commercial order'];
        }
        return new \WP_Error('contact_bridge_unsupported_source');
    }
    private static function canReferenceCpt(string $key,int $postId):bool
    {
        if(current_user_can('edit_post',$postId))return true;
        $source=self::source($key);
        if(is_wp_error($source))return false;
        // A site-specific owner may allow cross-system *reference visibility*
        // without granting the CRM an ability to edit the supplier's CPT.
        return ($source['reference_capability']??null)==='manage_options'
            && current_user_can('manage_options');
    }
    public static function link(int $contactId,string $key,int $sourceId,string $role):array|\WP_Error
    {
        if(!current_user_can('manage_options'))
            return new \WP_Error('contact_bridge_permission_denied');
        if(!self::ready())return new \WP_Error('contact_bridge_schema_not_ready');
        if($contactId<1 || !preg_match('/^[a-z][a-z0-9_-]{1,35}$/D',$role))
            return new \WP_Error('contact_bridge_identity_invalid');
        global $wpdb;
        $contacts=$wpdb->prefix.'fc_subscribers';
        $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM $contacts WHERE id=%d",$contactId));
        if(!$exists)return new \WP_Error('contact_bridge_contact_not_found');
        $source=self::sourceEvidence($key,$sourceId);
        if(is_wp_error($source))return $source;
        if($source['domain']==='cpt'&&!self::canReferenceCpt($key,$sourceId))
            return new \WP_Error('contact_bridge_cpt_access_denied');
        $table=self::table();
        $insert=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table
            (contact_id,domain,source_key,source_id,role,actor_id,created_utc)
            VALUES (%d,%s,%s,%d,%s,%d,%s)",
            $contactId,$source['domain'],$key,$sourceId,$role,
            get_current_user_id(),gmdate('Y-m-d H:i:s')
        ));
        if($insert===false)return new \WP_Error('contact_bridge_link_write_failed');
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,contact_id,domain,source_key,source_id,role FROM $table
             WHERE contact_id=%d AND domain=%s AND source_key=%s
             AND source_id=%d AND role=%s",
            $contactId,$source['domain'],$key,$sourceId,$role
        ),ARRAY_A);
        if(!is_array($row))return new \WP_Error('contact_bridge_link_readback_failed');
        if($insert===1)
            do_action('commerce_composition_contact_linked',$row,$source);
        return ['status'=>$insert===1?'linked':'already_linked',
            'relation_id'=>(int)$row['id'],'contact_id'=>$contactId,
            'source_key'=>$key,'source_id'=>$sourceId,'role'=>$role];
    }
    public static function forContact(int $contactId,int $limit=40):array|\WP_Error
    {
        if(!current_user_can('manage_options'))
            return new \WP_Error('contact_bridge_permission_denied');
        if($contactId<1||!self::ready())
            return new \WP_Error('contact_bridge_unavailable');
        global $wpdb;
        $table=self::table();
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT id,domain,source_key,source_id,role,created_utc
             FROM $table WHERE contact_id=%d ORDER BY id DESC LIMIT %d",
            $contactId,max(1,min(100,$limit))
        ),ARRAY_A);
        $results=[];
        foreach($rows as $row){
            $ev=self::sourceEvidence((string)$row['source_key'],(int)$row['source_id']);
            if(is_wp_error($ev))continue;
            if($row['domain']==='cpt'&&!self::canReferenceCpt($row['source_key'],(int)$row['source_id']))
                continue;
            $results[]=[
                'relation_id'=>(int)$row['id'],'domain'=>$row['domain'],
                'source_key'=>$row['source_key'],'source_id'=>(int)$row['source_id'],
                'role'=>$row['role'],'status'=>$ev['status'],
                'label'=>$ev['label'],
                'field_projection'=>$ev['field_projection']??[],
                'created_utc'=>$row['created_utc']
            ];
        }
        return $results;
    }
}
