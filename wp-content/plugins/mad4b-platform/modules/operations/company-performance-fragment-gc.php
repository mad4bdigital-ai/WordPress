<?php
/**
 * Bounded, opportunistic tenant fragment garbage collector.
 * Runs on an eligible Staging request, without depending on WP-Cron.
 */
defined('ABSPATH') || exit;

if (!function_exists('mad4b_company_fragment_gc_scan')) {
 function mad4b_company_fragment_gc_scan(
     string $directory,
     int $now,
     int $max_entries = 250,
     int $interval_seconds = 120,
     bool $force = false
 ): array {
     $stats = ['scanned'=>0, 'deleted'=>0, 'expired'=>0, 'skipped'=>0, 'locked'=>false];
     $max_entries = max(1, min(500, $max_entries));
     $interval_seconds = max(30, min(3600, $interval_seconds));
     $base = realpath(sys_get_temp_dir());
     if (!$base || !is_dir($directory) || is_link($directory) ||
         realpath($directory) === false ||
         dirname(realpath($directory)) !== $base ||
         !preg_match('/^mad4b-company-(menu|card)-fragments-[a-f0-9]{64}$/D', basename($directory))) {
         return $stats;
     }
     $lockfile = $directory.'/.gc.lock';
     if (is_link($lockfile)) return $stats;
     $lock = @fopen($lockfile, 'c+');
     if (!$lock) return $stats;
     try {
         if (!@flock($lock, LOCK_EX | LOCK_NB)) {
             $stats['locked'] = true;
             return $stats;
         }
         rewind($lock);
         $last = (int) trim((string) stream_get_contents($lock, 32));
         if (!$force && $last > 0 && $now - $last < $interval_seconds) return $stats;
         rewind($lock);
         ftruncate($lock, 0);
         fwrite($lock, (string)$now);
         fflush($lock);

         $iter = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
         foreach ($iter as $entry) {
             if ($stats['scanned'] >= $max_entries) break;
             if ($entry->isLink() || !$entry->isFile() ||
                 !preg_match('/^[a-f0-9]{64}\.json$/D', $entry->getFilename())) {
                 $stats['skipped']++;
                 continue;
             }
             $stats['scanned']++;
             if ($entry->getSize() > 1100000) {
                 $stats['skipped']++;
                 continue;
             }
             $value = json_decode((string) @file_get_contents($entry->getPathname()), true);
             if (!is_array($value) || !isset($value['expires']) ||
                 !is_int($value['expires']) || !is_string($value['html'] ?? null)) {
                 $stats['skipped']++;
                 continue;
             }
             if ($value['expires'] > $now) continue;
             $stats['expired']++;
             if (@unlink($entry->getPathname())) $stats['deleted']++;
         }
     } catch (\Throwable $e) {
         // Opportunistic cleanup must not interrupt WordPress page delivery.
     } finally {
         @flock($lock, LOCK_UN);
         @fclose($lock);
     }
     return $stats;
 }
}

add_action('init', static function () {
    if (wp_get_environment_type() !== 'staging' || PHP_SAPI === 'cli' ||
        !function_exists('mad4b_company_fragment_preset_config')) return;
    $cfg = mad4b_company_fragment_preset_config();
    if (!$cfg || ($cfg['enabled'] ?? false) !== true) return;
    // Require the same origin and exact public request as the feature gate.
    if (!function_exists('mad4b_company_fragment_preset_enabled') ||
        !mad4b_company_fragment_preset_enabled()) return;
    $hash = hash('sha256', strtolower($cfg['site_host']));
    foreach (['menu','card'] as $type) {
        $dir = sys_get_temp_dir().'/mad4b-company-'.$type.'-fragments-'.$hash;
        if (is_dir($dir)) mad4b_company_fragment_gc_scan($dir, time(), 250, 120);
    }
}, 25);
