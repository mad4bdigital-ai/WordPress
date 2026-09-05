<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\SEO\PublicationRegistry;

final class PublicationPage {
	private $config;
	private $profiles;
	private $publication;

	public function __construct( Configuration $config, ProfileRegistry $profiles, PublicationRegistry $publication ) {
		$this->config=$config;$this->profiles=$profiles;$this->publication=$publication;
	}

	public function register(): void {
		add_action('admin_menu',array($this,'menu'));
		add_action('admin_post_etg_dfsb_export_publication_preview',array($this,'export'));
	}

	public function menu(): void {
		add_options_page('ETG SEO Publication','ETG SEO Publication','manage_options','etg-filter-seo-publication',array($this,'render'));
	}

	public function render(): void {
		if(!current_user_can('manage_options')){return;}
		$tabs=array('overview'=>'Overview','candidates'=>'Candidates','elementor'=>'Elementor Content','sitemap'=>'Sitemap & Discovery');
		$tab=isset($_GET['tab'])?sanitize_key((string)wp_unslash($_GET['tab'])):'overview';if(!isset($tabs[$tab])){$tab='overview';}
		$summary=null;
		if('candidates'===$tab&&isset($_GET['etg_build_publication_preview'])){$summary=$this->publication->publicationSummary(100);}
		?>
		<div class="wrap etg-publication-admin">
		<h1>ETG SEO Publication</h1>
		<style>
		.etg-publication-admin .nav-tab-wrapper{margin-bottom:20px}.etg-pub-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;max-width:1100px}.etg-pub-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;margin:0 0 16px;max-width:1100px}.etg-pub-badge{display:inline-block;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700}.etg-pub-safe{background:#d7f3df;color:#0b5d24}.etg-pub-danger{background:#fbeaea;color:#8a1515}.etg-pub-readonly{background:#e8f0fe;color:#174ea6}.etg-pub-tip{display:inline-flex;width:18px;height:18px;align-items:center;justify-content:center;border-radius:50%;background:#2271b1;color:#fff;font-weight:700;font-size:12px;cursor:help;margin-left:5px}.etg-pub-table{border-collapse:collapse;width:100%;max-width:1400px;background:#fff}.etg-pub-table th,.etg-pub-table td{border:1px solid #dcdcde;padding:8px;vertical-align:top;text-align:left}.etg-pub-code{background:#f6f7f7;padding:12px;overflow:auto;max-width:1100px}.etg-pub-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
		</style>
		<div class="etg-pub-grid">
			<div class="etg-pub-card"><strong>Global bridge</strong><br/><span class="etg-pub-badge <?php echo $this->config->enabled()?'etg-pub-danger':'etg-pub-safe'; ?>"><?php echo $this->config->enabled()?'ON':'OFF'; ?></span><?php $this->tip('Rank Math metadata, index authority and live sitemap publication remain disabled while Global bridge is OFF. Optional Elementor dark presentation is controlled separately per profile.'); ?></div>
			<div class="etg-pub-card"><strong>Profiles</strong><br/><?php echo esc_html((string)count($this->profiles->all())); ?><?php $this->tip('Only enabled profiles with exact, profile-bound approved combinations can become publication candidates.'); ?></div>
			<div class="etg-pub-card"><strong>Publication model</strong><br/><span class="etg-pub-badge etg-pub-readonly">READ-ONLY PREVIEW</span><?php $this->tip('Preview can resolve Terms, metadata, background result counts and exclusion reasons without opening the Global kill switch.'); ?></div>
		</div>
		<nav class="nav-tab-wrapper" aria-label="ETG SEO Publication sections">
		<?php foreach($tabs as $slug=>$label):$url=add_query_arg(array('page'=>'etg-filter-seo-publication','tab'=>$slug),admin_url('options-general.php'));?><a class="nav-tab <?php echo $tab===$slug?'nav-tab-active':'';?>" href="<?php echo esc_url($url);?>"><?php echo esc_html($label);?></a><?php endforeach;?>
		</nav>
		<?php
		if('candidates'===$tab){$this->renderCandidates($summary);}elseif('elementor'===$tab){$this->renderElementor();}elseif('sitemap'===$tab){$this->renderSitemap();}else{$this->renderOverview();}
		?>
		</div><?php
	}

	private function renderOverview(): void {?>
		<div class="etg-pub-card"><h2>Publication contract</h2><p>Dynamic filter URLs are publishable only after the exact profile, route, taxonomy shape, approved combination, translated Terms, authoritative result count, minimum-result threshold, content readiness and Elementor content verification all pass.</p><p>The same resolved Term context feeds the visible Elementor content, Rank Math title/description/canonical/robots, OpenGraph/Twitter metadata, CollectionPage Schema and hreflang URLs.</p></div>
		<div class="etg-pub-card"><h2>Safe sequence</h2><ol><li>Add or import Term SEO/content fields.</li><li>Set <code>elementor_render_when_global_off=true</code> only while you need safe visual dark validation.</li><li>Build the archive presentation in Elementor Theme Builder with ETG shortcodes.</li><li>Keep <code>elementor_content_verified=false</code> until representative real filtered URLs visibly contain the intended Term sections.</li><li>Approve exact profile/language combinations.</li><li>Run Candidate Preview while Global bridge is OFF.</li><li>Resolve every exclusion.</li><li>Set <code>elementor_content_verified=true</code> only after visual verification, then consider Global ON as a separate decision.</li></ol></div>
	<?php }

	private function renderCandidates( $summary ): void {?>
		<div class="etg-pub-card"><h2>Publication Candidate Preview <span class="etg-pub-badge etg-pub-readonly">READ-ONLY</span></h2><p>This evaluates approved combinations against real Terms, Query Builder structure, background taxonomy result counts, content readiness and the indexing policy. It never adds a URL to the live sitemap while Global bridge is OFF.</p><div class="etg-pub-actions"><form method="get"><input type="hidden" name="page" value="etg-filter-seo-publication"/><input type="hidden" name="tab" value="candidates"/><input type="hidden" name="etg_build_publication_preview" value="1"/><button class="button button-secondary" type="submit">Build Publication Preview</button><?php $this->tip('Evaluates up to 100 approved combinations in this view. No configuration or profile is written.'); ?></form><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="etg_dfsb_export_publication_preview"/><?php wp_nonce_field('etg_dfsb_export_publication_preview');?><button class="button button-secondary" type="submit">Download Preview JSON</button><?php $this->tip('Downloads a bounded read-only publication report for audit and review.'); ?></form></div></div>
		<?php if(is_array($summary)):?>
		<div class="etg-pub-card"><strong>Candidates:</strong> <?php echo esc_html((string)$summary['candidate_count']);?> &nbsp; <strong>Would index:</strong> <?php echo esc_html((string)$summary['would_index_count']);?> &nbsp; <strong>Live sitemap included:</strong> <?php echo esc_html((string)$summary['sitemap_included_count']);?><pre class="etg-pub-code"><?php echo esc_html(wp_json_encode($summary['exclusion_reasons'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));?></pre></div>
		<table class="etg-pub-table"><thead><tr><th>URL / Signature</th><th>SEO</th><th>Content</th><th>Result Count</th><th>Sitemap</th><th>Exclusions</th></tr></thead><tbody><?php foreach((array)$summary['candidates'] as $row):?><tr><td><code><?php echo esc_html((string)$row['signature']);?></code><br/><small><?php echo esc_html((string)$row['url']);?></small></td><td><strong><?php echo esc_html((string)($row['metadata']['title']??''));?></strong><br/><?php echo esc_html((string)($row['metadata']['description']??''));?></td><td><?php echo !empty($row['content_ready'])?'READY':'BLOCKED';?> · <?php echo esc_html((string)$row['content_chars']);?> chars<br/>Elementor: <?php echo !empty($row['elementor_content_verified'])?'VERIFIED':'NOT VERIFIED';?></td><td><?php echo is_numeric($row['result_count'])?esc_html((string)$row['result_count']):'N/A';?><br/><small><?php echo esc_html((string)$row['result_count_source']);?></small></td><td><?php echo !empty($row['sitemap_included'])?'INCLUDED':(!empty($row['would_index'])?'WOULD INDEX AFTER GLOBAL ON':'EXCLUDED');?></td><td><?php echo esc_html(implode(', ',(array)$row['exclusion_reasons']));?></td></tr><?php endforeach;?></tbody></table>
		<?php endif;
	}

	private function renderElementor(): void {?>
		<div class="etg-pub-card"><h2>Elementor Theme Builder support</h2><p>Use Shortcode widgets or shortcode-capable text fields inside your archive template. Output is generated server-side from the exact resolved filter Terms, so crawlers see the same content used to build SEO metadata.</p><pre class="etg-pub-code">[etg_filter_h1]
[etg_filter_intro]
[etg_filter_sections]
[etg_filter_gallery mode="priority" limit="8" size="large"]
[etg_filter_breadcrumb_context]

[etg_filter_term role="location" field="name"]
[etg_filter_term role="location" field="description" autop="1"]
[etg_filter_term role="tour_type" field="short_description" autop="1"]
[etg_filter_term role="style" field="description" autop="1"]

[etg_filter_term_section role="location" field="description" heading="1" heading_level="2"]
[etg_filter_term_section role="tour_type" field="description" heading="1" heading_level="2"]</pre></div>
		<div class="etg-pub-card"><h2>Safe dark presentation</h2><p>To inspect these Elementor sections on real Production URLs without turning on Rank Math/indexing authority, explicitly set <code>elementor_render_when_global_off=true</code>. This permits only the ETG shortcode presentation to use the read-only evidence context when the request is stopped by the Global kill switch. It does not publish sitemap URLs or SEO metadata.</p></div>
		<div class="etg-pub-card"><h2>Content-readiness alignment</h2><p>The SEO content gate reads the same Term <code>description</code> and <code>short_description</code> sources. If your Term content is stored in custom JetEngine/ACF keys, map those keys into each taxonomy rule's <code>field_map.short_description</code> or other supported SEO fields so the visible sections and metadata share one source of truth.</p><p>Use this publication block while building:</p><pre class="etg-pub-code">"publication": {
  "sitemap": true,
  "hreflang": true,
  "schema": true,
  "social": true,
  "include_images_in_sitemap": true,
  "require_elementor_content": true,
  "elementor_render_when_global_off": true,
  "elementor_content_verified": false,
  "max_preview_urls": 100
}</pre><p>After the Theme Builder template is visibly correct on representative real filter URLs, set <code>elementor_content_verified=true</code>. Until then, the indexing policy fails closed with <code>elementor_content_not_verified</code>. You may turn dark rendering back off after verification; it is irrelevant once Global is ON because the normal live context takes precedence.</p></div>
	<?php }

	private function renderSitemap(): void {?>
		<div class="etg-pub-card"><h2>Rank Math dynamic sitemap</h2><p>The plugin registers an <code>etg-filter-seo</code> sitemap provider with Rank Math. When Global bridge is ON, only candidates whose final indexing decision is <code>index=true</code> are returned. When Global bridge is OFF, the provider returns no URLs.</p><p><strong>Expected provider URL:</strong> <code><?php echo esc_html(home_url('/etg-filter-seo-sitemap.xml'));?></code></p><p>If the eligible set exceeds Rank Math's per-sitemap entry limit, the provider emits paginated sitemap files. Post, taxonomy-relation, Term, Term-meta and ETG profile changes invalidate the provider cache so removed or no-longer-eligible combinations disappear on refresh.</p></div>
		<div class="etg-pub-card"><h2>Multilingual discovery</h2><p>WPML hreflang output is replaced only for valid ETG dynamic pages. Alternate URLs are emitted only when the translated Terms exist and the target language has its own exact approved profile-bound combination. <code>x-default</code> points to the approved default-language dynamic URL.</p></div>
	<?php }

	public function export(): void {
		if(!current_user_can('manage_options')){wp_die('Forbidden','Forbidden',array('response'=>403));}
		check_admin_referer('etg_dfsb_export_publication_preview');
		$payload=$this->publication->publicationSummary(500);
		if(function_exists('nocache_headers')){nocache_headers();}
		header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="etg-dfsb-publication-preview.json"');
		echo wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
	}

	private function tip( string $text ): void {printf('<span class="etg-pub-tip" tabindex="0" title="%1$s" aria-label="%1$s">?</span>',esc_attr($text));}
}
