<?php
/**
 * Plugin Name: MAD4B VPS Broker Operator Canary (Staging)
 * Description: Authenticated operator-initiated, non-mutating Dispatch/Claim canary using the existing broker pipeline.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_VPS_Broker_Operator_Canary_V1 {
    const PAGE = 'mad4b-broker-canary';
    public static function allowed(): bool {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::allowed()
            || !class_exists('MAD4B_Central_VPS_Broker_V1', false)
            || !class_exists('MAD4B_Central_SSH_Command_Intake_V1', false)) return false;
        if (defined('WP_CLI') && WP_CLI) return false;
        if (!defined('LOGGED_IN_COOKIE') || empty($_COOKIE[LOGGED_IN_COOKIE])) return false;
        return (int)wp_validate_auth_cookie((string)$_COOKIE[LOGGED_IN_COOKIE], 'logged_in')
            === get_current_user_id();
    }
    public static function menu(): void {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::central()) return;
        add_management_page(
            'MAD4B VPS Broker Canary', 'MAD4B VPS Broker Canary',
            'manage_options', self::PAGE, array(__CLASS__, 'render')
        );
    }
    public static function render(): void {
        if (!self::allowed()) wp_die('Enrolled Administrator browser session required', 403);
        $site = 'wayfatours';
        $host = (string)get_option(MAD4B_Central_VPS_Broker_V1::HOST_ID, '');
        $heartbeat = get_option(MAD4B_Central_VPS_Broker_V1::HEARTBEAT, array());
        $fresh = is_array($heartbeat) && !empty($heartbeat['verified'])
            && (int)($heartbeat['at']??0)>time()-600;
        if ($host !== 'srv2030908') wp_die('Exact registered VPS host is required', 409);
        $base = untrailingslashit(rest_url('mad4b/v1/central-execution-lanes/'.$site));
        $nonce = wp_create_nonce('wp_rest');
        $opts = wp_json_encode(array(
            'base'=>$base,'nonce'=>$nonce,'host'=>$host,'company'=>$site,
            'canary_command'=>'printf MAD4B_BROKER_DIAGNOSTIC',
            'allow_dispatch'=>$fresh,
        ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo '<div class="wrap"><h1>MAD4B — VPS Broker Diagnostic</h1>';
        echo '<p>Uses the existing two-lane broker. This test queues a fixed, non-mutating shell diagnostic. The VPS broker claims requests but does not execute queued shell bytes.</p>';
        echo '<p>Registered host: <strong>'.esc_html($host).'</strong>. Signed heartbeat: <strong>'.
            esc_html($fresh?'Verified (fresh)':'Unavailable').'</strong>.</p>';
        echo '<p>Company: <strong>Wayfatours</strong> · environment: <strong>Production</strong> · company mutation: <strong>none</strong>.</p>';
        echo '<p><button id="mad4b-broker-diagnostic" class="button button-primary" '.($fresh?'':'disabled').'>Dispatch signed broker diagnostic</button></p>';
        echo '<pre id="mad4b-broker-canary-output" style="white-space:pre-wrap;max-width:780px"></pre>';
        echo '<p>The request is accepted only in your authenticated browser with the enrolled Administrator account. A VPS host grant and independent execution authority are not created by this canary.</p>';
        echo '</div>';
        ?>
<script>
(() => {
  const opt = <?php echo $opts; ?>;
  const button = document.getElementById('mad4b-broker-diagnostic');
  const output = document.getElementById('mad4b-broker-canary-output');
  if (!button || !opt.allow_dispatch) return;
  const post = async (suffix, payload) => {
    const response = await fetch(opt.base + suffix, {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type':'application/json','X-WP-Nonce':opt.nonce},
      body:JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok) throw new Error((data.code||'HTTP_'+response.status)+': '+(data.message||'Request failed'));
    return data;
  };
  button.addEventListener('click', async () => {
    button.disabled = true;
    output.textContent = 'Issuing one-time company operation grant...';
    try {
      const grant = await post('/grants', {
        operation:'maintenance.shell',host_id:opt.host,host_principal:'root',
        grant_mode:'one_time',ttl_seconds:900,
        reason:'Owner-authorized non-mutating VPS Broker dispatch claim diagnostic'
      });
      const correlation = 'mad4b-broker-canary-'+crypto.randomUUID();
      output.textContent = 'Grant '+grant.grant_id+' issued. Preparing diagnostic command...';
      const request = await post('/commands', {
        grant_id:grant.grant_id,command:opt.canary_command,
        summary:'Non-mutating signed VPS Broker diagnostic; no command execution',
        correlation_id:correlation,target_scope:'wordpress'
      });
      const job = await post('/broker-dispatch',{request_id:request.request_id});
      output.textContent = JSON.stringify({
        grant_mode:grant.grant_mode, job_id:job.job_id,
        state:job.state, correlation_id:correlation,
        command_sha256:job.command_sha256,
        execution_performed:false,
        next:'Existing mad4b-dispatch-v1.timer will claim after independently verified broker admission.'
      }, null, 2);
    } catch (err) {
      output.textContent = 'Diagnostic dispatch failed: '+String(err.message||err);
    } finally { button.disabled = false; }
  });
})();
</script>
<?php
    }
    public static function boot():void {
        add_action('admin_menu',array(__CLASS__,'menu'),75);
    }
}
MAD4B_VPS_Broker_Operator_Canary_V1::boot();
