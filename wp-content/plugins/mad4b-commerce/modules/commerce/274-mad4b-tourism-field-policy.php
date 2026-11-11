<?php
/**
 * Plugin Name: MAD4B Tourism Field Policy Adapter
 * Description: Source-specific field metadata registry mounted on generic Content Hub field fusion.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Tourism_Field_Policy_V1 {
    public static function boot(): void {
        if(!class_exists('MAD4B_Hub_Field_Fusion_V1'))return;
        foreach([
            ['tours-and-activities','tour_mode','manual','text',70],
            ['tours-and-activities','tour_type','manual','text',60],
            ['transportations','trans_route_estimated_duration','freshest','text',45],
            ['transportations','trans_train_operator','manual','text',80],
        ] as $row) {
            MAD4B_Hub_Field_Fusion_V1::register_field($row[0],$row[1],[
                'strategy'=>$row[2],'value_type'=>$row[3],
                'weight'=>$row[4],'localized'=>false,'sensitive'=>false,
            ]);
        }
    }
}
add_action('init',['MAD4B_Tourism_Field_Policy_V1','boot'],35);
