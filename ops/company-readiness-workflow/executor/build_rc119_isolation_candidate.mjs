#!/usr/bin/env node
/** Build a bounded, non-deployable rc.119 isolation-only candidate from
 * the ACTUAL installed rc.118 plugin. The builder never edits the source,
 * WordPress database, site profiles, users, or Production. It does not
 * authorize distribution. The central governed release executor owns that.
 *
 * Usage: node build_rc119_isolation_candidate.mjs <source-plugin-dir> <new-quarantine-dir>
 */
import {promises as fs} from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const SOURCE_VERSION='0.4.0-rc.118';
const TARGET_VERSION='0.4.0-rc.119';
const MAIN='mad4b-site-control-plane.php';
const P='includes/class-mad4b-scp-site-profile.php';
const A='includes/class-mad4b-scp-site-profile-admin.php';
const h=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');

function replaceOne(content,before,after,tag) {
 const parts=content.split(before);
 if(parts.length!==2)throw Error('RC118_ANCHOR_DRIFT:'+tag);
 return parts.join(after);
}
function convertProfile(s) {
 s=replaceOne(s,
 "public static function provider_isolation_enabled() { return self::feature_enabled( 'provider_isolation' ); }",
 `public static function provider_isolation_enabled() {
        // Unmarked legacy OFF is an old default, not a verified opt-out.
        if (!self::origin_enrolled()) return false;
        return !self::provider_isolation_explicit_opt_out();
    }
    public static function provider_isolation_explicit_opt_out() {
        $p=self::profile();
        return true === ($p['provider_isolation_explicit_opt_out'] ?? false)
            && empty($p['features']['provider_isolation']);
    }`,'effective-policy');
 s=replaceOne(s,
 "$feature = sanitize_key( (string) $feature );\n\t\tif ( ! self::origin_enrolled() || empty( $profile['features'] )",
 "$feature = sanitize_key( (string) $feature );\n\t\tif ( 'provider_isolation' === $feature ) return self::provider_isolation_enabled();\n\t\tif ( ! self::origin_enrolled() || empty( $profile['features'] )",
 'generic-feature');
 s=replaceOne(s,
 "\t\t$record = array(\n\t\t\t'contract' => self::CONTRACT,",
 `        $provider_isolation_explicit_opt_out = array_key_exists('provider_isolation_enabled', $input)
            ? false === (bool)$input['provider_isolation_enabled']
            : ($existing_identity_matches
                && true === ($existing_normalized['provider_isolation_explicit_opt_out'] ?? false)
                && empty($existing_normalized['features']['provider_isolation']));
        $record = array(
            'provider_isolation_explicit_opt_out' => (bool)$provider_isolation_explicit_opt_out,
            'contract' => self::CONTRACT,`,
 'record-provenance');
 s=replaceOne(s,
 "'provider_isolation' => ! empty( $input['provider_isolation_enabled'] ),",
 "'provider_isolation' => ! $provider_isolation_explicit_opt_out,",
 'default-on');
 s=replaceOne(s,
 "'provider_isolation' => false, 'managed_runtime'",
 "'provider_isolation' => true, 'managed_runtime'",
 'legacy-migration');
 s=replaceOne(s,
 "'legacy_zero_touch', 'implicit_production_override_confirmed', 'migration_requires_reenrollment'",
 "'legacy_zero_touch', 'implicit_production_override_confirmed', 'migration_requires_reenrollment', 'provider_isolation_explicit_opt_out'",
 'validate-provenance');
 s=replaceOne(s,
 "\t\t\t'write_enabled' => $authority_ready && self::record_feature_enabled( $profile, 'write', $environment ),",
 `            'provider_isolation_policy' => 'default_on_explicit_opt_out_only',
            'provider_isolation_explicit_opt_out' => $configured
                && true === ($profile['provider_isolation_explicit_opt_out'] ?? false)
                && empty($profile['features']['provider_isolation']),
            'provider_isolation_effective' => $authority_ready
                && !(true === ($profile['provider_isolation_explicit_opt_out'] ?? false)
                    && empty($profile['features']['provider_isolation'])),
            'provider_isolation_legacy_default_pending_persist' => $configured
                && empty($profile['features']['provider_isolation'])
                && true !== ($profile['provider_isolation_explicit_opt_out'] ?? false),
            'write_enabled' => $authority_ready && self::record_feature_enabled( $profile, 'write', $environment ),`,
 'readonly-policy-status');
 return s;
}
function convertAdmin(s) {
 s=replaceOne(s,
 "'provider_isolation_enabled' => ! empty( $features['provider_isolation'] ),",
 "'provider_isolation_enabled' => MAD4B_SCP_Site_Profile::provider_isolation_enabled(),",
 'autopilot-effective');
 s=replaceOne(s,
 "<?php self::checkbox( 'provider_isolation_enabled', 'Provider MCP isolation', ! empty( $features['provider_isolation'] ) ); ?>",
 "<?php self::checkbox( 'provider_isolation_enabled', 'Provider MCP isolation (default ON)', empty( $status['configured'] ) ? true : MAD4B_SCP_Site_Profile::provider_isolation_enabled() ); ?>",
 'admin-default-on');
 s=replaceOne(s,
 ") as $feature => $input_key ) {\n\t\t\tif ( (bool) ! empty( $input[ $input_key ] )",
 `) as $feature => $input_key ) {
            if ('provider_isolation' === $feature && ! array_key_exists($input_key,$input)) {
                $opt_out = true === ($profile['provider_isolation_explicit_opt_out'] ?? false);
                if ((bool)!empty($features[$feature]) === $opt_out) return false;
                continue;
            }
            if ( (bool) ! empty( $input[ $input_key ] )`,
 'readback-omission');
 return s;
}
async function inspectTree(root) {
 const files=[];
 async function scan(dir) {
  for(const e of await fs.readdir(dir,{withFileTypes:true})){
   const full=path.join(dir,e.name);
   const st=await fs.lstat(full);
   if(st.isSymbolicLink()||(!st.isFile()&&!st.isDirectory()))throw Error('SOURCE_UNSAFE_FILE_TYPE:'+e.name);
   if(st.isDirectory())await scan(full);
   else files.push(path.relative(root,full));
  }
 }
 await scan(root);return files.sort();
}
async function build(source,destination){
 const real=await fs.realpath(source);
 if(path.basename(real)!=='mad4b-site-control-plane')throw Error('SOURCE_PLUGIN_IDENTITY');
 const target=path.resolve(destination);
 if(target===real||target.startsWith(real+path.sep)||real.startsWith(target+path.sep))throw Error('SOURCE_DESTINATION_OVERLAP');
 if(!target.includes(path.sep+'release-quarantine'+path.sep))throw Error('DESTINATION_NOT_QUARANTINE');
 try{await fs.lstat(target);throw Error('CANDIDATE_ALREADY_EXISTS')}catch(e){if(e.code!=='ENOENT')throw e}
 const names=await inspectTree(real);
 for(const n of [MAIN,P,A])if(!names.includes(n))throw Error('REQUIRED_FILE_MISSING:'+n);
 const sourceText={};for(const n of [MAIN,P,A])sourceText[n]=await fs.readFile(path.join(real,n),'utf8');
 if(!sourceText[MAIN].includes('Version: '+SOURCE_VERSION)
   ||!sourceText[MAIN].includes("define( 'MAD4B_SCP_VERSION', '"+SOURCE_VERSION+"' )"))
   throw Error('RC118_BASE_VERSION_MISMATCH');
 const changed={
  [MAIN]:replaceOne(replaceOne(sourceText[MAIN],'Version: '+SOURCE_VERSION,'Version: '+TARGET_VERSION,'header'),
    "define( 'MAD4B_SCP_VERSION', '"+SOURCE_VERSION+"' )",
    "define( 'MAD4B_SCP_VERSION', '"+TARGET_VERSION+"' )",'runtime-version'),
  [P]:convertProfile(sourceText[P]),
  [A]:convertAdmin(sourceText[A]),
 };
 // Validate all anchors before creating a destination.
 await fs.mkdir(target,{recursive:false});
 const plugin=path.join(target,'mad4b-site-control-plane');
 await fs.cp(real,plugin,{recursive:true,force:false,errorOnExist:true});
 for(const [n,data]of Object.entries(changed))await fs.writeFile(path.join(plugin,n),data);
 const all=await inspectTree(plugin);
 if(JSON.stringify(names)!==JSON.stringify(all))throw Error('FILE_INVENTORY_CHANGED');
 const files={};for(const n of all)files[n]=h(await fs.readFile(path.join(plugin,n)));
 const preimages={};for(const n of [MAIN,P,A])preimages[n]=h(sourceText[n]);
 const allowed=new Set([MAIN,P,A]);
 for(const n of all)if(!allowed.has(n)&&h(await fs.readFile(path.join(real,n)))!==files[n])throw Error('UNRELATED_SOURCE_CHANGED');
 const manifest={
  contract:'mad4b.rc118-cumulative-isolation-candidate.v1',
  release_state:'STAGED_UNVERIFIED_NOT_DEPLOYABLE',
  baseline_version:SOURCE_VERSION,target_version:TARGET_VERSION,
  baseline_critical_file_sha256:preimages,
  candidate_critical_file_sha256:Object.fromEntries([MAIN,P,A].map(n=>[n,files[n]])),
  candidate_tree_sha256:h(JSON.stringify(files)),
  changes:Object.keys(changed),
  file_count:all.length,
  source_unchanged:true,
  production_mutation:false,
  live_staging_mutation:false,
  staging_test_and_authorized_release_required:true,
  note:'This candidate requires php-lint, isolated WP runtime tests, exact site binding and a governed release ticket before installation.'
 };
 await fs.writeFile(path.join(target,'manifest.json'),JSON.stringify(manifest,null,2)+'\n',{flag:'wx'});
 return manifest;
}
if(process.argv[1]&&import.meta.url===new URL('file://'+path.resolve(process.argv[1])).href) {
 if(process.argv.length!==4)throw Error('USAGE: node build_rc119_isolation_candidate.mjs SOURCE_PLUGIN_DIR NEW_RELEASE_QUARANTINE_DIR');
 console.log(JSON.stringify(await build(process.argv[2],process.argv[3]),null,2));
}
export {build,convertProfile,convertAdmin,replaceOne};
