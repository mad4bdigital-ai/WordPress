import test from 'node:test';
import assert from 'node:assert/strict';
import {promises as fs} from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {build,convertProfile,convertAdmin} from '../executor/build_rc119_isolation_candidate.mjs';

const main=`<?php
/*
Plugin Name: MAD4B Site Control Plane
 * Version: 0.4.0-rc.118
*/
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.118' );
`;
const profile=`<?php
class MAD4B_SCP_Site_Profile {
    public static function provider_isolation_enabled() { return self::feature_enabled( 'provider_isolation' ); }
    public static function feature_enabled($feature) {
        $profile = self::profile();
        $feature = sanitize_key( (string) $feature );
        if ( ! self::origin_enrolled() || empty( $profile['features'] ) || ! is_array( $profile['features'] ) ) return false;
    }
    public static function save_current_site() {
        $record = array(
            'contract' => self::CONTRACT,
            'features' => array('provider_isolation' => ! empty( $input['provider_isolation_enabled'] )),
        );
    }
    public static function migrate_legacy() {
      $record=array('features' => array( 'oauth' => false, 'skills' => false, 'write' => false, 'production_write_confirmed' => false, 'provider_isolation' => false, 'managed_runtime' => false, 'acceptance' => false ),);
    }
    public static function build_status() {
        return array(
            'write_enabled' => $authority_ready && self::record_feature_enabled( $profile, 'write', $environment ),
        );
    }
    public static function valid_record_shape() {
        foreach ( array( 'legacy_zero_touch', 'implicit_production_override_confirmed', 'migration_requires_reenrollment' ) as $field ) {}
    }
}
`;
const admin=`<?php
class MAD4B_SCP_Site_Profile_Admin {
    public static function input() {
       return array('provider_isolation_enabled' => ! empty( $features['provider_isolation'] ),);
    }
    public static function view() {
       <?php self::checkbox( 'provider_isolation_enabled', 'Provider MCP isolation', ! empty( $features['provider_isolation'] ) ); ?>
    }
    public static function readback() {
       foreach ( array('provider_isolation' => 'provider_isolation_enabled') as $feature => $input_key ) {
           if ( (bool) ! empty( $input[ $input_key ] ) !== (bool) ! empty( $features[ $feature ] ) ) return false;
       }
    }
}
`;
async function fixture() {
 const t=await fs.mkdtemp(path.join(os.tmpdir(),'mad4b-central-release-test-'));
 const root=path.join(t,'mad4b-site-control-plane');
 const quarantine=path.join(t,'release-quarantine');
 await fs.mkdir(path.join(root,'includes'),{recursive:true});
 await fs.mkdir(quarantine);
 await fs.writeFile(path.join(root,'mad4b-site-control-plane.php'),main);
 await fs.writeFile(path.join(root,'includes/class-mad4b-scp-site-profile.php'),profile);
 await fs.writeFile(path.join(root,'includes/class-mad4b-scp-site-profile-admin.php'),admin);
 await fs.writeFile(path.join(root,'unrelated.php'),'<?php // keep exactly\n');
 return {t,root,quarantine,out:path.join(quarantine,'policy-candidate')};
}
test('bounded rc118 overlay only changes three intended files and does not touch source',async()=>{
 const f=await fixture();
 try {
 const original=await fs.readFile(path.join(f.root,'mad4b-site-control-plane.php'),'utf8');
 const manifest=await build(f.root,f.out);
 assert.equal(manifest.release_state,'STAGED_UNVERIFIED_NOT_DEPLOYABLE');
 assert.deepEqual(manifest.changes,['mad4b-site-control-plane.php','includes/class-mad4b-scp-site-profile.php','includes/class-mad4b-scp-site-profile-admin.php']);
 assert.equal(await fs.readFile(path.join(f.root,'mad4b-site-control-plane.php'),'utf8'),original);
 assert.match(await fs.readFile(path.join(f.out,'mad4b-site-control-plane/mad4b-site-control-plane.php'),'utf8'),/rc.119/);
 assert.equal(await fs.readFile(path.join(f.out,'mad4b-site-control-plane/unrelated.php'),'utf8'),'<?php // keep exactly\n');
 assert.equal(manifest.file_count,4);
 }finally{await fs.rm(f.t,{recursive:true,force:true})}
});
test('source drift and old source fail closed before building anything',async()=>{
 const f=await fixture();
 try{
 await fs.writeFile(path.join(f.root,'mad4b-site-control-plane.php'),main.replaceAll('0.4.0-rc.118','0.4.0-rc.104'));
 await assert.rejects(()=>build(f.root,f.out),/RC118_BASE_VERSION_MISMATCH/);
 await fs.writeFile(path.join(f.root,'mad4b-site-control-plane.php'),main);
 await fs.writeFile(path.join(f.root,'includes/class-mad4b-scp-site-profile.php'),profile.replace("public static function provider_isolation_enabled() { return self::feature_enabled( 'provider_isolation' ); }",''));
 await assert.rejects(()=>build(f.root,f.out),/RC118_ANCHOR_DRIFT/);
 }finally{await fs.rm(f.t,{recursive:true,force:true})}
});
test('never stage outside quarantine or over an existing release',async()=>{
 const f=await fixture();
 try{
 await assert.rejects(()=>build(f.root,path.join(f.t,'dangerous')),/DESTINATION_NOT_QUARANTINE/);
 await fs.mkdir(f.out);
 await assert.rejects(()=>build(f.root,f.out),/CANDIDATE_ALREADY_EXISTS/);
 }finally{await fs.rm(f.t,{recursive:true,force:true})}
});
test('source symlink is rejected',async()=>{
 const f=await fixture();
 try{
 await fs.symlink(path.join(f.t,'outside'),path.join(f.root,'foreign-symlink'));
 await assert.rejects(()=>build(f.root,f.out),/SOURCE_UNSAFE_FILE_TYPE/);
 }finally{await fs.rm(f.t,{recursive:true,force:true})}
});
test('pure patch fails closed on duplicate or missing anchors',()=>{
 assert.throws(()=>convertProfile(profile+profile),/RC118_ANCHOR_DRIFT/);
 assert.throws(()=>convertAdmin(admin.replace('Provider MCP isolation','Provider X')),/RC118_ANCHOR_DRIFT/);
});
