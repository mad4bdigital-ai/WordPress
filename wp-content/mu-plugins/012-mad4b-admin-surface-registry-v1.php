<?php
/**
 * Plugin Name: MAD4B Admin Surface Registry
 * Description: Reviewed read rights and one scoped content-draft permission; no role grants or generic write bypass.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;

/**
 * Phase 2: explicit read caps and ONE review-only draft operation.
 * - Every registered surface declares capabilities and scope.
 * - Generic write, publish, approve and unknown actions deny.
 * - Content Job draft still requires the separate browser nonce, native
 *   runtime mutation gate and exact site/tenant/brand integrity check.
 * - No automatic wp_roles mutations and no bypass of WP native capabilities.
 */
final class MAD4B_Admin_Surface_Registry_V1 {
    public const CONTRACT = 'mad4b.admin-surface-registry.readonly.v1';
    public const VERSION = '20261119.2';
    public const MENU_SLUG = 'mad4b-admin-surface-registry';

    /**
     * Small reviewed seed, not a proxy for other handler authority.
     * Owner of each original handler must opt in before a write contract.
     */
    public static function policies(): array {
        return [
            'mad4b-company-workspaces' => [
                'label'=>'Company Workspaces',
                'read_caps'=>['mad4b_manage_company_operations','edit_others_posts'],
                'write_caps'=>null, 'approve_caps'=>null,
                'scope'=>'current_wordpress_site',
                'handler'=>'MAD4B_Company_Workspaces_V1::overview'
            ],
            'mad4b-company-content-jobs' => [
                'label'=>'Content Jobs (read-only)',
                'read_caps'=>['mad4b_manage_company_operations','edit_others_posts'],
                'write_caps'=>null, 'approve_caps'=>null,
                'scope'=>'original_content_jobs_site_uuid_brand_id_tenant_id',
                'handler'=>'MAD4B_Company_Workspaces_V1::jobs'
            ],
            'mad4b-company-content-job-draft' => [
                'label'=>'Content Job strict-review draft intake (nonce guarded)',
                'read_caps'=>['mad4b_manage_company_operations','mad4b_work_company_tasks','mad4b_propose_company_content','edit_posts'],
                'draft_caps'=>['mad4b_manage_company_operations','mad4b_work_company_tasks','mad4b_propose_company_content','edit_posts'],
                'write_caps'=>null,'approve_caps'=>null,
                'scope'=>'exact_site_brand_tenant_original_integrity_capture',
                'handler'=>'MAD4B_Company_Content_Job_Draft_V1::page'
            ],
            'mad4b-company-staff-roles' => [
                'label'=>'Staff & Roles (navigation only)',
                'read_caps'=>['mad4b_manage_company_operations','mad4b_work_company_tasks','edit_others_posts'],
                'write_caps'=>null, 'approve_caps'=>null,
                'scope'=>'staff_handler_owner_check_required',
                'handler'=>'MAD4B_Scoped_Staff_Management_V1::screen'
            ],
            'mad4b-pricing-workspace' => [
                'label'=>'Pricing Workspace (navigation only)',
                'read_caps'=>['commerce_manage_pricing','mad4b_manage_company_operations','mad4b_work_company_tasks'],
                'write_caps'=>null, 'approve_caps'=>null,
                'scope'=>'original_pricing_handler',
                'handler'=>'MAD4B_Pricing_Workspace_V1::page'
            ],
            'mad4b-pricing-lab' => [
                'label'=>'Pricing Lab (navigation only)',
                'read_caps'=>['commerce_manage_pricing','mad4b_manage_company_operations','mad4b_work_company_tasks'],
                'write_caps'=>null, 'approve_caps'=>null,
                'scope'=>'original_pricing_handler',
                'handler'=>'MAD4B_Pricing_Lab_V1::page'
            ],
            'mad4b-pricing-workbench' => [
                'label'=>'Pricing Workbench (read-only product inspector)',
                'read_caps'=>['commerce_manage_pricing','mad4b_manage_company_operations','mad4b_work_company_tasks'],
                'write_caps'=>null,'approve_caps'=>null,'scope'=>'current_wordpress_site',
                'handler'=>'MAD4B_Pricing_Workbench::page'
            ],
            'mad4b-control-plane-content-pipeline' => [
                'label'=>'Content Pipeline (read-only company report)',
                'read_caps'=>['mad4b_manage_company_operations','mad4b_propose_company_content'],
                'write_caps'=>null,'approve_caps'=>null,'scope'=>'current_wordpress_site',
                'handler'=>'MAD4B_SCP_Dynamic_Content_Pipeline_Admin::render'
            ],
            'mad4b-company-content-hub' => [
                'label'=>'Content Hub (central authority status only)',
                'read_caps'=>['mad4b_manage_company_operations','mad4b_propose_company_content'],
                'write_caps'=>null,'approve_caps'=>null,'scope'=>'current_wordpress_site',
                'handler'=>'MAD4B_Company_Content_Hub_Overview_V1::page'
            ],
            'fluent_forms_all_entries' => [
                'label'=>'Fluent Forms Entries (native per-form scope)',
                'read_caps'=>['fluentform_dashboard_access','fluentform_entries_viewer'],
                'write_caps'=>null,'approve_caps'=>null,'scope'=>'native_fluentform_form_acl',
                'handler'=>'FluentForm\\App\\Modules\\Registerer\\Menu::renderAllEntriesAdminRoute'
            ],
            'fluent_forms_reports' => [
                'label'=>'Fluent Forms Reports (native per-form scope)',
                'read_caps'=>['fluentform_dashboard_access','fluentform_entries_viewer'],
                'write_caps'=>null,'approve_caps'=>null,'scope'=>'native_fluentform_form_acl',
                'handler'=>'FluentForm\\App\\Modules\\Registerer\\Menu::renderReports'
            ],
            'fluent_forms#add=1' => [
                'label'=>'Fluent Forms New Form (native form manager)',
                'read_caps'=>['fluentform_dashboard_access','fluentform_forms_manager'],
                'write_caps'=>null,'approve_caps'=>null,'scope'=>'native_fluentform_form_acl',
                'handler'=>'FluentForm\\App\\Modules\\Registerer\\Menu::renderAddNewFormRoute'
            ],
        ];
    }

