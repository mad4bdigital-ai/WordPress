"""Elementor native photo focus must match its own Background selector specificity."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/'wp-content/mu-plugins/mad4b-tour-gallery-popup-layout.php').read_text()
start=s.index('public static function add_elementor_controls(')
end=s.index('public static function register_tag(',start)
block=s[start:end]
for field in ('background-position: {{VALUE}};','background-size: {{VALUE}};'):
    assert "'{{WRAPPER}}:not(.elementor-motion-effects-element-type-background)'=>'"+field+"'" in block
    assert "'{{WRAPPER}} > .elementor-motion-effects-container > .elementor-motion-effects-layer'=>'"+field+"'" in block
    assert "'selectors'=>['{{WRAPPER}}'=>'"+field+"']" not in block
assert 'dynamic' in block and "'active'=>true" in block
assert 'No runtime CSS/JS override' in block
print('PASS native dynamic selector matches Elementor background and motion-layer specificity')
