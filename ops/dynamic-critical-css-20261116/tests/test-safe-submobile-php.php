<?php
declare(strict_types=1);
namespace Elementor {
    class NativeBreakpoint {
        public function __construct(private int $value) {}
        public function get_value(): int { return $this->value; }
    }
    class NativeBreakpoints {
        public function __construct(private int $mobile) {}
        public function get_breakpoints(): array {
            return ['mobile'=>new NativeBreakpoint($this->mobile)];
        }
    }
    class Plugin {public static object $instance;}
}
namespace {
    define('ABSPATH','/tmp/');
    define('WP_CONTENT_DIR','/tmp/');
    function add_action(...$args): void {}
    function get_option($key,$default=null) {return $key==='mad4b_css_ai_epoch_v1' ? 129 : $default;}
    function wp_parse_url($url,$component=null){return parse_url($url,$component);}
    function get_queried_object_id(){return 123;}
    function assertCase(string $label,bool $result): void {
        if (!$result) throw new \RuntimeException('FAIL '.$label);
        echo 'PASS '.$label."\n";
    }
    \Elementor\Plugin::$instance=(object)['breakpoints'=>new \Elementor\NativeBreakpoints(767)];
    require '/tmp/mad4b-safe-mobile-cap-20261117-lint.php';
    $type = new \ReflectionClass('MAD4B_Verified_Critical_CSS_V1');
    $admit = $type->getMethod('adaptive_width_proven');
    $cert599=['adaptive_mobile_breakpoint_px'=>599,
       'native_mobile_breakpoint_px'=>767,
       'decision'=>'passed_evidence',
       'viewports'=>[320,390,414,599,600,767,768]];
    $cert767=['adaptive_mobile_breakpoint_px'=>767,
       'decision'=>'passed_evidence',
       'viewports'=>[320,390,414,767,768]];
    assertCase('legacy Elementor 767px certification unchanged',
       $admit->invoke(null,767,$cert767)===true);
    assertCase('narrower 599px cap requires exact seven-width proof',
       $admit->invoke(null,599,$cert599)===true);
    assertCase('599px submobile cap rejects absent 600px proof',
       $admit->invoke(null,599,[...$cert599,'viewports'=>[320,390,414,599,767,768]])===false);
    assertCase('599px submobile cap rejects missing Elementor 768px edge',
       $admit->invoke(null,599,[...$cert599,'viewports'=>[320,390,414,599,600,767]])===false);
    assertCase('599px cap cannot claim a different native mobile breakpoint',
       $admit->invoke(null,599,[...$cert599,'native_mobile_breakpoint_px'=>759])===false);
    assertCase('unverified width 601 rejected',
       $admit->invoke(null,601,$cert599)===false);
    assertCase('cap wider than Elementor mobile rejected',
       $admit->invoke(null,900,[...$cert599,'adaptive_mobile_breakpoint_px'=>900])===false);
    $buffer='<body><section class="elementor-45927"></section></body>';
    $elementorDoc=hash('sha256','45927');
    $row=['gate_status'=>'pass','route_path'=>'/','language'=>'en','device'=>'mobile',
      'page_id'=>123,'css_epoch'=>129,'elementor_doc_fingerprint'=>$elementorDoc,
      'css_critical_mobile_breakpoint'=>599,
      'evidence'=>['decision'=>'passed_evidence','adaptive_mobile_breakpoint_px'=>599,
       'native_mobile_breakpoint_px'=>767,'viewports'=>[320,390,414,599,600,767,768]]];
    $meta=['bundle_file'=>'0123456789abcdef0123456789abcdef.css',
      'variants'=>['/|en|mobile'=>$row]];
    $m=['version'=>2,'contract'=>'mad4b.mobile-critical-css.v2'];
    $_SERVER['REQUEST_URI']='/';
    $verify=$type->getMethod('verified_variant');
    assertCase('route+locale+template CSS narrow cap accepted with live Elementor',
       $verify->invoke(null,$m,$meta,$meta['bundle_file'],$buffer,'en')===$row);
    assertCase('non-matching Elementor mobile 600 invalidates old 767 certificate',
       (function() use($verify,$m,$meta,$buffer){
          \Elementor\Plugin::$instance=(object)['breakpoints'=>new \Elementor\NativeBreakpoints(600)];
          $result=$verify->invoke(null,$m,$meta,$meta['bundle_file'],$buffer,'en');
          \Elementor\Plugin::$instance=(object)['breakpoints'=>new \Elementor\NativeBreakpoints(767)];
          return $result===null;
       })());
    $style='x'.str_repeat('a',17000);
    $rest='<html><head><style id="mad4b-critical-css-v1" media="(max-width:599px)">'.$style.'</style>'.
      '<link id="mad4b-css-rest-v1" rel="stylesheet" media="(min-width:600px)" href="/wp-content/litespeed/css/0123456789abcdef0123456789abcdef.css">'.
      '<script data-mad4b-css-async-lib="inline-v1"></script></head><body>'.
      str_repeat('<i>static</i>',1000).'</body></html>';
    $complete=$type->getMethod('mobile_home_markup_complete');
    assertCase('old HTML from LiteSpeed never inherits unproven narrow-cap cache readiness',
       $complete->invoke(null,$rest)===false);
    $prop=$type->getProperty('verifiedSubMobileCap');
    $nativeProp=$type->getProperty('verifiedSubMobileNative');
    $prop->setValue(null,599);$nativeProp->setValue(null,767);
    assertCase('only request-local prior verified transform can certify narrow HTML',
       $complete->invoke(null,$rest)===true);
    \Elementor\Plugin::$instance=(object)['breakpoints'=>new \Elementor\NativeBreakpoints(760)];
    assertCase('Elementor breakpoint changed after transform blocks cache readiness',
       $complete->invoke(null,$rest)===false);
    echo "PASS 12/12 safe sub-mobile runtime regression cases\n";
}
