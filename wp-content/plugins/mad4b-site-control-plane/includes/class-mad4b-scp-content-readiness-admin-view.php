<?php
/** MAD4B Content Readiness — bounded admin presentation. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Content_Readiness_Admin_View {
    public static function render() {
        if(!current_user_can('edit_others_posts'))wp_die('Forbidden',403);
        $rules=MAD4B_SCP_Content_Readiness_Alerts::rules();$issues=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());$counts=MAD4B_SCP_Content_Readiness_Alerts::summary($issues);
        $flash=get_transient('mad4b_readiness_result_'.get_current_user_id());
        delete_transient('mad4b_readiness_result_'.get_current_user_id());
        echo '<div class="wrap"><h1>Content Readiness &amp; Alerts</h1>';
        echo '<p>Source-backed issue ledger. Approved local scheduler reconciles issues; external tasks and messages remain governed separately.</p>';
        echo '<p><a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page='.MAD4B_SCP_Readiness_Workflow_Options::PAGE)).'">Follow-up &amp; Notifications Settings</a></p>';
        if($flash)echo '<div class="notice notice-info"><pre>'.esc_html(wp_json_encode($flash)).'</pre></div>';
        echo '<h2>Open: '.(int)$counts['open'].' | Critical: '.(int)$counts['critical'].' | High: '.(int)$counts['high'].' | Resolved: '.(int)$counts['resolved'].'</h2>';
        if(current_user_can('manage_options')) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_readiness_scan');
        echo '<input type="hidden" name="action" value="mad4b_readiness_scan">';
        submit_button('Scan & reconcile content issues','primary','submit',false);echo '</form>';
        echo '<h2>Site-specific source rules</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_readiness_rules');echo '<input type="hidden" name="action" value="mad4b_readiness_save_rules">';
        $fields=array('post_types'=>'Content post types (comma-separated)',
            'origin_fields'=>'Origin meta by post type',
            'origin_source_fields'=>'Origin source term ID meta by post type',
            'origin_source_taxonomies'=>'Origin term taxonomy by post type',
            'location_taxonomies'=>'Location taxonomy by post type',
            'media_fields'=>'Media meta by post type',
            'owner_fields'=>'Owner meta by post type',
            'end_date_fields'=>'End date meta by post type',
            'stop_sale_fields'=>'Stop sale meta by post type');
        foreach($fields as $field=>$name){
            $value=$rules[$field];
            if(is_array($value)) {
                $parts=array();foreach($value as $t=>$key)$parts[]=is_string($t)?$t.':'.$key:$key;
                $value=implode(', ',$parts);
            }
            echo '<p><label>'.esc_html($name).'<br><input class="large-text" name="'.esc_attr($field).'" value="'.esc_attr((string)$value).'"></label></p>';
        }
        echo '<p><label>Disallowed origin levels (CSV)<br><input class="large-text" name="invalid_origin_levels" value="'.esc_attr(implode(',',(array)$rules['invalid_origin_levels'])).'"></label></p>';
        foreach(array('scheduled_scan_interval_minutes'=>'Scheduled scan minimum interval (minutes)', 'media_reuse_threshold'=>'Shared gallery review threshold (0 disables)', 'form_id'=>'Fluent Forms ID', 'form_unread_days'=>'Unread follow-up threshold (days)') as $field=>$label)
            echo '<p><label>'.esc_html($label).' <input type="number" min="0" name="'.esc_attr($field).'" value="'.(int)$rules[$field].'"></label></p>';
        foreach(array('scheduled_scan_enabled'=>'Enable local server-side scheduled scans (issue ledger only; no notifications or tasks)','check_wpml'=>'Detect missing WPML language','check_cron'=>'Detect overdue WP-Cron') as $field=>$label)
            echo '<p><label><input type="checkbox" name="'.esc_attr($field).'" '.checked($rules[$field],true,false).'>'.esc_html($label).'</label></p>';
        submit_button('Save rules');echo '</form>';
        }
        $requestedStatus=sanitize_key((string)wp_unslash($_GET['readiness_status']??'open'));
        if(!in_array($requestedStatus,array('open','resolved','all'),true))$requestedStatus='open';
        $requestedSeverity=sanitize_key((string)wp_unslash($_GET['readiness_severity']??'all'));
        if(!in_array($requestedSeverity,array('all','critical','high','medium','low'),true))$requestedSeverity='all';
        $rows=array_values(array_filter($issues,static function($row) use($requestedStatus,$requestedSeverity){
            if(!is_array($row))return false;
            $state=(($row['status']??'open')==='resolved')?'resolved':'open';
            return ($requestedStatus==='all'||$state===$requestedStatus) &&
                ($requestedSeverity==='all'||($row['severity']??'medium')===$requestedSeverity);
        }));
        MAD4B_SCP_Content_Readiness_Alerts::sort_issues($rows);
        $totalRows=count($rows);
        $totalPages=max(1,(int)ceil($totalRows/100));
        $issuePage=max(1,min($totalPages,absint($_GET['readiness_page']??1)));
        echo '<h2>Issues</h2><form method="get" style="margin:12px 0">';
        echo '<input type="hidden" name="page" value="'.esc_attr(MAD4B_SCP_Content_Readiness_Alerts::PAGE).'">';
        foreach(array('readiness_status'=>array('open'=>'Open','resolved'=>'Resolved','all'=>'All'),
                      'readiness_severity'=>array('all'=>'All severities','critical'=>'Critical','high'=>'High','medium'=>'Medium','low'=>'Low')) as $field=>$options){
            $selected=$field==='readiness_status'?$requestedStatus:$requestedSeverity;
            echo '<label>'.esc_html($field==='readiness_status'?'Status':'Severity').' <select name="'.esc_attr($field).'">';
            foreach($options as $key=>$value)echo '<option value="'.esc_attr($key).'" '.selected($selected,$key,false).'>'.esc_html($value).'</option>';
            echo '</select></label> ';
        }
        echo '<button class="button">Filter</button></form>';
        $exportUrl=wp_nonce_url(add_query_arg(array('action'=>'mad4b_readiness_export',
            'readiness_status'=>$requestedStatus,'readiness_severity'=>$requestedSeverity),
            admin_url('admin-post.php')),'mad4b_readiness_export');
        echo '<p><a class="button button-secondary" href="'.esc_url($exportUrl).'">Export matching issues (CSV)</a></p>';
        echo '<p class="description">Showing '.($totalRows?((($issuePage-1)*100)+1):0).'-'.min($issuePage*100,$totalRows).' of '.$totalRows.' matching issues.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Severity</th><th>Source</th><th>Issue</th><th>Status</th><th>Fluent Boards</th></tr></thead><tbody>';
        foreach(array_slice($rows,($issuePage-1)*100,100) as $row) {
            $url=$row['object_id']?get_edit_post_link($row['object_id']):'';
            $label=esc_html((string)$row['post_type'].' #'.(int)$row['object_id']);
            if($url)$label='<a href="'.esc_url($url).'">'.$label.'</a>';
            $evidence=(array)($row['source_evidence']??array());
            if (!empty($evidence['term_id']) && !empty($evidence['taxonomy'])) {
                $termUrl=get_edit_term_link(absint($evidence['term_id']),sanitize_key($evidence['taxonomy']));
                $sourceLabel='Source: '.sanitize_key($evidence['taxonomy']).' #'.absint($evidence['term_id']).
                    ' / '.sanitize_key($evidence['level']??'unverified');
                if (is_string($termUrl) && $termUrl)
                    $label.='<p class="description"><a href="'.esc_url($termUrl).'">'.esc_html($sourceLabel).'</a></p>';
                else $label.='<p class="description">'.esc_html($sourceLabel).'</p>';
            }
            echo '<tr><td>'.esc_html($row['severity']).'</td><td>'.$label.'</td><td>'.esc_html($row['label']).'</td><td>'.esc_html($row['status']).'</td><td>';
            if(!empty($row['board_task_id']))echo 'Task #'.(int)$row['board_task_id'];
            elseif(($row['status']??'')!=='resolved' && current_user_can('manage_options')) {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('mad4b_readiness_board_sync');
                echo '<input type="hidden" name="action" value="mad4b_readiness_board_sync">';
                echo '<input type="hidden" name="issue_id" value="'.esc_attr($row['id']).'">';
                echo '<button class="button" '.disabled(empty(MAD4B_SCP_Readiness_Workflow_Options::settings()['board_tasks'])||!class_exists('MAD4B_SCP_Policy')||!MAD4B_SCP_Policy::can_mutate(),true,false).'>Create Board Task</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if($totalPages>1){
            echo '<p class="tablenav-pages">Page '.(int)$issuePage.' of '.(int)$totalPages.' ';
            foreach(array('Previous'=>$issuePage-1,'Next'=>$issuePage+1) as $label=>$target){
                if($target<1||$target>$totalPages)continue;
                $url=add_query_arg(array('page'=>MAD4B_SCP_Content_Readiness_Alerts::PAGE,'readiness_status'=>$requestedStatus,
                    'readiness_severity'=>$requestedSeverity,'readiness_page'=>$target),admin_url('admin.php'));
                echo '<a class="button" style="margin-left:8px" href="'.esc_url($url).'">'.esc_html($label).'</a>';
            }
            echo '</p>';
        }
        echo '</div>';
    }
}
