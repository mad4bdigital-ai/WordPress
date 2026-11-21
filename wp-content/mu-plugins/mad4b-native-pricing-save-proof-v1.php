<?php
/**
 * Plugin Name: MAD4B Native JetEngine Pricing Save Proof
 * Version: 1.0.0
 * Description: Bounded, private, metadata-only proof of real native JetEngine saves and verified stale rejections.
 */
defined('ABSPATH') || exit;

final class MAD4B_Native_Pricing_Save_Proof_V1 {
    public const OPTION = 'mad4b_native_pricing_save_proof_v1';
    private const LIMIT = 12;
    private const PAGE = 'etg-dynamic-pricing-controls';

    private static function authorized(): bool {
        if (!class_exists('MAD4B_JetEngine_Native_Pricing_CAS_V1')) return false;
        if (!current_user_can('commerce_manage_pricing') ||
            !current_user_can('manage_woocommerce')) return false;
        return true;
    }

    /** Strictly compare original row arrays; a failure is NOT a successful Save. */
    public static function exact_repeater_readback($submitted,$stored): bool {
        return is_array($submitted) && is_array($stored) &&
            hash_equals(hash('sha256',serialize($submitted)),
                        hash('sha256',serialize($stored)));
    }

    /** Read after the one vendor Save, directly from DB (not object cache). */
    private static function repeater_readback($page): array {
        global $wpdb;
        $checked=0;$exact=0;
        foreach ((array)$page->get_prepared_fields() as $key=>$field) {
            if (($field['type']??'')!=='repeater' ||
                !array_key_exists($key,$_REQUEST)) continue;
            $checked++;
            if ($checked>24)break;
            $option=(string)$page->get_separate_option_name($key);
            if ($option==='' || strlen($option)>191)continue;
            $raw=$wpdb->get_var($wpdb->prepare(
              "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
              $option));
            if (self::exact_repeater_readback($_REQUEST[$key],maybe_unserialize($raw))) {
                $exact++;
            }
        }
        return ['checked'=>min($checked,24),'exact'=>$exact];
    }

    /** The caller cannot provide pricing values or arbitrary log fields. */
    public static function entry(string $status, string $submitted, string $current, array $repeater=[]): array {
        if (!in_array($status,['native_source_changed','native_source_unchanged','stale_rejected','stale_probe_rejected'],true)) {
            return [];
        }
        if (!preg_match('/^[a-f0-9]{64}$/D',$submitted) ||
            !preg_match('/^[a-f0-9]{64}$/D',$current)) return [];
        return [
            'status'=>$status,
            'timestamp'=>time(),
            'submitted'=>substr($submitted,0,12),
            'current'=>substr($current,0,12),
            'original_jetengine_save'=>in_array($status,['native_source_changed','native_source_unchanged'],true),
            'source_changed'=>$status==='native_source_changed',
            // Counts only, never the submitted/saved row values.
            'repeater_checked'=>in_array($status,['native_source_changed','native_source_unchanged'],true)
                ?max(0,min(24,(int)($repeater['checked']??0))):0,
            'repeater_exact'=>in_array($status,['native_source_changed','native_source_unchanged'],true)
                ?max(0,min(
                  max(0,min(24,(int)($repeater['checked']??0))),
                  (int)($repeater['exact']??0))):0,
        ];
    }

    /** Restricted to one small metadata-only option, not a pricing field. */
    private static function append(array $entry): void {
        if (!$entry || !self::authorized())return;
        $history=get_option(self::OPTION,[]);
        if (!is_array($history))$history=[];
        $history=array_slice(array_values(array_filter($history,'is_array')),-(self::LIMIT-1));
        $history[]=$entry;
        update_option(self::OPTION,$history,false);
    }

