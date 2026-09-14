<?php
declare(strict_types=1);
function need($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
$root=dirname(__DIR__);
$bootstrap=file_get_contents($root.'/includes/Bootstrap.php');
$metadata=file_get_contents($root.'/includes/RankMath/MetadataAdapter.php');
$probe=file_get_contents($root.'/includes/SEO/PublicationResultCountProbe.php');
$topology=file_get_contents($root.'/includes/Runtime/RuntimeTopologyDiscoverer.php');
$publication=file_get_contents($root.'/includes/SEO/PublicationRegistry.php');
$operational=file_get_contents($root.'/includes/Admin/OperationalPage.php');
$config=file_get_contents($root.'/includes/Config/Configuration.php');
$uninstall=file_get_contents($root.'/includes/Lifecycle/UninstallPolicy.php');
need(false===strpos($metadata,'wpml_hreflangs'),'Rank Math adapter must not own WPML hreflang hook');
need(false!==strpos($bootstrap,'new HreflangAdapter'),'language adapter owns hreflang registration');
need(false!==strpos($probe,'LanguageResolverInterface'),'publication count probe uses language abstraction');
need(false!==strpos($bootstrap,'new PublicationResultCountProbe($queryBindingResolver,$languages)'),'Bootstrap passes selected language adapter to publication probe');
need(false!==strpos($bootstrap,"new ContentSlotRegistry('alpha13'"),'Alpha13 compatibility controls legacy content slots');
need(false!==strpos($topology,'bool $persistCache = true'),'topology discovery exposes persistence boundary');
need(false!==strpos($bootstrap,'$topology->discover(true,false)')&&false!==strpos($bootstrap,'$topology->discover(false,false)'),'diagnostic topology calls are non-persistent');
need(false!==strpos($publication,'candidates(int $limit=50,bool $persistCache=true)'),'publication candidates expose cache persistence boundary');
need(false!==strpos($publication,'$this->candidates($limit,false)'),'publication summary is read-only and bypasses persistent cache writes');
need(false!==strpos($operational,"'sanitize_callback' => array(\$this->config, 'sanitizeForStorage')"),'Settings API uses lifecycle-safe storage sanitizer');
need(false!==strpos($config,"'profiles_json' => '[]'"),'fresh default has zero profiles');
need(false!==strpos($config,"'enabled' => false"),'fresh default is globally inert');
need(false!==strpos($uninstall,"'delete_on_uninstall'")&&false!==strpos($uninstall,'is_multisite'),'destructive uninstall is explicit and multisite-safe');
need(false!==strpos($bootstrap,'CapabilityRequirements::forProfiles'),'Bootstrap is capability-driven');
need(false!==strpos($bootstrap,'LocaleLanguageResolver'),'Core has vendor-free language fallback');
echo "INDEXFLOW_FOUNDATION_ARCHITECTURE=PASS\n";
