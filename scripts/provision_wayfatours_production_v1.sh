#!/usr/bin/env bash
# Create a clean, independent WordPress PRODUCTION tenant runtime.
# Source: GitHub only. No staging database, option values, uploads, user records, or secrets.
# Deliberately binds HTTP to loopback until DNS, HTTPS and tenant readiness are verified.
set -euo pipefail
umask 077
SOURCE=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy
ROOT=/opt/hostinger-migrations/hosting-3/wayfatours-production
HOST=wayfatours.mad4b.com
WPCLI=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime/bin/wp-cli.phar
if [ "$(id -u)" -ne 0 ]; then echo "Root required" >&2; exit 2; fi
if [ ! -d "$SOURCE/wp-admin" ] || [ ! -f "$WPCLI" ] || [ -e "$ROOT/site-copy/wp-config.php" ]; then
 echo "Source missing or tenant already initialized; refusing destructive overwrite" >&2; exit 2
fi
if ss -ltn | grep -Eq '127\.0\.0\.1:8107([[:space:]]|$)'; then
 echo "Reserved internal port 8107 already in use" >&2;exit 2
fi
mkdir -p "$ROOT"/{stack,site-copy/wp-content/plugins,site-copy/wp-content/themes,site-copy/wp-content/mu-plugins,runtime/secrets,runtime/mysql,runtime/logs}
chmod 700 "$ROOT/runtime/secrets"
# WordPress core is software, never data. Skip staging wp-config, media and generated dirs.
rsync -a --no-owner --no-group "$SOURCE/wp-admin" "$SOURCE/wp-includes" "$ROOT/site-copy/"
find "$SOURCE" -maxdepth 1 -type f -name '*.php' \
  ! -name 'wp-config.php' ! -name 'wp-config-docker.php' ! -name '*.bak.php' \
  -exec cp -p --target-directory="$ROOT/site-copy" '{}' +
for plugin in elementor elementor-pro jet-engine jet-smart-filters woocommerce sitepress-multilingual-cms wpml-string-translation astra-addon; do
 if [ ! -d "$SOURCE/wp-content/plugins/$plugin" ]; then echo "Missing required plugin code: $plugin" >&2; exit 2; fi
 rsync -a --no-owner --no-group "$SOURCE/wp-content/plugins/$plugin" "$ROOT/site-copy/wp-content/plugins/"
done
rsync -a --no-owner --no-group "$SOURCE/wp-content/themes/astra" "$ROOT/site-copy/wp-content/themes/"
for mu in 198-mad4b-elementor-kit-integrity.php mad4b-company-template-identity.php mad4b-company-policy-library.php;do
 test -f "$SOURCE/wp-content/mu-plugins/$mu" || { echo "Missing governed MU: $mu" >&2;exit 2; }
 cp "$SOURCE/wp-content/mu-plugins/$mu" "$ROOT/site-copy/wp-content/mu-plugins/$mu"
