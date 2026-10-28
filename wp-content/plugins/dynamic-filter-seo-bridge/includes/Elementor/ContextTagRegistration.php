<?php
namespace DynamicFilterSEOBridge\Elementor;

use DynamicFilterSEOBridge\Elementor\DynamicTags\ContextValueTag;
use DynamicFilterSEOBridge\Elementor\DynamicTags\ContextImageTag;
use DynamicFilterSEOBridge\Runtime\BootGuard;

/** Safe opt-in tag; no public source output until full boot certification. */
final class ContextTagRegistration {
    public function registerHooks(): void {
        add_action('elementor/dynamic_tags/register',array($this,'register'),21);
        if(did_action('elementor/dynamic_tags/register') && class_exists('Elementor\Plugin')){
            $plugin=\Elementor\Plugin::$instance;
            if($plugin && isset($plugin->dynamic_tags))$this->register($plugin->dynamic_tags);
        }
    }
    public function register($manager): void {
        if(BootGuard::shouldHold() && !is_admin() && !current_user_can('manage_options'))return;
        if(!class_exists('Elementor\Core\DynamicTags\Tag'))return;
        if(method_exists($manager,'register_group')){
            $manager->register_group('dfsb-context',array('title'=>'Context Sources'));
        }
        if(method_exists($manager,'register')){
            $manager->register(new ContextValueTag());
            if(class_exists('Elementor\Core\DynamicTags\Data_Tag'))$manager->register(new ContextImageTag());
        }elseif(method_exists($manager,'register_tag')){
            $manager->register_tag(ContextValueTag::class);
            if(class_exists('Elementor\Core\DynamicTags\Data_Tag'))$manager->register_tag(ContextImageTag::class);
        }
    }
}
