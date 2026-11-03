<?php
/**
 * Safe company readiness projection. This is not an engine loader.
 */
defined('ABSPATH') || exit;
final class MAD4B_Commerce_Readiness {
    public static function inspect(array $inventory): array {
        $results = [];
        foreach (($inventory['modules'] ?? []) as $id => $module) {
            if (empty($module['enrollable'])) {
                continue;
            }
            $pending = [];
            if (!empty($module['desired'])) {
                if (empty($module['runtime_detected'])) {
                    $pending[] = 'module_not_loaded';
                }
                if (!empty($module['missing_dependencies'])) {
                    $pending[] = 'missing_dependencies';
                }
                $pending[] = 'release_certification_required';
                $pending[] = 'tenant_activation_receipt_required';
            }
            $results[$id] = [
                'requested' => !empty($module['desired']),
                'blockers' => $pending,
                'activation_authorized' => false,
                'activation_executed' => false,
            ];
        }
        return [
            'contract' => 'mad4b.commerce.readiness.v1',
            'modules' => $results,
            'activation_authorized' => false,
            'activation_executed' => false,
        ];
    }
}
