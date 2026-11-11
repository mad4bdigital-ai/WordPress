<?php
/** QA ONLY: copy as a temporary MU plugin on an isolated staging instance. */
defined('ABSPATH') || exit;
add_action('wp_abilities_api_init',static function(){
    wp_register_ability('mad4b/qa-runtime-input-fixture',array(
        'label'=>'MAD4B Fleet Intake QA',
        'description'=>'Synthetic nonsecret typed form acceptance fixture',
        'category'=>'mad4b-fleet-intake',
        'execute_callback'=>static function($in){return array('executed'=>false);},
        'permission_callback'=>static function(){return current_user_can('manage_options');},
        'input_schema'=>array('type'=>'object','properties'=>array(
            'tour_name'=>array('type'=>'string','minLength'=>2,'maxLength'=>80),
            'travellers'=>array('type'=>'integer','minimum'=>1,'maximum'=>20),
            'confirmed'=>array('type'=>'boolean'),
            'travel_date'=>array('type'=>'string','format'=>'date'),
            'rooming'=>array('type'=>'array','minItems'=>1,'maxItems'=>3,
                'items'=>array('type'=>'object','properties'=>array(
                    'room_type'=>array('type'=>'string','enum'=>array('single','double','triple')),
                    'guests'=>array('type'=>'integer','minimum'=>1)),
                    'required'=>array('room_type','guests'),'additionalProperties'=>false)),
            'metadata'=>array('type'=>'object','properties'=>array(
                'source'=>array('type'=>'string')),'additionalProperties'=>false),
        ),'required'=>array('tour_name','travellers','rooming'),
          'additionalProperties'=>false),
        'output_schema'=>array('type'=>'object','additionalProperties'=>true),
        'meta'=>array('show_in_rest'=>false),
    ));
},40);
