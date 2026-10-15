<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit read-only MCP mount for ACI01's three governed abilities. */
final class MAD4B_SCP_ACI01_Read_Adapter extends MAD4B_SCP_Adapter_Base {
    public function id() { return 'aci01-content-intelligence'; }
    public function label() { return 'ACI01 Content Intelligence Read Previews'; }
    public function is_available() {
        return class_exists( 'MAD4B_SCP_ACI01_Intake_Preview', false )
            && class_exists( 'MAD4B_SCP_ACI01_Evidence_Preview', false )
            && class_exists( 'MAD4B_SCP_ACI01_Opportunity_Preview', false )
            && class_exists( 'MAD4B_SCP_ACI01_Native_Relation_Audit', false )
            && class_exists( 'MAD4B_SCP_ACI01_Runtime_Binding', false )
            && class_exists( 'MAD4B_SCP_ACI01_Semantic_Recipe', false );
    }
    public function ability_names() {
        return array(
            'read' => array(
                'mad4b/aci01-intake-preview',
                'mad4b/aci01-evidence-preview',
                'mad4b/aci01-opportunity-preview',
                'mad4b/aci01-native-relation-audit',
            ),
            'content' => array(), 'write' => array(), 'admin' => array(),
        );
    }
    public function register_abilities() {
        if ( ! $this->is_available() ) return;
        MAD4B_SCP_ACI01_Intake_Preview::register_ability();
        MAD4B_SCP_ACI01_Evidence_Preview::register_ability();
        MAD4B_SCP_ACI01_Opportunity_Preview::register_ability();
        MAD4B_SCP_ACI01_Native_Relation_Audit::register_ability();
    }
    protected function mutation_requires_certification() { return false; }
    protected function provider_certification( $available ) { return null; }
    public function reversible_contracts() { return array(); }
}
