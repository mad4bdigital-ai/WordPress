<?php
/**
 * Plugin Name: MAD4B Central Multi-Environment Owner Review
 * Description: Original REST owner authorization UI; no new issuer, roles, grants, or remote execution.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Central_Owner_Manager_Review_V1',false)){
final class MAD4B_Central_Owner_Manager_Review_V1 {
    private static function central(): bool {
        return function_exists('wp_get_environment_type')
            && wp_get_environment_type()==='staging'
            && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com'
            && class_exists('MAD4B_Manager_Auto_Write_V1',false)
            && class_exists('MAD4B_Company_Environment_Registry_V2',false);
    }
    private static function permitted(): bool {
        return self::central() && current_user_can('manage_options')
            && current_user_can('activate_plugins')
            && get_current_user_id()===MAD4B_Manager_Auto_Write_V1::enrolled_owner();
    }
    public static function menu():void {
        if(!self::permitted())return;
        add_management_page('MAD4B Company Environment Review','MAD4B Company Environments',
            'manage_options','mad4b-staging-owner-review',[__CLASS__,'render']);
    }
    public static function render():void {
        if(!self::permitted())wp_die('Original enrolled WordPress owner required',403);
        $staging=[];
        $production=[];
        $controllers=[];
        $all=MAD4B_Company_Environment_Registry_V2::discover();
        foreach($all as $company=>$entry) {
            if(!is_string($company)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
                ||!is_array($entry)||!is_array($entry['environments']??null))continue;
            foreach($entry['environments'] as $env=>$target) {
                if(!in_array($env,['staging','production'],true)||!is_array($target))continue;
                $item=['company'=>$company,'origin'=>(string)($target['canonical_origin']??''),
                    'site_uuid'=>(string)($target['site_uuid']??'')];
                if($company==='tours-central' && $env==='staging') {
                    $controllers[]=$item;
                    continue;
                }
                if($env==='staging')$staging[]=$item;
                else {
                    $evidence=is_array($target['identity_evidence']??null)
                        ?$target['identity_evidence']:[];
                    $verified=($target['authorized_connections']['signed_identity']['centrally_verified']??false)===true;
                    $uuid_valid=wp_is_uuid($item['site_uuid']);
                    $item['verified']=$verified;
                    $item['state']=!$uuid_valid?'Site UUID / original Production enrollment missing'
                        :($verified?'Original identity verified — separate Production approval required'
                        :'Exact Production identity / environment binding pending');
                    $production[]=$item;
                }
            }
        }
        usort($staging,static fn($a,$b)=>strcmp($a['company'],$b['company']));
        usort($production,static fn($a,$b)=>strcmp($a['company'],$b['company']));
        $source=['companies'=>array_column($staging,'company'),
            'nonce'=>wp_create_nonce('wp_rest'),
            'base'=>esc_url_raw(rest_url('mad4b-central/v1/company-environments/'))];
        echo '<div class="wrap mad4b-owner-environments"><h1>MAD4B — Company & Environment Authority</h1>';
        echo '<p>All registered environments are visible. Owner approval is available only where an original authorized issuer exists. No 305 grants are created by this page.</p>';
        echo '<style>
            .mad4b-owner-environments .widefat{table-layout:fixed;max-width:1200px}
            .mad4b-owner-environments th:nth-child(1){width:20%}
            .mad4b-owner-environments th:nth-child(2){width:19%}
            .mad4b-owner-environments th:nth-child(3){width:36%}
            .mad4b-owner-environments th:nth-child(4){width:25%}
            .mad4b-owner-environments td{overflow-wrap:anywhere;vertical-align:middle;padding:10px}
            .mad4b-owner-environments button{white-space:normal;height:auto;min-height:32px}
            .mad4b-owner-environments .mad4b-state-error{color:#b32d2e;font-weight:600}
            .mad4b-owner-environments code{font-size:12px}
            @media(max-width:782px){.mad4b-owner-environments .widefat{table-layout:auto}
            .mad4b-owner-environments td,.mad4b-owner-environments th{padding:8px 5px}
            .mad4b-owner-environments .button{padding:4px 7px}}
        </style>';
        echo '<h2>Staging ('.count($staging).')</h2><p>Original owner-authenticated manager approval. Site identity is independently signed and checked.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Environment</th><th>Original plan / errors</th><th>Owner action</th></tr></thead><tbody>';
        foreach($staging as $item) {
            echo '<tr data-company="'.esc_attr($item['company']).'" data-environment="staging"><td><strong>'.
                esc_html($item['company']).'</strong></td><td>Staging</td>'.
                '<td class="mad4b-state" role="status" aria-live="polite">Reading original plan…</td><td>'.
                '<button type="button" class="button button-primary mad4b-approve" disabled>Approve and activate manager</button></td></tr>';
        }
        if(!$staging)echo '<tr><td colspan="4">No Staging company targets enrolled.</td></tr>';
        echo '</tbody></table>';
        echo '<h2>Production ('.count($production).')</h2><p>Production appears here for original registration and readiness review. An active Production grant is not implied by SSH access or Staging approval.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Environment</th><th>Original identity / binding</th><th>Next action</th></tr></thead><tbody>';
        foreach($production as $item) {
            echo '<tr data-environment="production"><td><strong>'.esc_html($item['company']).
                '</strong></td><td>Production</td><td>'.
                esc_html($item['state']).'<p><small>'.esc_html($item['origin']).'</small></p></td><td>'.
                '<a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-company-portfolio-managers')).
                '">Review original company portfolio</a>'.
                '<p><small>Separate original Production authority required</small></p></td></tr>';
        }
        if(!$production)echo '<tr><td colspan="4">No Production targets enrolled.</td></tr>';
        echo '</tbody></table>';
        if($controllers){
            echo '<h2>Central controller</h2><p>';
            foreach($controllers as $item)echo '<strong>'.esc_html($item['company']).
                '</strong> — '.esc_html($item['origin']).' (original native host, not a remote company grant)';
            echo '</p>';
        }
        echo '<p><strong>Authorization:</strong> original WordPress owner + REST nonce for Staging; '.
            'Production requires independently enrolled Site Profile, trusted identity and original Production issuer. '.
            'Native 305 and host-scoped SSH/SQL grants remain separate.</p></div>';
        ?>
        <script>
        (() => {
          const cfg = <?php echo wp_json_encode($source, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
          const rows = [...document.querySelectorAll('tr[data-company][data-environment="staging"]')];
          async function request(company,method,body) {
            if (!cfg.companies.includes(company)) throw new Error('Company not enrolled in Staging');
            const url = cfg.base + encodeURIComponent(company) + '/staging/manager-assignment';
            const res = await fetch(url,{method,credentials:'same-origin',
              headers:{'X-WP-Nonce':cfg.nonce,'Accept':'application/json',
                ...(body?{'Content-Type':'application/json'}:{})},
              ...(body?{body:JSON.stringify(body)}:{})});
            const contentType=res.headers.get('content-type')||'';
            let data;
            if(contentType.includes('application/json')) {
              try { data=await res.json(); } catch(e) { throw new Error('REST returned invalid JSON'); }
            } else {
              throw new Error('Original REST did not return JSON (HTTP '+res.status+')');
            }
            if(!res.ok || !data || data.code) {
              const message=data?.message||data?.code||'Original REST rejected';
              if(res.status===423) {
                const code=String(data?.code||'');
                const cause=code==='mad4b_environment_manager_assignment_locked'
                  ?'قفل تعيين المدير مشغول مؤقتًا.'
                  :code==='mad4b_runtime_rest_environment_unverified'
                  ?'بوابة MAD4B لم تتحقق من ربط بيئة الموقع. ليست مشكلة قفل SQL.'
                  :'تم رفض الطلب بواسطة بوابة MAD4B.';
                const error=new Error(cause+' (HTTP 423): '+message);
                error.status=423;
                throw error;
              }
              throw new Error('HTTP '+res.status+': '+message);
            }
            return data;
          }
          function admissible(p,company){
            return p.contract==='mad4b.environment-manager-assignment.v1' &&
              p.company===company && p.environment==='staging' && p.eligible===true &&
              typeof p.binding_sha256==='string' && /^[a-f0-9]{64}$/.test(p.binding_sha256) &&
              typeof p.site_uuid==='string' && typeof p.revision==='number' &&
              p.remote_write_authorized===undefined;
          }
          async function refresh(row,preserveError=false){
            const company=row.dataset.company;
            const label=row.querySelector('.mad4b-state');
            const button=row.querySelector('.mad4b-approve');
            try {
              const p=await request(company,'GET');
              if(!preserveError) label.textContent=String(p.assignment_state||p.next_action||'unknown');
              button.disabled=!admissible(p,company)||p.assignment_state==='active';
              button.textContent=p.assignment_state==='active'
                ?'Manager active' : preserveError
                ?'Retry Staging manager approval':'Approve and activate Staging manager';
            } catch(e) {
              if(!preserveError)label.textContent='Original REST rejected: '+e.message;
              button.disabled=true;
            }
          }
          async function authorize(row){
            const company=row.dataset.company;
            const button=row.querySelector('.mad4b-approve');
            const label=row.querySelector('.mad4b-state');
            if(row.dataset.inFlight==='true')return;
            row.dataset.inFlight='true';
            button.disabled=true;
            let outcome='error';
            try {
              for(let i=0;i<3;i++){
                const p=await request(company,'GET');
                if(!admissible(p,company))throw new Error('Original identity/binding invalid');
                if(p.assignment_state==='active')break;
                const action=({'missing':'propose','revoked':'propose',
                  'proposed':'approve','approved':'activate'})[p.assignment_state];
                if(!action)throw new Error('Unknown owner assignment state');
                label.textContent='Original owner REST: '+action+'…';
                const next=await request(company,'POST',{
                  action, expected_revision:p.revision,
                  expected_binding_sha256:p.binding_sha256,
                  expected_proposal_sha256:p.proposal_sha256||''});
                const expected={'propose':'proposed','approve':'approved','activate':'active'}[action];
                if(next.assignment_state!==expected||next.revision!==p.revision+1)
                  throw new Error('Independent owner readback mismatch');
              }
              const verified=await request(company,'GET');
              if(verified.assignment_state!=='active')throw new Error('Final original state is not active');
              label.textContent='Manager assignment active; 305 remains separate';
              outcome='active';
            } catch(e) {
              label.textContent='Stopped: '+e.message;
              row.dataset.lastFailure=String(e.message);
            } finally {
              row.dataset.inFlight='false';
              await refresh(row,outcome!=='active');
              if(outcome==='active') row.dataset.lastFailure='';
            }
          }
          for(const row of rows) {
            row.querySelector('.mad4b-approve').addEventListener('click',()=>authorize(row));
            refresh(row);
          }
        })();
        </script>
        <?php
    }
    public static function boot():void {
        if(!self::central())return;
        add_action('admin_menu',[__CLASS__,'menu'],100);
    }
}
MAD4B_Central_Owner_Manager_Review_V1::boot();
}
