<?php
/** Company-local Distribution Console. Presentation only; registry remains authority. */
defined('ABSPATH') || exit;

final class MAD4B_Distribution_Admin_V1 {
    private const PAGE = 'mad4b-distribution-channels';

    public static function boot(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    public static function enqueue(string $hook): void {
        if ($hook !== 'tools_page_' . self::PAGE || !current_user_can('manage_options')) return;
        $root = dirname(__DIR__);
        $css = $root . '/assets/distribution-console.css';
        $js  = $root . '/assets/distribution-console.js';
        if (is_file($css)) {
            wp_enqueue_style('mad4b-distribution-console',
                plugins_url('assets/distribution-console.css', $root . '/mad4b-distribution-connectors.php'),
                [], (string)filemtime($css));
        }
        if (is_file($js)) {
            wp_enqueue_script('mad4b-distribution-console',
                plugins_url('assets/distribution-console.js', $root . '/mad4b-distribution-connectors.php'),
                [], (string)filemtime($js), true);
        }
    }

    private static function esc(string $value): string { return esc_html($value); }
    private static function category(string $key): string {
        $names = [
            'social_catalog'=>'Social & Commerce','tourism_marketplace'=>'Tours & Activities',
            'ota_marketplace'=>'OTA Marketplaces','advertising'=>'Advertising',
            'local_discovery'=>'Local Discovery','partner_distribution'=>'B2B & Partners',
            'other'=>'Other',
        ];
        return $names[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
    private static function display_status(array $row,array $health): array {
        if (($health['connection_verified']??false)===true)
            return ['Connected & verified','verified'];
        if (($row['status']??'')==='configuration_pending_verification')
            return ['Needs verification','attention'];
        if (!empty($row['account_alias']) || !empty($health['credential_saved_locally']))
            return ['Setup in progress','attention'];
        return ['Not connected','idle'];
    }
    private static function reason(string $code): string {
        $map = [
            'provider_enrollment_pending'=>'Provider enrollment not approved',
            'account_not_bound'=>'Partner account not linked',
            'company_local_credential_missing'=>'Local credential not saved',
            'site_local_encryption_unavailable'=>'Local encryption unavailable',
            'provider_credential_not_tested'=>'Credential not verified with provider',
            'provider_endpoint_unbound'=>'Provider endpoint not set',
            'channel_adapter_not_installed'=>'Provider integration not installed',
        ];
        if (isset($map[$code])) return $map[$code];
        if (str_starts_with($code,'linked_channel_not_connected:')) {
            $key=substr($code,strlen('linked_channel_not_connected:'));
            return 'Dependent channel not connected: '.str_replace('_',' ', $key);
        }
        return ucwords(str_replace('_',' ',sanitize_text_field($code)));
    }
    private static function icon(string $id,string $category): string {
        $byId=[
            'meta_catalog'=>'dashicons-screenoptions',
            'facebook_shops'=>'dashicons-store','instagram_shopping'=>'dashicons-format-image',
            'whatsapp_business_catalog'=>'dashicons-format-chat',
            'google_things_to_do'=>'dashicons-location',
            'google_ads'=>'dashicons-megaphone',
            'google_business_profile'=>'dashicons-admin-site-alt3',
            'viator'=>'dashicons-tickets-alt','getyourguide'=>'dashicons-palmtree',
            'klook'=>'dashicons-admin-site-alt2','partner_b2b'=>'dashicons-groups',
        ];
        $byCategory=['ota_marketplace'=>'dashicons-tickets-alt','tourism_marketplace'=>'dashicons-location',
            'partner_distribution'=>'dashicons-groups','advertising'=>'dashicons-megaphone',
            'social_catalog'=>'dashicons-screenoptions','local_discovery'=>'dashicons-location-alt'];
        return $byId[$id] ?? ($byCategory[$category] ?? 'dashicons-admin-links');
    }
    private static function url(string $id=''): string {
        $args=['page'=>self::PAGE];
        if($id!=='')$args['channel']=$id;
        return add_query_arg($args,admin_url('tools.php'));
    }
    private static function notice(string $code): string {
        $labels=[
            'saved'=>'Changes saved. Connection still requires provider verification.',
            'credential_string_required'=>'Enter a credential before saving.',
            'connection_no_fields_supplied'=>'No new connection details were entered.',
            'channel_field_not_configurable'=>'This field cannot be changed here.',
            'credential_requires_vault_reference'=>'Legacy external references are not required; use local credentials.',
            'local_encryption_unavailable'=>'Local encryption is unavailable. No credential was stored.',
            'local_credential_value_invalid'=>'The credential must be 8–8192 characters.',
            'invalid_channel_operation'=>'Invalid operation. Nothing was changed.',
            'invalid_linked_channel'=>'Invalid channel dependency.',
        ];
        return $labels[$code] ?? ('Operation: '.str_replace('_',' ',$code));
    }
    private static function csrf(string $operation,string $id): void {
        wp_nonce_field('mad4b_distribution_channel_config');
        echo '<input type="hidden" name="action" value="mad4b_distribution_channel_save">';
        echo '<input type="hidden" name="operation" value="'.esc_attr($operation).'">';
        echo '<input type="hidden" name="channel_id" value="'.esc_attr($id).'">';
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Access denied');
        $rows=MAD4B_Distribution_Channel_Registry_V1::public_status();
        $health=MAD4B_Distribution_Channel_Registry_V1::connection_health();
        $events=get_option(MAD4B_Distribution_Channel_Registry_V1::AUDIT,[]);
        $events=is_array($events)?array_slice($events,-6):[];
        $security=class_exists('MAD4B_Distribution_Local_Credentials_V1')
            ?MAD4B_Distribution_Local_Credentials_V1::status():[];
        $preferred=isset($_GET['channel'])&&is_string($_GET['channel'])
            ?sanitize_key(wp_unslash($_GET['channel'])):'';
        if (!isset($rows[$preferred])) $preferred=(string)(array_key_first($rows)??'');
        self::body($rows,$health,$security,$events,$preferred);
    }

    /** Pure read-only rendering, can be regression-tested without privileged WP CLI writes. */
    public static function body(array $rows,array $health,array $security,array $events,string $selected): void {
        $categories=[];$connected=0;$credentials=0;$requires=0;$sending=0;
        foreach($rows as $id=>$row) {
            $key=(string)($row['category']??'other');
            $categories[$key]=($categories[$key]??0)+1;
            if(($health[$id]['connection_verified']??false)===true)$connected++;
            if(($health[$id]['credential_saved_locally']??false)===true)$credentials++;
            if(!empty($health[$id]['missing']))$requires++;
            if(($row['external_delivery_enabled']??false)===true)$sending++;
        }
        $current=$rows[$selected]??null;
        $currentHealth=$health[$selected]??[];
        $name=$current['label']??'';
        $catalogUrl=admin_url('options-general.php?page=mcfm_feeds_v2');
        ?>
        <div class="wrap mad4b-dc" id="mad4b-distribution-console">
          <div class="mad4b-dc__topline"><span class="mad4b-dc__eyebrow">MAD4B / COMPANY OPERATIONS</span><span class="mad4b-dc__company"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><?php echo self::esc((string)wp_parse_url(home_url('/'),PHP_URL_HOST)); ?></span></div>
          <header class="mad4b-dc__header">
            <div><h1>Distribution Control Center</h1><p>Manage your catalogs, marketplace connections and partner readiness from one company workspace.</p></div>
            <div class="mad4b-dc__header-actions"><a href="<?php echo esc_url($catalogUrl); ?>" class="button mad4b-dc__action-secondary"><span class="dashicons dashicons-list-view" aria-hidden="true"></span>Catalog Feed Studio</a><a href="#mad4b-dc-add-partner" class="button mad4b-dc__action-primary"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>Add channel</a></div>
          </header>
          <?php if(isset($_GET['channel_notice'])&&is_string($_GET['channel_notice'])): $notice=sanitize_key(wp_unslash($_GET['channel_notice'])); ?>
          <div class="mad4b-dc__notice" role="status"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php echo self::esc(self::notice($notice)); ?></div>
          <?php endif; ?>
          <section class="mad4b-dc__metrics" aria-label="Distribution overview">
            <div class="mad4b-dc__metric"><span>Registered channels</span><strong><?php echo count($rows); ?></strong><small>Across all marketplace groups</small></div>
            <div class="mad4b-dc__metric"><span>Connected &amp; verified</span><strong><?php echo $connected; ?></strong><small>Provider connection confirmed</small></div>
            <div class="mad4b-dc__metric"><span>Require setup</span><strong><?php echo $requires; ?></strong><small>Open onboarding requirements</small></div>
            <div class="mad4b-dc__metric"><span>Local credentials</span><strong><?php echo $credentials; ?></strong><small>Encrypted for this company</small></div>
          </section>
          <div class="mad4b-dc__security" role="status">
            <span class="mad4b-dc__security-icon dashicons dashicons-shield" aria-hidden="true"></span>
            <div><strong>Company-local credential security</strong><span><?php echo !empty($security['encryption_available'])?'Encrypted storage available':'Encrypted storage unavailable'; ?> · No external vault required · Credentials never copied between companies</span></div>
            <span class="mad4b-dc__security-state <?php echo !empty($security['encryption_available'])?'is-secure':'is-pending'; ?>"><?php echo !empty($security['encryption_available'])?'LOCAL ENCRYPTION READY':'ACTION REQUIRED'; ?></span>
          </div>
          <div class="mad4b-dc__workspace">
            <aside class="mad4b-dc__sidebar" aria-label="Channel categories">
              <div class="mad4b-dc__sidebar-heading">WORKSPACE</div>
              <nav class="mad4b-dc__category-nav" aria-label="Filter channel categories">
                <button type="button" class="mad4b-dc__category is-active" data-filter="all" aria-pressed="true"><span class="dashicons dashicons-grid-view" aria-hidden="true"></span>All channels <b><?php echo count($rows); ?></b></button>
                <?php foreach($categories as $slug=>$n): ?><button type="button" class="mad4b-dc__category" data-filter="<?php echo esc_attr($slug); ?>" aria-pressed="false"><span class="dashicons <?php echo esc_attr(self::icon('',$slug)); ?>" aria-hidden="true"></span><?php echo self::esc(self::category($slug)); ?><b><?php echo (int)$n; ?></b></button><?php endforeach; ?>
              </nav>
              <div class="mad4b-dc__sidebar-hint"><span class="dashicons dashicons-info" aria-hidden="true"></span><p>Channels can share catalog data while keeping their own accounts, policies and verification.</p></div>
            </aside>
            <main class="mad4b-dc__main">
              <div class="mad4b-dc__section-head"><div><span class="mad4b-dc__section-kicker">CHANNEL DIRECTORY</span><h2>Marketplaces &amp; connections</h2><p>Choose a channel to manage its account and distribution requirements.</p></div><label class="mad4b-dc__search"><span class="dashicons dashicons-search" aria-hidden="true"></span><span class="screen-reader-text">Search channels</span><input type="search" id="mad4b-dc-search" placeholder="Search channels..." autocomplete="off"></label></div>
              <div class="mad4b-dc__directory" id="mad4b-dc-channel-list">
                <?php foreach($rows as $id=>$row): $h=$health[$id]??[];$category=(string)($row['category']??'other');[$statusLabel,$statusKind]=self::display_status($row,$h);$missing=(array)($h['missing']??[]); ?>
                <article class="mad4b-dc__card <?php echo $selected===$id?'is-selected':''; ?>" data-category="<?php echo esc_attr($category); ?>" data-search="<?php echo esc_attr(strtolower((string)($row['label']??'').' '.$id.' '.self::category($category))); ?>">
                  <div class="mad4b-dc__card-top"><span class="mad4b-dc__channel-icon is-<?php echo esc_attr($category); ?>"><span class="dashicons <?php echo esc_attr(self::icon($id,$category)); ?>" aria-hidden="true"></span></span><span class="mad4b-dc__status is-<?php echo esc_attr($statusKind); ?>"><span class="mad4b-dc__status-dot"></span><?php echo self::esc($statusLabel); ?></span></div>
                  <h3><a href="<?php echo esc_url(self::url($id).'#mad4b-dc-channel-settings'); ?>"><?php echo self::esc((string)($row['label']??$id)); ?></a></h3><p class="mad4b-dc__card-meta"><?php echo self::esc(self::category($category)); ?> <span>·</span> <?php echo self::esc(strtoupper(str_replace('_',' ',(string)($row['transport']??'—')))); ?></p>
                  <div class="mad4b-dc__card-bottom"><span><?php echo !empty($h['credential_saved_locally'])?'Encrypted credential saved':count($missing).' setup requirements'; ?></span><a href="<?php echo esc_url(self::url($id).'#mad4b-dc-channel-settings'); ?>" aria-label="Configure <?php echo esc_attr((string)($row['label']??$id)); ?>">Configure <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a></div>
                </article>
                <?php endforeach; ?>
              </div>
              <p class="mad4b-dc__empty" id="mad4b-dc-empty" hidden>No matching channels. Try a different search or category.</p>
              <p class="mad4b-dc__results" id="mad4b-dc-results" aria-live="polite"><?php echo count($rows); ?> channels shown</p>
            </main>
            <?php if($current): [$statusLabel,$statusKind]=self::display_status($current,$currentHealth); ?>
            <aside class="mad4b-dc__inspector" id="mad4b-dc-channel-settings" aria-label="Selected channel settings">
              <div class="mad4b-dc__inspector-header"><span>CHANNEL SETTINGS</span><span class="mad4b-dc__inspector-status is-<?php echo esc_attr($statusKind); ?>"><?php echo self::esc($statusLabel); ?></span></div>
              <div class="mad4b-dc__inspector-identity"><span class="mad4b-dc__channel-icon is-<?php echo esc_attr((string)($current['category']??'other')); ?>"><span class="dashicons <?php echo esc_attr(self::icon($selected,(string)($current['category']??'other'))); ?>" aria-hidden="true"></span></span><div><h2><?php echo self::esc((string)$name); ?></h2><p><?php echo self::esc(self::category((string)$current['category'])); ?> · <?php echo self::esc(strtoupper((string)$current['transport'])); ?></p></div></div>
              <div class="mad4b-dc__inspector-body">
                <h3>Connection readiness</h3>
                <?php $missing=(array)($currentHealth['missing']??[]); if(!$missing): ?><div class="mad4b-dc__check-ok">No open onboarding checks recorded.</div><?php else: ?><ul class="mad4b-dc__checklist"><?php foreach(array_slice($missing,0,6) as $problem): ?><li><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php echo self::esc(self::reason((string)$problem)); ?></li><?php endforeach; ?><?php if(count($missing)>6): ?><li><?php echo count($missing)-6; ?> additional requirements</li><?php endif; ?></ul><?php endif; ?>
                <div class="mad4b-dc__inspector-rule"></div>
                <h3>Account details</h3><p class="mad4b-dc__helper">Account name and endpoint are company-specific. Saving a draft does not enable publishing.</p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="mad4b-dc__form">
                  <?php self::csrf('configure',$selected); ?>
                  <label for="mad4b-dc-alias">Account alias <input id="mad4b-dc-alias" type="text" name="account_alias" maxlength="96" placeholder="e.g. Company Partner Account" value="<?php echo esc_attr((string)($current['account_alias']??'')); ?>"></label>
                  <label for="mad4b-dc-endpoint">Public API/SFTP hostname <input id="mad4b-dc-endpoint" type="text" name="endpoint_host" placeholder="partner.example.com" inputmode="url" value="<?php echo esc_attr((string)($current['endpoint_host']??'')); ?>"></label>
                  <p class="mad4b-dc__field-tip">Leave the endpoint blank to keep the saved value. Never paste tokens here.</p>
                  <button class="button mad4b-dc__form-primary" type="submit">Save connection draft</button>
                </form>
                <div class="mad4b-dc__inspector-rule"></div>
                <h3>Local credentials <span class="mad4b-dc__mini-label">ENCRYPTED</span></h3>
                <p class="mad4b-dc__helper"><?php echo !empty($currentHealth['credential_saved_locally'])?'A local credential is saved. Enter a new value to rotate it.':'No local credential saved for this channel.'; ?> Values are never displayed after saving.</p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" autocomplete="off" class="mad4b-dc__form">
                  <?php self::csrf('secret_save',$selected); ?>
                  <label for="mad4b-dc-secret">API token / secret <input id="mad4b-dc-secret" type="password" name="credential_value" minlength="8" maxlength="8192" required autocomplete="new-password" placeholder="Enter secure credential"></label>
                  <button class="button mad4b-dc__form-primary" type="submit"><?php echo !empty($currentHealth['credential_saved_locally'])?'Rotate encrypted credential':'Save encrypted credential'; ?></button>
                </form>
                <?php if(!empty($currentHealth['credential_saved_locally'])): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mad4b-dc__revoke-form" data-confirm="Revoke the credential for <?php echo esc_attr((string)$name); ?>? This will invalidate its connection verification."><?php self::csrf('secret_delete',$selected); ?><button type="submit" class="button mad4b-dc__revoke">Revoke saved credential</button></form><?php endif; ?>
                <div class="mad4b-dc__inspector-rule"></div>
                <details class="mad4b-dc__dependencies"><summary>Linked channels <span><?php echo count((array)($current['linked_channels']??[])); ?></span></summary><p class="mad4b-dc__helper">Choose channels whose verified connection is required before this one can be ready.</p>
                  <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="mad4b-dc__form">
                  <?php self::csrf('link',$selected); ?>
                  <div class="mad4b-dc__linked-options"><?php foreach($rows as $otherId=>$other): if($otherId===$selected)continue; ?><label><input type="checkbox" name="linked_channels[]" value="<?php echo esc_attr($otherId); ?>" <?php checked(in_array($otherId,(array)($current['linked_channels']??[]),true)); ?>><?php echo self::esc((string)($other['label']??$otherId)); ?></label><?php endforeach; ?></div>
                  <button class="button mad4b-dc__form-secondary" type="submit">Save dependencies</button>
                  </form>
                </details>
                <?php if(class_exists('MAD4B_Distribution_Activation_V1')):
                    $activation=MAD4B_Distribution_Activation_V1::readiness($selected); ?>
                <div class="mad4b-dc__inspector-rule"></div>
                <section class="mad4b-dc__activation" aria-label="External channel activation">
                  <h3>External distribution</h3>
                  <p class="mad4b-dc__helper">Activation requires a real approved provider account, a verified connection receipt and a working delivery adapter. No simulated provider approval.</p>
                  <strong class="mad4b-dc__activation-state"><?php echo self::esc(str_replace('_',' ',(string)$activation['state'])); ?></strong>
                  <?php if(!empty($activation['missing'])): ?>
                    <ul class="mad4b-dc__checklist">
                      <?php foreach($activation['missing'] as $reason): ?><li><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php echo self::esc(self::reason((string)$reason)); ?></li><?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                  <div class="mad4b-dc__activation-actions">
                    <?php foreach([
                        'request'=>'Request activation',
                        'verify'=>'Verify provider connection',
                        'activate'=>'Activate verified delivery',
                        'disable'=>'Disable delivery',
                    ] as $action=>$label): ?>
                      <?php $blocked=($action==='activate' && (empty($activation['eligible'])||empty($activation['requested'])))
                          || ($action==='request' && !empty($activation['requested'])); ?>
                      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('mad4b_distribution_channel_activation'); ?>
                        <input type="hidden" name="action" value="mad4b_distribution_channel_activation">
                        <input type="hidden" name="channel_id" value="<?php echo esc_attr($selected); ?>">
                        <input type="hidden" name="activation_action" value="<?php echo esc_attr($action); ?>">
                        <button type="submit" class="button" <?php disabled($blocked); ?>><?php echo self::esc($label); ?></button>
                      </form>
                    <?php endforeach; ?>
                  </div>
                </section>
                <?php endif; ?>
                <div class="mad4b-dc__delivery-note"><span class="dashicons dashicons-lock" aria-hidden="true"></span><div><strong>External delivery is <?php echo !empty($current['external_delivery_enabled'])?'enabled':'off'; ?></strong><p>Provider validation and an approved transport are required before distribution.</p></div></div>
              </div>
            </aside>
            <?php endif; ?>
          </div>
          <section class="mad4b-dc__lower" aria-label="More distribution tools">
            <div class="mad4b-dc__lower-card" id="mad4b-dc-add-partner">
              <div class="mad4b-dc__lower-title"><span class="dashicons dashicons-plus-alt" aria-hidden="true"></span><div><h2>Add a marketplace or partner</h2><p>Register a new company-local channel without inheriting another company's account or credentials.</p></div></div>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mad4b-dc__create-form">
                <?php wp_nonce_field('mad4b_distribution_channel_config'); ?><input type="hidden" name="action" value="mad4b_distribution_channel_save"><input type="hidden" name="operation" value="create">
                <label>Channel ID <input name="channel_id" placeholder="custom_partner_name" pattern="custom_[a-z0-9_]{3,55}" required></label>
                <label>Display name <input name="label" placeholder="Partner or marketplace name" maxlength="90" required></label>
                <label>Category <select name="category"><option value="ota_marketplace">OTA Marketplace</option><option value="partner_distribution">Partner / B2B</option><option value="social_catalog">Social Catalog</option><option value="advertising">Advertising</option><option value="other">Other</option></select></label>
                <label>Transport <select name="transport"><option value="partner_api">Partner API</option><option value="api">REST API</option><option value="sftp">SFTP</option><option value="feed">Feed</option><option value="webhook">Webhook</option><option value="manual">Manual</option></select></label>
                <button class="button mad4b-dc__form-primary" type="submit">Add disconnected channel</button>
              </form>
            </div>
            <div class="mad4b-dc__lower-card mad4b-dc__activity"><div class="mad4b-dc__lower-title"><span class="dashicons dashicons-backup" aria-hidden="true"></span><div><h2>Recent configuration activity</h2><p>Audit records show metadata and operation names. Secrets are never logged.</p></div></div>
              <?php if(!$events): ?><div class="mad4b-dc__activity-empty">No connection configuration changes recorded yet.</div><?php else: ?><ol class="mad4b-dc__activity-list"><?php foreach(array_reverse($events) as $event): if(!is_array($event))continue; ?><li><span class="mad4b-dc__activity-dot"></span><div><strong><?php echo self::esc(ucwords(str_replace('_',' ',(string)($event['event']??'Configuration updated')))); ?></strong><small><?php echo self::esc((string)($event['channel']??'')); ?> · <?php echo self::esc((string)($event['time']??'')); ?> UTC</small></div></li><?php endforeach; ?></ol><?php endif; ?>
              <div class="mad4b-dc__delivery-overview"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><?php echo (int)$sending; ?> active external deliveries reported across registered channels</div>
            </div>
          </section>
          <footer class="mad4b-dc__footer">MAD4B Distribution Suite <span>·</span> Company-local configuration <span>·</span> No external vault <span>·</span> Private credentials</footer>
        </div>
        <?php
    }
}
MAD4B_Distribution_Admin_V1::boot();