    /**
     * FluentCRM v3.2 / v3.3 bridge.
     * URE-owned existing effective company caps determine the original
     * FluentCRM permission list; unknown/future plugin permissions deny.
     * An explicit per-user native permission record is a further upper
     * bound, even for Master Admin (the legacy filter ignored it).
     */
    public static function fluentcrm_permissions($existing,$user): array {
        $original=is_array($existing)?array_values(array_filter($existing,'is_string')):[];
        if (!is_object($user) || empty($user->ID)
            || !in_array('master_admin',(array)($user->roles??[]),true)) return $original;
        $user_id=(int)$user->ID;
        $base=['commerce_manage_crm','mad4b_manage_company_operations'];
        foreach($base as $cap)if(!user_can($user_id,$cap))return [];
        $required=[
            'fcrm_view_dashboard'=>[],
            'fcrm_read_contacts'=>[],
            'fcrm_manage_contacts'=>[],
            'fcrm_manage_contacts_delete'=>['commerce_manage_operations'],
            'fcrm_manage_contacts_export'=>['commerce_manage_operations'],
            'fcrm_manage_contact_cats'=>[],
            'fcrm_manage_contact_cats_delete'=>['commerce_manage_operations'],
            'fcrm_read_emails'=>[],
            'fcrm_manage_emails'=>[],
            'fcrm_manage_email_delete'=>['commerce_manage_operations'],
            'fcrm_manage_email_templates'=>[],
            'fcrm_manage_forms'=>['mad4b_propose_company_content'],
            'fcrm_read_funnels'=>[],
            'fcrm_write_funnels'=>['commerce_manage_operations'],
            'fcrm_delete_funnels'=>['commerce_manage_operations'],
            'fcrm_manage_settings'=>['commerce_manage_operations','commerce_manage_policies'],
        ];
        $native=[];
        if(class_exists('FluentCrm\App\Services\PermissionManager'))
            $native=(array)\FluentCrm\App\Services\PermissionManager::pluginPermissions();
        else return $original; // Degraded dependency: do not change existing grants.
        $approved=[];
        foreach($native as $permission){
            if(!is_string($permission)||!array_key_exists($permission,$required))continue;
            $ok=true;
            foreach($required[$permission] as $cap)if(!user_can($user_id,$cap)){$ok=false;break;}
            if($ok)$approved[]=$permission;
        }
        // Respect a deliberate native per-user FluentCRM manager policy.
        // For an empty native record, an explicit revoke wins over role grants.
        if(function_exists('fluentcrm_get_meta')){
            $meta=fluentcrm_get_meta($user_id, \FluentCrm\App\Models\User::class,'manager_permissions');
            if($meta!==null){
                $explicit=is_array($meta->value??null)?$meta->value:[];
                $approved=array_values(array_intersect($approved,$explicit));
            }
        }
        return array_values(array_unique($approved));
    }