    /**
     * Fired from vendor JetEngine save() after its original update_options()
     * and before CAS unlock. No parallel writer, no custom save operation.
     */
    public static function native_saved($page): void {
        // Tests using WP-CLI are not real browser submissions: never create
        // a success receipt for simulated writer hooks or fixtures.
        if (defined('WP_CLI') && WP_CLI) return;
        if (!self::authorized() ||
            !($page instanceof Jet_Engine_Options_Page_Factory) ||
            $page->slug!==self::PAGE ||
            ($_SERVER['REQUEST_METHOD']??'')!=='POST' ||
            (string)($_REQUEST['action']??'')!=='jet-engine-op-save-settings' ||
            (string)($_GET['page']??'')!==self::PAGE) return;
        $cls='MAD4B_JetEngine_Native_Pricing_CAS_V1';
        if (!$cls::save_lock_held())return;
        $raw=$_POST['mad4b_native_cas_revision']??'';
        $nonce=$_POST['mad4b_native_cas_nonce']??'';
        if (!is_string($raw) || !is_string($nonce) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)),$cls::NONCE_ACTION)) return;
        $submitted=sanitize_text_field(wp_unslash($raw));
        $after=$cls::source_revision();
        if (is_wp_error($after))return;
        // A vendor hook proves control reached Save; ONLY a changed raw DB
        // source revision proves an option mutation. Never claim otherwise.
        self::append(self::entry(
            hash_equals($submitted,$after) ? 'native_source_unchanged' : 'native_source_changed',
            $submitted,$after,self::repeater_readback($page)
        ));
    }

    /** Only record genuine nonce-verified stale denials, not anonymous scans. */
    public static function native_denied($error): void {
        if (defined('WP_CLI') && WP_CLI) return;
        if (!self::authorized() || !is_wp_error($error) ||
            $error->get_error_code()!=='native_pricing_cas_stale_source')return;
        $cls='MAD4B_JetEngine_Native_Pricing_CAS_V1';
        $raw=$_POST['mad4b_native_cas_revision']??'';
        $nonce=$_POST['mad4b_native_cas_nonce']??'';
        if (!is_string($raw) || !is_string($nonce) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)),$cls::NONCE_ACTION))return;
        $current=$cls::source_revision();
        if (is_wp_error($current))return;
        $probe=($_POST['mad4b_native_cas_probe']??'')==='1';
        self::append(self::entry($probe?'stale_probe_rejected':'stale_rejected',
            sanitize_text_field(wp_unslash($raw)),$current));
    }

    /** The test uses the logged-in operator's existing session; no new login. */
    public static function admin_assets(): void {
        if (!is_admin() || !self::authorized() ||
            !in_array((string)($_GET['page']??''),[self::PAGE,'dynamic-pricing-controls'],true))return;
        $file=WPMU_PLUGIN_DIR.'/assets/mad4b-native-pricing-stale-probe-v1.js';
        if (!is_readable($file))return;
        wp_enqueue_script('mad4b-native-pricing-stale-probe-v1',
            content_url('mu-plugins/assets/mad4b-native-pricing-stale-probe-v1.js'),
            ['mad4b-jetengine-native-pricing-cas-v1'],
            (string)filemtime($file),true);
    }

    /** Read-only, privileged evidence beside the original JetEngine editor. */
    public static function admin_notice(): void {
        if (!is_admin() || !self::authorized() ||
            !in_array((string)($_GET['page']??''),
              [self::PAGE,'dynamic-pricing-controls'],true))return;
        $history=get_option(self::OPTION,[]);
        if (!is_array($history))$history=[];
        $history=array_slice(array_reverse($history),0,5);
        echo '<div class="notice notice-info mad4b-native-save-proof" role="region" aria-label="Pricing Save verification">';
        echo '<p><strong>Native JetEngine Save evidence</strong> — shows the original protected Save and stale-revision rejects. No pricing values are recorded.</p>';
        echo '<p><button type="button" class="button" id="mad4b-native-stale-probe" data-save-url="'.
            esc_url(admin_url('admin.php?page='.self::PAGE.'&action=jet-engine-op-save-settings')).
            '">Test stale-save rejection (no pricing changes)</button> ';
        echo '<span id="mad4b-native-stale-probe-result" role="status" aria-live="polite"></span></p>';
        echo '<details><summary>Review last '.count($history).' event(s)</summary>';
        if (!$history) echo '<p>No verified native Save or stale conflict has been observed yet. Read-only preflight tests are not counted as saved changes.</p>';
        else {
            echo '<table class="widefat striped"><caption class="screen-reader-text">Original JetEngine Pricing Save verification</caption>';
            echo '<thead><tr><th>Recorded</th><th>Result</th><th>Revision</th><th>Repeater readback</th></tr></thead><tbody>';
            foreach($history as $event) {
                if (!is_array($event)) continue;
                $status=(string)($event['status']??'unknown');
                $names=[
                    'native_source_changed'=>'Original Save completed; source changed',
                    'native_source_unchanged'=>'Original Save handler ran; no source change',
                    'stale_rejected'=>'Real stale Save rejected; no pricing change',
                    'stale_probe_rejected'=>'Browser self-test: stale Save blocked (HTTP 409)',
                ];
                $time=absint($event['timestamp']??0);
                echo '<tr><td>'.esc_html($time?wp_date('Y-m-d H:i:s',$time).' '.wp_timezone_string():'Unknown').'</td>';
                echo '<td>'.esc_html($names[$status]??'Unknown').'</td>';
                echo '<td><code>'.esc_html((string)($event['submitted']??'')).'</code> → <code>'.esc_html((string)($event['current']??'')).'</code></td>';
                $checked=$event['repeater_checked']??null;
                $exact=$event['repeater_exact']??null;
                $out=is_numeric($checked)&&$checked>0
                    ?((int)$exact.'/'.(int)$checked.' exact')
                    :'Not measured';
                echo '<td>'.esc_html($out).'</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</details></div>';
    }
}
add_action('jet-engine/options-pages/after-save/etg-dynamic-pricing-controls',
    [MAD4B_Native_Pricing_Save_Proof_V1::class,'native_saved'],998);
add_action('mad4b/pricing/native-save-denied',
    [MAD4B_Native_Pricing_Save_Proof_V1::class,'native_denied'],10);
add_action('admin_notices',
    [MAD4B_Native_Pricing_Save_Proof_V1::class,'admin_notice'],40);
add_action('admin_enqueue_scripts',
    [MAD4B_Native_Pricing_Save_Proof_V1::class,'admin_assets'],71);
