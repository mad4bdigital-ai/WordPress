<?php
/**
 * Plugin Name: MAD4B Central Owner Staging Manager Review
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
        add_management_page('MAD4B Staging Owner Review','MAD4B Staging Owner Review',
            'manage_options','mad4b-staging-owner-review',[__CLASS__,'render']);
    }
    public static function render():void {
        if(!self::permitted())wp_die('Original enrolled WordPress owner required',403);
        $companies=[];
        $all=MAD4B_Company_Environment_Registry_V2::discover();
        foreach($all as $company=>$entry) {
            if(!is_string($company)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
                ||!isset($entry['environments']['staging']))continue;
            // The Central controller manages others; do not delegate itself.
            if($company==='tours-central')continue;
            $companies[]=$company;
        }
        sort($companies,SORT_STRING);
        $source=['companies'=>$companies,'nonce'=>wp_create_nonce('wp_rest'),
            'base'=>esc_url_raw(rest_url('mad4b-central/v1/company-environments/'))];
        echo '<div class="wrap"><h1>MAD4B Staging — Original Owner Review</h1>';
        echo '<p>Approve the exact registered manager per Staging company. ';
        echo 'This does not issue 305 grants or authorize Production.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Original plan</th><th>Owner action</th></tr></thead><tbody>';
        foreach($companies as $company) {
            echo '<tr data-company="'.esc_attr($company).'"><td>'.esc_html($company).
                '</td><td class="mad4b-state">Reading original plan…</td><td>'.
                '<button class="button button-primary mad4b-approve" disabled>Approve and activate Staging manager</button></td></tr>';
        }
        echo '</tbody></table><p><strong>Original authorization:</strong> WordPress login + REST nonce. ';
        echo 'Native executor and full SSH/SQL grants remain separately governed.</p></div>';
        ?>
        <script>
        (() => {
          const cfg = <?php echo wp_json_encode($source, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
          const rows = [...document.querySelectorAll('tr[data-company]')];
          async function request(company,method,body) {
            if (!cfg.companies.includes(company)) throw new Error('Company not enrolled in Staging');
            const url = cfg.base + encodeURIComponent(company) + '/staging/manager-assignment';
            const res = await fetch(url,{method,credentials:'same-origin',
              headers:{'X-WP-Nonce':cfg.nonce,'Accept':'application/json',
                ...(body?{'Content-Type':'application/json'}:{})},
              ...(body?{body:JSON.stringify(body)}:{})});
            const data=await res.json();
            if(!res.ok || !data || data.code) throw new Error(data.message||data.code||'Original REST rejected');
            return data;
          }
          function admissible(p,company){
            return p.contract==='mad4b.environment-manager-assignment.v1' &&
              p.company===company && p.environment==='staging' && p.eligible===true &&
              typeof p.binding_sha256==='string' && /^[a-f0-9]{64}$/.test(p.binding_sha256) &&
              typeof p.site_uuid==='string' && typeof p.revision==='number' &&
              p.remote_write_authorized===undefined;
          }
          async function refresh(row){
            const company=row.dataset.company;
            const label=row.querySelector('.mad4b-state');
            const button=row.querySelector('.mad4b-approve');
            try {
              const p=await request(company,'GET');
              label.textContent=String(p.assignment_state||p.next_action||'unknown');
              button.disabled=!admissible(p,company)||p.assignment_state==='active';
            } catch(e) {label.textContent='Original REST rejected: '+e.message;button.disabled=true;}
          }
          async function authorize(row){
            const company=row.dataset.company;
            const button=row.querySelector('.mad4b-approve');
            const label=row.querySelector('.mad4b-state');
            button.disabled=true;
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
              label.textContent='Manager assignment active; 305 remains separate';
            } catch(e) {label.textContent='Stopped: '+e.message;}
            await refresh(row);
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