    /** Capability predicate only; never substitutes for a handler's nonce/site/tenant checks. */
    public static function allowed(string $slug,string $operation='read'): bool {
        if (!is_user_logged_in()) return false;
        $policies=self::policies();
        $p=$policies[$slug]??null;
        if (!$p) return false;
        if ($operation === 'read') {
            $caps=$p['read_caps']??null;
        } elseif ($operation === 'draft' && $slug === 'mad4b-company-content-job-draft') {
            // The ONLY explicit draft state; no generic write/publish/approve grant.
            $caps=$p['draft_caps']??null;
        } else {
            return false;
        }
        if (!is_array($caps) || !$caps) return false;
        foreach ($caps as $cap) if (!current_user_can($cap)) return false;
        return true;
    }

    /**
     * Normalized observed menu rows, scoped to the actual current site.
     * This reports menu cap gates; it does not assert callback/REST access.
     */
    public static function live_inventory(): array {
        global $menu,$submenu;
        $rows=[];
        $add=static function($parent,$title,$slug,$cap,$kind) use (&$rows) {
            $slug=(string)$slug;
            if ($slug==='') return;
            $key=$slug;
            if (!isset($rows[$key])) {
                $rows[$key]=[
                    'slug'=>$slug,
                    'title'=>wp_strip_all_tags((string)$title),
                    'parents'=>[],
                    'menu_caps'=>[],
                    'passed_menu_cap'=>false,
                    'source'=>'wordpress_admin_menu',
                ];
            }
            $rows[$key]['parents'][]=(string)$parent;
            $rows[$key]['menu_caps'][]=(string)$cap;
            if ($cap!=='' && current_user_can((string)$cap)) $rows[$key]['passed_menu_cap']=true;
        };
        foreach ((array)$menu as $item) {
            if (is_array($item) && isset($item[2])) {
                $add('<top>',$item[0]??'',$item[2],$item[1]??'','top');
            }
        }
        foreach ((array)$submenu as $parent=>$items) foreach ((array)$items as $item) {
            if (is_array($item) && isset($item[2])) {
                $add($parent,$item[0]??'',$item[2],$item[1]??'','sub');
            }
        }
        $policies=self::policies();
        foreach ($rows as &$entry) {
            $entry['parents']=array_values(array_unique($entry['parents']));
            $entry['menu_caps']=array_values(array_unique($entry['menu_caps']));
            $entry['policy_state']=isset($policies[$entry['slug']])?'explicit_read_policy':'unmapped_review_required';
            $entry['registry_read_gate']=self::allowed($entry['slug'],'read');
            $entry['registry_write_gate']=false;
            $entry['registry_approve_gate']=false;
            $entry['handler_authorization_certified']=false;
        }
        unset($entry);
        ksort($rows,SORT_STRING);
        return array_values($rows);
    }

    private static function can_see_inventory(): bool {
        return current_user_can('mad4b_manage_company_operations')
            && current_user_can('edit_others_posts');
    }

