<?php
/**
 * Plugin Name: MAD4B Destination Term Alerts
 * Description: Read-only, site-bound destination Term Meta link/media evidence for Content Readiness.
 */
defined('ABSPATH') || exit;

final class MAD4B_Destination_Term_Alerts_V1 {
    const SOURCE = 'mad4b-destination-term-integrity';
    const CONTRACT = 'mad4b.destination-term-integrity.v1';
    const CODES = array(
        'term_external_link_attribution' => true,
        'term_foreign_media_origin' => true,
        'term_media_local_missing' => true,
    );
    private static $coverage = null;
    private static $issue_keys = array();
    private static $readback_count = 0;

    private static function site_uuid() {
        return class_exists('MAD4B_SCP_Site_Profile', false)
            ? (string) MAD4B_SCP_Site_Profile::site_uuid() : '';
    }
    private static function url_host($url) {
        $parts = wp_parse_url(html_entity_decode((string)$url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
    }
    private static function url_tokens($raw) {
        $tokens = array();
        // JetEngine galleries may be comma-separated URLs, serialized arrays,
        // or JSON values. Never treat an entire CSV sequence as one filename.
        preg_match_all('~https?://[^\s"\'<>\\\\]+~i', (string)$raw, $matches);
        foreach ($matches[0] as $sequence) {
            foreach (preg_split('~,(?=https?://)~i', $sequence) as $url) {
                $url = html_entity_decode(rtrim($url, '.,;)]}'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_contains($url, '\u')) {
                    $decoded = json_decode('"'.str_replace('"','\\"',$url).'"', true);
                    if (is_string($decoded)) $url = $decoded;
                }
                if ($url !== '') $tokens[] = $url;
            }
        }
        return array_values(array_unique($tokens));
    }
    private static function link_tokens($raw, $key) {
        $links = array();
        preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~is', (string)$raw, $matches);
        foreach ($matches[2] ?? array() as $url) {
            $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($url !== '') $links[] = $url;
        }
        if (!$links && preg_match('/(?:^|_)(?:links?|url|website|booking)(?:$|_)/i', $key)) {
            $candidate = trim((string)$raw);
            if (preg_match('~^https?://[^\s<>"\']+$~i', $candidate)) $links[] = $candidate;
        }
        return array_values(array_unique($links));
    }
    private static function local_upload_status($url) {
        $parsed = wp_parse_url($url);
        if (!is_array($parsed)) return null;
        $path = rawurldecode((string)($parsed['path'] ?? ''));
        $marker = '/wp-content/uploads/';
        $pos = strpos($path, $marker);
        if ($pos === false) return null;
        $relative = substr($path, $pos + strlen($marker));
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) return null;
        $uploads = wp_get_upload_dir();
        $base = rtrim((string)($uploads['basedir'] ?? ''), '/');
        if ($base === '') return null;
        return is_file($base . '/' . $relative);
    }
    private static function group_add(&$group, $kind, $key, $meta_id, $url, $host) {
        if (!isset($group[$kind])) $group[$kind] = array('count'=>0, 'fields'=>array(), 'meta_ids'=>array(), 'urls'=>array(), 'hosts'=>array());
        $item = &$group[$kind];
        $item['count']++;
        $item['fields'][$key] = true;
        $item['meta_ids'][$meta_id] = true;
        if (count($item['urls']) < 6) $item['urls'][$url] = true;
        if ($host !== '') $item['hosts'][$host] = true;
    }
    public static function scan() {
        global $wpdb;
        $site = self::site_uuid();
        $site_host = self::url_host(home_url('/'));
        $coverage = array('contract'=>self::CONTRACT, 'complete'=>false, 'site_uuid'=>$site,
            'taxonomy'=>'destinations','scanned_rows'=>0,'scanned_terms'=>0,'issues'=>0);
        self::$coverage = $coverage;
        self::$issue_keys = array();
        self::$readback_count = 0;
        if ($site === '' || $site_host === '' || !taxonomy_exists('destinations')) return array('complete'=>false,'issues'=>array(),'coverage'=>$coverage);
        $sql = $wpdb->prepare("SELECT m.meta_id,m.term_id,m.meta_key,m.meta_value
            FROM {$wpdb->termmeta} m INNER JOIN {$wpdb->term_taxonomy} t ON t.term_id=m.term_id
            WHERE t.taxonomy=%s ORDER BY m.term_id,m.meta_id LIMIT 15001", 'destinations');
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error) || count($rows) > 15000)
            return array('complete'=>false,'issues'=>array(),'coverage'=>$coverage);
        $approved = (array)apply_filters('mad4b/destination_term_integrity/approved_hosts', array(), $site);
        $approved[] = $site_host;
        $approved[] = preg_replace('/^www\./', '', $site_host);
        $approved = array_values(array_filter(array_unique(array_map('strtolower', $approved))));
        $groups = array(); $terms = array();
        foreach ($rows as $row) {
            $tid = (int)$row['term_id']; $key = (string)$row['meta_key'];
            $value = (string)$row['meta_value']; $meta_id=(int)$row['meta_id'];
            $terms[$tid] = true;
            // Non-public/operational, source-credit, attribution and citation
            // fields are not visitor-facing promotional placements.
            if ($value === '' || str_starts_with($key, '_')) continue;
            $editorial = (bool)preg_match('/(?:source|credit|citation|reference|provenance|license|attribution|^video_url$|youtube|social|instagram|facebook|tiktok)/i', $key);
            $media_field = (bool)preg_match('/(?:image|gellery|gallery|photo|thumbnail|hero|media)/i', $key);
            if (!$editorial) foreach (self::link_tokens($value, $key) as $url) {
                $host=self::url_host($url);
                if ($host!=='' && !in_array($host,$approved,true)) {
                    self::group_add($groups[$tid], 'links', $key, $meta_id, $url, $host);
                    if ($key==='internal_links' || str_contains($key,'booking'))
                        $groups[$tid]['links']['commercial']=true;
                }
            }
            if (!$media_field) continue;
            foreach (self::url_tokens($value) as $url) {
                $host=self::url_host($url);
                $path=(string)(wp_parse_url($url,PHP_URL_PATH) ?: '');
                if (!str_contains($path,'/wp-content/uploads/')
                    && !preg_match('/\.(?:jpe?g|png|webp|gif|avif|svg)(?:$|\?)/i',$path)) continue;
                $local=self::local_upload_status($url);
                if ($local===false) self::group_add($groups[$tid], 'missing', $key, $meta_id, $url, $host);
                if ($host!=='' && !in_array($host,$approved,true))
                    self::group_add($groups[$tid], 'media', $key, $meta_id, $url, $host);
            }
        }
        $issues = array();
        $map = array('links'=>'term_external_link_attribution',
            'media'=>'term_foreign_media_origin','missing'=>'term_media_local_missing');
        foreach ($groups as $tid=>$group) foreach ($map as $kind=>$code) {
            if (empty($group[$kind]['count'])) continue;
            $g=$group[$kind]; $term=get_term((int)$tid,'destinations');
            if (!$term || is_wp_error($term)) return array('complete'=>false,'issues'=>array(),'coverage'=>$coverage);
            $severity=$kind==='missing' || ($kind==='links' && !empty($g['commercial']))?'high':'medium';
            $fields=array_keys($g['fields']);
            $hosts=array_keys($g['hosts']);
            $sample=array_keys($g['urls']);
            $evidence=array('contract'=>self::CONTRACT,'site_uuid'=>$site,
                'taxonomy'=>'destinations','term_id'=>(int)$tid,
                'fields'=>$fields,'meta_ids'=>array_map('intval',array_keys($g['meta_ids'])),
                'hosts'=>$hosts,'sample_urls'=>$sample,'reference_count'=>(int)$g['count'],
                'commercial'=>!empty($g['commercial']),
                'source_sha256'=>hash('sha256',wp_json_encode(array($tid,$code,$g))),
                'requires_render_readback'=>true,'automatically_rewritten'=>false);
            $label=$kind==='links'?'External company links in destination Term Meta':
                ($kind==='media'?'Foreign-origin image URLs stored in destination Term Meta':
                    'Destination gallery/featured image file missing locally');
            $id=hash('sha256',get_current_blog_id().'|destinations|'.$tid.'|'.$code);
            self::$issue_keys[$id]=true;
            $issues[]=array('id'=>$id,'post_type'=>'destinations','object_id'=>(int)$tid,
                'code'=>$code,'severity'=>$severity,'label'=>$label.' · '.implode(', ',$fields).
                    ($hosts?' · '.implode(', ',$hosts):''),
                'source'=>self::SOURCE,'source_evidence'=>$evidence);
        }
        $coverage['complete']=true;
        $coverage['scanned_rows']=count($rows);
        $coverage['scanned_terms']=count($terms);
        $coverage['issues']=count($issues);
        self::$coverage=$coverage;
        return array('complete'=>true,'issues'=>$issues,'coverage'=>$coverage);
    }
    public static function coverage() { return self::$coverage; }
    public static function external_issues($issues, $rules) {
        if (!is_array($issues)) return $issues;
        $report=self::scan();
        if (empty($report['complete'])) {
            // An incomplete discovery must never silently close old findings.
            $issues[]=array('source'=>self::SOURCE,'code'=>'term_scan_incomplete');
            return $issues;
        }
        return array_merge($issues,$report['issues']);
    }
    public static function can_resolve($issue) {
        if (empty(self::$coverage['complete']) || self::$coverage['site_uuid']!==self::site_uuid()) return false;
        $id=(string)($issue['id']??'');
        if (isset(self::$issue_keys[$id])) return false;
        $e=(array)($issue['source_evidence']??array());
        $tid=(int)($issue['object_id']??0);
        if (($e['contract']??'')!==self::CONTRACT
            || ($e['site_uuid']??'')!==self::site_uuid()
            || ($e['taxonomy']??'')!=='destinations'
            || (int)($e['term_id']??0)!==$tid
            || !get_term($tid,'destinations')) return false;
        global $wpdb;
        // Read the original field values again; a missed parser match or
        // short/partial scan must never be mistaken for a corrected source.
        $source_fields=(array)($e['fields']??array());
        foreach ($source_fields as $field) {
            if (!is_string($field) || $field==='' || strlen($field)>160) return false;
            $raw_values=$wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id=%d AND meta_key=%s",
                $tid,$field));
            if (!is_array($raw_values) || $wpdb->last_error!=='') return false;
            foreach ((array)$raw_values as $raw) {
                if (!is_scalar($raw)) continue;
                foreach ((array)($e['sample_urls']??array()) as $old_url)
                    if (is_string($old_url) && $old_url!=='' && str_contains((string)$raw,$old_url))
                        return false;
            }
        }
        // Source re-read: re-run against the current raw termmeta record at
        // the exact subject. A disappeared row alone is never enough.
        $current=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->termmeta} m
            INNER JOIN {$wpdb->term_taxonomy} t ON t.term_id=m.term_id
            WHERE t.taxonomy=%s AND m.term_id=%d",'destinations',$tid));
        if ($current===null || (int)$current<1) return false;
        $url=get_term_link($tid,'destinations');
        if (is_wp_error($url) || !is_string($url) || $url==='') return false;
        // Bounded front-end observation, only for candidate resolutions.
        // Browser checks are bounded to five candidate closures per scan.
        // Slow/cold staging pages must not make the whole scan unbounded.
        if (self::$readback_count >= 5) return false;
        self::$readback_count++;
        $res=wp_safe_remote_get($url,array('timeout'=>12,'redirection'=>2,
            'limit_response_size'=>2097152,'headers'=>array('Cache-Control'=>'no-cache'),
            'user-agent'=>'MAD4B-Destination-Term-Readback/1.0'));
        if (is_wp_error($res) || (int)wp_remote_retrieve_response_code($res)!==200) return false;
        $html=(string)wp_remote_retrieve_body($res);
        // The full closing HTML document must be observed: a truncated
        // response could hide a stale external link beyond the byte limit.
        if (strlen($html)<300 || stripos($html,'<html')===false
            || stripos($html,'</html>')===false) return false;
        foreach ((array)($e['sample_urls']??array()) as $old_url)
            if (is_string($old_url) && $old_url!=='' &&
                (str_contains($html,$old_url)||str_contains(html_entity_decode($html,ENT_QUOTES|ENT_HTML5,'UTF-8'),$old_url)))
                return false;
        return true;
    }
}
add_filter('mad4b/content_readiness/external_issues',
    array('MAD4B_Destination_Term_Alerts_V1','external_issues'),30,2);

