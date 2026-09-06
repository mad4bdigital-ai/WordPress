from pathlib import Path

admin = Path('wp-content/plugins/wpl-client/includes/class-wpl-client-admin.php')
text = admin.read_text(encoding='utf-8')
old = "            'api_base' => rtrim( str_replace( '/wpl/v1', '', WPL_SERVER_API_URL ), '/' ),\n"
if text.count(old) != 1:
    raise SystemExit(f'api_base cleanup expected one match, got {text.count(old)}')
admin.write_text(text.replace(old, '', 1), encoding='utf-8')
print('Removed unused direct WPL api_base localization')
