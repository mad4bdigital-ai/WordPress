<?php
defined('ABSPATH')||exit;
if(!class_exists('\\Elementor\\Includes\\Elements\\Container'))return;
if(!class_exists('MAD4B_Mixed_Family_Container_V1',false)) {
 class MAD4B_Mixed_Family_Container_V1 extends \Elementor\Includes\Elements\Container {
   protected function print_content() {
     $native=function():string {
       ob_start();
       parent::print_content();
       return (string)ob_get_clean();
     };
     echo MAD4B_Mixed_Family_Public_Islands_V1::render($this,$native);
   }
 }
}
