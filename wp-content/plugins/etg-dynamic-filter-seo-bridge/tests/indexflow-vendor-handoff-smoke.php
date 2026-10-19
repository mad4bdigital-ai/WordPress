<?php
require __DIR__ . '/indexflow-managed-runtime-smoke.php';
define('WPINC', 'wp-includes');
require $argv[1]; // CI-owned temp file, extracted from this checkout's vendor ZIP.
require $argv[2];
$providerCalled = false;
$vendor = (object)array(
    'data' => new class { public function get_request() { return $_REQUEST; } public function get_sitepath() { return ''; } },
    'utils' => new class { public function is_truthy_or_zero($value) { return null !== $value && '' !== $value; } },
    'query' => new class {
        public $provider; public $vars = array();
        public function set_provider_from_request($provider) { $this->provider=$provider; }
        public function get_current_provider($return=null) { return explode(':',$this->provider)[0]; }
        public function set_query_var_to_request($key,$value) { $this->vars[$key]=$value; }
        public function get_query_from_request() { return $this->vars; }
    },
    'providers' => new class {
        public function get_providers($name) {
            if ('jet-engine' !== $name) return null;
            return new class { public function apply_filters_in_request() { $GLOBALS['providerCalled']=true; } };
        }
    });
function jet_smart_filters() { return $GLOBALS['vendor']; }
$authority = true;
$_SERVER['REQUEST_URI'] = $url; $_REQUEST = array();
$wp = (object)array('query_vars'=>array('indexflow_route'=>'cairo-en'));
$runtime->parseRequest($wp);
$render=(new ReflectionClass('Jet_Smart_Filters_Render'))->newInstanceWithoutConstructor();
$render->apply_filters_from_permalink($wp);
expect($vendor->query->provider === 'jet-engine:Tours_01', 'Real vendor receives exact case-preserving provider/query');
expect($vendor->query->vars['tax'] === 'location:cairo;style:luxury', 'Real vendor parses both native dimensions');
expect($providerCalled, 'Real vendor dispatches the existing provider path');
$parseHooks=array_values(array_filter($hooks,static function($row){return 'parse_request' === $row[0];}));
expect($parseHooks[0][2] < 10, 'Handoff precedes the actual vendor parse_request callbacks');
$aliases = (new ReflectionClass('Jet_Smart_Filters_URL_Aliases'))->newInstanceWithoutConstructor();
$aliases->use_url_aliases = true;
$aliases->url_aliases = array(array('needle'=>'/jsf/', 'replacement'=>'/filters/'));
$bridge = new \ETG\DynamicFilterSEOBridge\IndexFlow\VendorUrlAliases($aliases);
$_SERVER['REQUEST_URI'] = '/original/'; $_REQUEST = array('keep'=>'original'); $redirectActive = true;
$aliased = $bridge->dispatch('build',$nativeUrl);
expect(strpos($aliased,'/filters/') !== false && $bridge->dispatch('resolve',$aliased) === $nativeUrl, 'Real vendor alias build/resolve round trip');
expect($_SERVER['REQUEST_URI'] === '/original/' && $_REQUEST === array('keep'=>'original') && $redirectActive, 'Alias inspection restores request globals and canonical redirect hook');
echo "PASS actual bundled JetSmartFilters permalink-to-provider handoff (source fixture; not full WordPress acceptance)\n";
