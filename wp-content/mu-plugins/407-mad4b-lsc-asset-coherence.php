<?php
/**
 * Plugin Name: MAD4B — LiteSpeed Generated-Asset Coherence
 * Description: Retains hashed CSS/JS and matching precompressed Gzip sidecars across LiteSpeed cache purges.
 * Version: 1.3.0
 */
defined('ABSPATH') || exit;

final class MAD4B_LSC_Asset_Coherence {
    const HOLD_SECONDS = 300;
    const MAX_BYTES = 100663296; // 96 MiB across CSS and JS.
    const MAX_FILES = 400;
    private static $pending = false;

    private static function enabled(): bool {
        $context = get_option('mad4b_scp_brand_context_profile_v1', []);
        $uuid = is_array($context) ? (string)($context['site_uuid'] ?? '') : '';
        return (bool) preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $uuid)
            && defined('LITESPEED_STATIC_DIR')
            && in_array(wp_get_environment_type(), ['staging', 'production'], true);
    }

    private static function valid_file(string $name, string $type): bool {
        return (bool) preg_match('/^[a-f0-9]{32}\.'.preg_quote($type, '/').'$/D', $name);
    }

    private static function cache_root(): string {
        return rtrim((string)LITESPEED_STATIC_DIR, '/');
    }

    private static function backup_root(): string {
        return self::cache_root().'/.mad4b-asset-retention';
    }

    private static function preferred_files(string $hold): array {
        $priorities = ['css' => [], 'js' => []];
        $file = $hold.'/priority-assets.json';
        if (is_link($file) || !is_file($file) || @filesize($file) > 32768) return $priorities;
        $metadata = json_decode((string) @file_get_contents($file), true);
        if (!is_array($metadata) || ($metadata['version'] ?? null) !== 1
            || ($metadata['timestamp'] ?? 0) < time() - 3600) return $priorities;
        foreach (['css', 'js'] as $kind) {
            if (!is_array($metadata[$kind] ?? null)) continue;
            foreach (array_slice($metadata[$kind], 0, 180) as $name) {
                if (is_string($name) && self::valid_file($name, $kind)) {
                    $priorities[$kind][$name] = true;
                }
            }
        }
        return $priorities;
    }

    public static function snapshot(): void {
        if (!self::enabled()) return;
        $root = self::cache_root();
        $hold = self::backup_root();
        if (!is_dir($hold) && !wp_mkdir_p($hold)) return;
        if (is_link($hold)) return;
        @chmod($hold, 0750);

        $total = 0;
        $count = 0;
        $priorities = self::preferred_files($hold);
        $entries = [];
        foreach (['css', 'js'] as $kind) {
            $srcdir = $root.'/'.$kind;
            $dst = $hold.'/'.$kind;
            if (!is_dir($srcdir) || is_link($srcdir)) continue;
            if (!is_dir($dst) && !wp_mkdir_p($dst)) continue;
            if (is_link($dst)) continue;
            foreach (new DirectoryIterator($srcdir) as $entry) {
                if ($entry->isDot() || !$entry->isFile() || $entry->isLink()) continue;
                $name = $entry->getFilename();
                if (!self::valid_file($name, $kind)) continue;
                $entries[] = [$kind, $name, $entry->getSize(), $entry->getMTime(),
                    isset($priorities[$kind][$name]) ? 0 : 1];
            }
        }
        // Sort across CSS and JS together. CSS-only iteration could exhaust
        // the 96 MiB quota before protecting public page JavaScript.
        usort($entries, static function($a, $b) {
            return ($a[4] <=> $b[4]) ?: ($b[3] <=> $a[3]) ?: strcmp($a[1], $b[1]);
        });
        foreach ($entries as $entry) {
            [$kind, $name, $bytes] = $entry;
            $origin = $root.'/'.$kind.'/'.$name;
            $target = $hold.'/'.$kind.'/'.$name;
            if (!is_file($origin) || is_link($origin) || is_link($target)) continue;
            if ($bytes <= 0 || $bytes > 6291456 || $total + $bytes > self::MAX_BYTES
                || $count >= self::MAX_FILES) continue;
            if (!is_file($target)) {
                $temp = $target.'.tmp-'.getmypid();
                if (!@copy($origin, $temp)) continue;
                if (!@rename($temp, $target)) {
                    @unlink($temp);
                    continue;
                }
            }
            @touch($target);
            $total += $bytes;
            $count++;
            $gzOrigin = $origin.'.gz';
            $gzTarget = $target.'.gz';
            if (!is_link($gzOrigin) && is_file($gzOrigin) && !is_link($gzTarget)
                && $count < self::MAX_FILES) {
                $gzBytes = @filesize($gzOrigin);
                if ($gzBytes > 0 && $gzBytes <= 6291456 && $total + $gzBytes <= self::MAX_BYTES) {
                    if (!is_file($gzTarget)) {
                        $temp = $gzTarget.'.tmp-'.getmypid();
                        if (@copy($gzOrigin, $temp)) {
                            if (!@rename($temp, $gzTarget)) @unlink($temp);
                        }
                    }
                    if (is_file($gzTarget)) {
                        @touch($gzTarget);
                        $total += $gzBytes;
                        $count++;
                    }
                }
            }
        }
        self::$pending = ($count > 0);
    }

    public static function restore(): void {
        if (!self::$pending || !self::enabled()) return;
        self::$pending = false;
        $root = self::cache_root();
        $hold = self::backup_root();
        $now = time();
        $candidates = [];
        foreach (['css', 'js'] as $kind) {
            $backupdir = $hold.'/'.$kind;
            if (!is_dir($backupdir) || is_link($backupdir)) continue;
            foreach (new DirectoryIterator($backupdir) as $entry) {
                if ($entry->isDot() || !$entry->isFile() || $entry->isLink()) continue;
                $name = $entry->getFilename();
                // Retained sidecars are copied with their original CSS/JS files.
                // Expire gzip files independently when their five-minute hold ends.
                if (preg_match('/^[a-f0-9]{32}\\.'.preg_quote($kind, '/').'\\.gz$/D', $name)) {
                    if ($now - $entry->getMTime() > self::HOLD_SECONDS) {
                        @unlink($backupdir.'/'.$name);
                    }
                    continue;
                }
                if (!self::valid_file($name, $kind)) continue;
                $path = $backupdir.'/'.$name;
                if ($now - $entry->getMTime() > self::HOLD_SECONDS) {
                    @unlink($path);
                    continue;
                }
                $candidates[] = [$entry->getMTime(), $kind, $name, $path, $entry->getSize()];
            }
        }
        $priorities = self::preferred_files($hold);
        usort($candidates, static function($a, $b) use($priorities) {
            $aPriority = isset($priorities[$a[1]][$a[2]]) ? 0 : 1;
            $bPriority = isset($priorities[$b[1]][$b[2]]) ? 0 : 1;
            return ($aPriority <=> $bPriority) ?: ($b[0] <=> $a[0]);
        });
        $used = 0;
        $count = 0;
        foreach ($candidates as $asset) {
            [, $kind, $name, $source, $bytes] = $asset;
            if ($bytes <= 0 || $bytes > 6291456 || $used + $bytes > self::MAX_BYTES || $count >= self::MAX_FILES) continue;
            $dir = $root.'/'.$kind;
            if (!is_dir($dir) && !wp_mkdir_p($dir)) continue;
            if (is_link($dir)) continue;
            $dest = $dir.'/'.$name;
            if (!is_file($dest) && !is_link($dest)) {
                $temporary = $dest.'.mad4b-'.getmypid().'.tmp';
                if (@copy($source, $temporary)) {
                    if (!@rename($temporary, $dest)) @unlink($temporary);
                }
            }
            $used += $bytes;
            $count++;
            $gzSource = $source.'.gz';
            $gzDest = $dest.'.gz';
            if (!is_link($gzSource) && is_file($gzSource) && !is_link($gzDest)
                && $count < self::MAX_FILES) {
                $gzBytes = @filesize($gzSource);
                if ($gzBytes > 0 && $gzBytes <= 6291456 && $used + $gzBytes <= self::MAX_BYTES) {
                    if (!is_file($gzDest)) {
                        $temp = $gzDest.'.mad4b-'.getmypid().'.tmp';
                        if (@copy($gzSource, $temp)) {
                            if (!@rename($temp, $gzDest)) @unlink($temp);
                        }
                    }
                    if (is_file($gzDest)) {
                        $used += $gzBytes;
                        $count++;
                    }
                }
            }
        }
    }
}
add_action('litespeed_purged_all_cssjs', ['MAD4B_LSC_Asset_Coherence', 'snapshot'], 1);
add_action('litespeed_purged_all', ['MAD4B_LSC_Asset_Coherence', 'restore'], 9999);
add_action('shutdown', ['MAD4B_LSC_Asset_Coherence', 'restore'], 9999);
