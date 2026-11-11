<?php
/**
 * Plugin Name: MAD4B Control — Host Performance Readback
 * Description: Read-only host-performance telemetry inside the existing MAD4B Control Plane menu.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Host_Performance_Readback {
    const CONTRACT = 'mad4b.host-performance-telemetry.v1';
    const PAGE = 'mad4b-host-performance';
    public static function file(): string {
        return WPMU_PLUGIN_DIR.'/.mad4b-host-telemetry/status.json';
    }
    public static function snapshot(): array {
        $p=self::file();
        if (is_link($p) || !is_file($p) || !is_readable($p)) return ['state'=>'unavailable'];
        $size=filesize($p);
        if ($size===false || $size>131072 || $size<50) return ['state'=>'invalid'];
        $d=json_decode((string)file_get_contents($p),true);
        if (!is_array($d) || ($d['contract']??'')!==self::CONTRACT
            || ($d['environment']??'')!=='staging'
            || empty($d['readonly']) || !isset($d['host']['metrics'])
            || !is_array($d['host']['metrics']) || !is_array($d['sites']??null)
            || !is_array($d['events']??null)) return ['state'=>'invalid'];
        $generated=(int)($d['generated_epoch']??0);
        $source=(int)($d['source_epoch']??0);
        $now=time();
        $d['freshness']=($generated>0 && $generated<=$now+60
          && $now-$generated<=900 && $source>0 && $now-$source<=900)
            ? 'fresh' : 'stale';
        return $d;
    }
    public static function menu(): void {
        if (!function_exists('add_submenu_page')) return;
        add_submenu_page('mad4b-control-plane','Host Performance & Alerts',
            'Host Performance','manage_options',self::PAGE,[__CLASS__,'render']);
    }
    private static function metric($v,string $unit=''): string {
        if (!is_numeric($v)) return '—';
        return number_format_i18n((float)$v,1).$unit;
    }
    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Administrator access required.');
        $d=self::snapshot();
        echo '<div class="wrap"><h1>MAD4B Host Performance &amp; Alerts</h1>';
        echo '<p>Read-only host observations from the shared VPS. No WordPress, WP-CLI, or Broker operations run when this page opens. Visitor identities and cookies are not collected.</p>';
        if (!isset($d['host'])) {
            echo '<div class="notice notice-warning"><p>Host telemetry unavailable or contract invalid.</p></div></div>';
            return;
        }
        $fresh=$d['freshness']==='fresh';
        echo '<p><strong>Snapshot:</strong> '.esc_html(gmdate('Y-m-d H:i:s',(int)$d['source_epoch']).' UTC');
        echo ' &nbsp; <strong>Freshness:</strong> '.esc_html($fresh?'Fresh':'Stale — check VPS observer');
        echo ' &nbsp; <strong>Current alerts:</strong> '.esc_html((string)count((array)($d['active_alert_codes']??[]))).'</p>';
        $cpu=$d['host']['metrics'];
        echo '<h2>VPS CPU &amp; resource pressure</h2><table class="widefat striped" style="max-width:960px"><tbody>';
        foreach ([
           'Host CPU'=>self::metric($cpu['host_cpu_pct']??null,'%'),
           'Hypervisor steal'=>self::metric($cpu['cpu_steal_pct']??null,'%'),
           'CPU PSI 10 seconds'=>self::metric($cpu['cpu_psi_some_avg10']??null,'%'),
           'Load (1 minute)'=>self::metric($cpu['load_1m']??null),
           'Concurrent WP-CLI'=>self::metric($cpu['wpcli_processes']??null),
        ] as $label=>$value) {
            echo '<tr><th scope="row">'.esc_html($label).'</th><td>'.esc_html($value).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Mobile homepage HTML cache — observed TTFB</h2>';
        echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>Company site</th><th>HTTP</th><th>TTFB</th><th>Total</th></tr></thead><tbody>';
        foreach(array_slice($d['sites'],0,20,true) as $host=>$stats) {
            if(!is_string($host) || !is_array($stats) || !preg_match('/^[a-z0-9.-]{1,100}$/D',$host)) continue;
            echo '<tr><th scope="row">'.esc_html($host).'</th><td>'.
                esc_html((string)($stats['status']??'—')).'</td><td>'.
                esc_html(self::metric($stats['ttfb_ms']??null,' ms')).'</td><td>'.
                esc_html(self::metric($stats['total_ms']??null,' ms')).'</td></tr>';
        }
        echo '</tbody></table>';
        $ajax=$d['wayfatours']['visitor_ajax']??[];
        echo '<h2>Wayfatours — real visitor preference requests</h2>';
        echo '<p>Aggregated recent traffic, not artificial AJAX tests. A zero sample count means <strong>no measurement</strong>, not instant responses.</p>';
        echo '<table class="widefat striped" style="max-width:960px"><tbody>';
        foreach ([
           'Observed requests'=>(string)($ajax['count']??'—'),
           'Median (P50)'=>self::metric($ajax['p50_ms']??null,' ms'),
           'Tail (P95)'=>self::metric($ajax['p95_ms']??null,' ms'),
           'Slowest'=>self::metric($ajax['max_ms']??null,' ms'),
           'Over 5 seconds'=>(string)($ajax['over_5000ms']??'—'),
           'Non-200 responses'=>(string)($ajax['non200']??'—'),
        ] as $name=>$value)echo '<tr><th scope="row">'.esc_html($name).'</th><td>'.esc_html($value).'</td></tr>';
        echo '</tbody></table>';
        echo '<h2>Host alert history</h2>';
        echo '<p>Historical alerts stay visible after a healthy reading. These are local host observations, not signed Broker receipts or externally delivered notifications.</p>';
        echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>First seen (UTC)</th><th>Source</th><th>Alert</th><th>Severity</th><th>State</th></tr></thead><tbody>';
        $events=array_slice($d['events'],0,60);
        foreach($events as $e){
            if(!is_array($e))continue;
            echo '<tr><td>'.esc_html(gmdate('Y-m-d H:i:s',(int)($e['first_seen_epoch']??0))).'</td><td>'.
                esc_html((string)($e['site']??'')).
                '</td><td><code>'.esc_html((string)($e['kind']??'')).
                '</code></td><td>'.esc_html((string)($e['severity']??'')).
                '</td><td>'.esc_html((string)($e['state']??'')).'</td></tr>';
        }
        if(!$events)echo '<tr><td colspan="5">No events captured in the retained host history.</td></tr>';
        echo '</tbody></table>';
        echo '<p><em>Refresh this page for the latest 5-minute host snapshot. This screen is read-only.</em></p></div>';
    }
}
if (is_admin()) add_action('admin_menu',['MAD4B_Host_Performance_Readback','menu'],99);