done
mkdir -p "$ROOT/site-copy/wp-content/uploads" "$ROOT/site-copy/wp-content/upgrade"
# Never copy any staging customer, media, Elementor, WPML, login or test data.
pw_db="$(openssl rand -hex 36)"
pw_root="$(openssl rand -hex 36)"
pw_admin="$(openssl rand -hex 32)"
{
 printf 'MARIADB_DATABASE=%s\n' 'wayfatours_production'
 printf 'MARIADB_USER=%s\n' 'wayfatours_wp'
 printf 'MARIADB_PASSWORD=%s\n' "$pw_db"
 printf 'MARIADB_ROOT_PASSWORD=%s\n' "$pw_root"
} > "$ROOT/runtime/secrets/db.env"
{
 printf 'WAYFATOURS_DB_NAME=wayfatours_production\n'
 printf 'WAYFATOURS_DB_USER=wayfatours_wp\n'
 printf 'WAYFATOURS_DB_PASSWORD=%s\n' "$pw_db"
 for key in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT;do
  printf 'WAYFATOURS_%s=%s\n' "$key" "$(openssl rand -hex 48)"
 done
} > "$ROOT/runtime/secrets/php.env"
printf '%s\n' "$pw_admin" > "$ROOT/runtime/secrets/wp-admin.initial-secret"
chmod 600 "$ROOT/runtime/secrets/"*
cat > "$ROOT/site-copy/wp-config.php" <<'PHP'
<?php
define('DB_NAME', getenv('WAYFATOURS_DB_NAME'));
define('DB_USER', getenv('WAYFATOURS_DB_USER'));
define('DB_PASSWORD', getenv('WAYFATOURS_DB_PASSWORD'));
define('DB_HOST', 'db:3306');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wp_';
foreach (['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $key) {
 define($key, getenv('WAYFATOURS_'.$key));
}
define('WP_ENVIRONMENT_TYPE', 'production');
define('DISALLOW_FILE_EDIT', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_DEBUG', false);
define('WP_CACHE', false);
define('DISABLE_WP_CRON', true);
define('WP_HOME', 'https://wayfatours.mad4b.com');
define('WP_SITEURL', 'https://wayfatours.mad4b.com');
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
 $_SERVER['HTTPS'] = 'on';
}
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
require_once ABSPATH.'wp-settings.php';
PHP
cat > "$ROOT/stack/compose.yml" <<'YAML'
name: wayfatours-production
services:
  db:
    image: mariadb:11.8.9
    container_name: wayfatours-production-db
    env_file: ../runtime/secrets/db.env
    volumes: [../runtime/mysql:/var/lib/mysql]
    restart: unless-stopped
    networks: [private]
    healthcheck:
      test: ["CMD-SHELL", "mariadb-admin ping -h 127.0.0.1 --silent"]
      interval: 15s
      timeout: 5s
      retries: 20
  php:
    image: wordpress:php8.5-fpm@sha256:5a57b4db54e5fe041dd2926a8c7d99a6feab947648c41cf385beb81bfe578208
    container_name: wayfatours-production-php
    user: '33:33'
    env_file: ../runtime/secrets/php.env
    volumes:
      - ../site-copy:/var/www/html
      - ../../etg-commerce-staging/runtime/bin/wp-cli.phar:/usr/local/bin/wp:ro
    depends_on:
      db: {condition: service_healthy}
    restart: unless-stopped
    networks: [private]
  nginx:
    image: nginx:1.28-alpine
    container_name: wayfatours-production-nginx
    user: '33:33'
    ports: ["127.0.0.1:8107:8080"]
    volumes:
      - ../site-copy:/var/www/html:ro
      - ./nginx.conf:/etc/nginx/conf.d/default.conf:ro
    tmpfs:
      - /var/cache/nginx:rw,nosuid,mode=1777
      - /var/run:rw,nosuid,mode=1777
      - /tmp:rw,nosuid,mode=1777
    depends_on: [php]
    restart: unless-stopped
    networks: [private, edge]
networks:
  private:
    name: wayfatours-production-private
    internal: true
  edge:
    name: wayfatours-production-edge
YAML
cat > "$ROOT/stack/nginx.conf" <<'NGINX'
server {
  listen 8080;
  server_name wayfatours.mad4b.com;
  root /var/www/html;
  index index.php;
  autoindex off;
  client_max_body_size 32m;
  location / { try_files $uri $uri/ /index.php?$args; }
  location ~* (^|/)(wp-config\.php|\.env|composer\.(json|lock)|[^/]+\.sql)$ { deny all; }
  location ~* ^/wp-content/uploads/.*\.php(?:$|/) { deny all; }
  location ~ \.php$ {
    try_files $uri =404;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass php:9000;
    fastcgi_read_timeout 90s;
  }
  location ~ /\.(?!well-known) { deny all; }
}
NGINX
# No outgoing messages from a new tenant until sender/domain is verified.
cat > "$ROOT/site-copy/wp-content/mu-plugins/199-wayfatours-unconfigured-email.php" <<'PHP'
<?php
defined('ABSPATH') || exit;
add_filter('pre_wp_mail', static function ($result) { return false; }, 1);
PHP
chown -R 33:33 "$ROOT/site-copy"
chmod 600 "$ROOT/site-copy/wp-config.php"
chmod 755 "$ROOT/stack"
chmod 644 "$ROOT/stack/compose.yml" "$ROOT/stack/nginx.conf"
cd "$ROOT/stack"
docker compose config --quiet
docker compose up -d --wait
docker exec -u 33:33 wayfatours-production-php wp core install --path=/var/www/html \
  --url="https://$HOST" --title='Wayfatours' --admin_user='wayfatours-admin' \
  --admin_password="$pw_admin" --admin_email="site-admin@$HOST" --skip-email
# WordPress site settings are company-specific, not copied.
docker exec -u 33:33 wayfatours-production-php wp option update blogdescription 'Curated journeys and unforgettable places' --path=/var/www/html
docker exec -u 33:33 wayfatours-production-php wp theme activate astra --path=/var/www/html
# Activate only base UI modules, not unapproved commerce/payment or license integrations.
docker exec -u 33:33 wayfatours-production-php wp plugin activate elementor jet-engine --path=/var/www/html
# Elementor Kit must be independently initialized to the target company's safe palette.
docker exec -i -u 33:33 wayfatours-production-php wp eval-file - --path=/var/www/html <<'WP'
<?php
if (wp_get_environment_type() !== 'production' || DB_NAME !== 'wayfatours_production' ||
 wp_parse_url(home_url(),PHP_URL_HOST) !== 'wayfatours.mad4b.com' || get_option('blogname') !== 'Wayfatours') {
 WP_CLI::error('WAYFATOURS_PRODUCTION_IDENTITY_MISMATCH');
}
$kit=(int)\Elementor\Plugin::$instance->kits_manager->get_active_id();
if (!$kit || get_post_type($kit)!=='elementor_library') WP_CLI::error('NO_ELEMENTOR_KIT');
$settings=(array)get_post_meta($kit,'_elementor_page_settings',true);
$settings['system_colors']=[
 ['_id'=>'primary','title'=>'Primary','color'=>'#173A4C'],
 ['_id'=>'secondary','title'=>'Secondary','color'=>'#235E6D'],
 ['_id'=>'text','title'=>'Text','color'=>'#173A4C'],
 ['_id'=>'accent','title'=>'Accent','color'=>'#BB9253'],
];
$settings['custom_colors']=[['_id'=>'bnav001','title'=>'Navigation Surface','color'=>'#F8F5EE']];
update_post_meta($kit,'_elementor_page_settings',$settings);
if (!function_exists('mad4b_kit_integrity_admission') || is_wp_error(mad4b_kit_integrity_admission())) {
 WP_CLI::error('PRODUCTION_KIT_INTEGRITY_DENIED');
}
$administrator=get_role('administrator');
if ($administrator) $administrator->add_cap('mad4b_manage_company_operations');
echo wp_json_encode(['site'=>home_url(),'environment'=>wp_get_environment_type(),'kit_id'=>$kit,'kit_admission'=>'PASS','initial_company_name'=>get_option('blogname')]).PHP_EOL;
WP
# Remove default placeholder WordPress posts/pages/comments: no inherited company content.
docker exec -u 33:33 wayfatours-production-php wp post delete 1 --force --path=/var/www/html >/dev/null || true
echo 'WAYFATOURS_PRODUCTION_RUNTIME_READY_PRIVATE_INGRESS'
echo "Host: $HOST | Host-only port: 127.0.0.1:8107"
echo "Credential file stays on server: $ROOT/runtime/secrets/wp-admin.initial-secret"