    public static function menu(): void {
        if (!self::can_see_inventory()
            || !class_exists('MAD4B_Company_Workspaces_V1',false)) return;
        $parent=MAD4B_Company_Workspaces_V1::menu_parent();
        add_submenu_page(
            $parent,'MAD4B Admin Surfaces','Admin Surfaces',
            'mad4b_manage_company_operations',self::MENU_SLUG,
            [__CLASS__,'page']
        );
    }

    public static function page(): void {
        if (!self::can_see_inventory()) wp_die(esc_html__('Company operations access required.','mad4b'));
        $inventory=self::live_inventory();
        $total=count($inventory);
        $pass=count(array_filter($inventory,static fn($r)=>$r['passed_menu_cap']));
        $mapped=count(array_filter($inventory,static fn($r)=>$r['policy_state']==='explicit_read_policy'));
        $view=isset($_GET['surface_view']) && is_string($_GET['surface_view'])
            ? sanitize_key(wp_unslash($_GET['surface_view'])) : 'all';
        if(!in_array($view,['all','denied','permitted','mapped'],true))$view='all';
        $visible=array_values(array_filter($inventory,static function($r) use($view){
            if($view==='denied')return !$r['passed_menu_cap'];
            if($view==='permitted')return $r['passed_menu_cap'];
            if($view==='mapped')return $r['policy_state']==='explicit_read_policy';
            return true;
        }));
        $limit=80;
        $page=isset($_GET['surface_page'])?max(1,absint($_GET['surface_page'])):1;
        $pages=max(1,(int)ceil(count($visible)/$limit));
        $page=min($page,$pages);
        $current=array_slice($visible,($page-1)*$limit,$limit);
        echo '<div class="wrap"><h1>MAD4B Admin Surface Registry</h1>';
        echo '<p>Read-only inventory of current WordPress menu capability gates. Passing a menu check does not authorize a page callback, REST request, AJAX operation, publication or owner approval. Unmapped surfaces default to deny in registry policy checks.</p>';
        echo '<p>Site: <code>'.esc_html(home_url()).'</code> · Menu slugs: <strong>'.esc_html((string)$total).
            '</strong> · Menu capability pass: <strong>'.esc_html((string)$pass).
            '</strong> · Explicit read policies: <strong>'.esc_html((string)$mapped).'</strong></p>';
        echo '<p>';
        foreach(['all'=>'All','denied'=>'Menu gate denied','permitted'=>'Menu gate passed','mapped'=>'Registry mapped'] as $key=>$label) {
            $url=add_query_arg(['page'=>self::MENU_SLUG,'surface_view'=>$key],admin_url('admin.php'));
            echo '<a class="button'.($view===$key?' button-primary':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a> ';
        }
        echo '</p><table class="widefat striped"><thead><tr><th>Page slug</th><th>Title</th><th>Menu capability</th><th>Current menu gate</th><th>MAD4B policy</th></tr></thead><tbody>';
        foreach ($current as $entry) {
            echo '<tr><td><code>'.esc_html($entry['slug']).'</code></td><td>'.esc_html($entry['title']).'</td><td>'.
                esc_html(implode(' | ',$entry['menu_caps'])).'</td><td>'.
                ($entry['passed_menu_cap']?'Pass':'Denied').'</td><td>'.
                esc_html($entry['policy_state']).'</td></tr>';
        }
        if(!$current) echo '<tr><td colspan="5">No matching registered surfaces</td></tr>';
        echo '</tbody></table><p>Page '.esc_html((string)$page).' of '.esc_html((string)$pages).'</p>';
        if ($page>1) {
            echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>self::MENU_SLUG,'surface_view'=>$view,'surface_page'=>$page-1],admin_url('admin.php'))).'">Previous</a> ';
        }
        if ($page<$pages) {
            echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>self::MENU_SLUG,'surface_view'=>$view,'surface_page'=>$page+1],admin_url('admin.php'))).'">Next</a>';
        }
        echo '</div>';
    }

    public static function boot(): void {
        add_action('admin_menu',[__CLASS__,'menu'],1750);
    }
}
MAD4B_Admin_Surface_Registry_V1::boot();
