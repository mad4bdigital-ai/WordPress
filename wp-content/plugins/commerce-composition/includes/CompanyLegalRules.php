<?php
/**
 * Tenant-local policy parameters and cancellation band presentation.
 * This module does not authorize charges, fee deduction, or refunds.
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Legal_Rules {
    const OPTIONS = 'mad4b-company-policy-library';
    public static function fields(): array {
        $fields = [
            ['name'=>'commerce_legal_rules_heading','title'=>'Company-specific commercial and legal controls',
                'type'=>'html','html'=>'<p>These settings belong to this company only. Cancellation bands are draft until the schedule and localized Cancellation policy are approved. No automatic refund or extra charge is performed here.</p>'],
            self::choice('commerce_cancellation_standard_status','Standard cancellation schedule approval',
                ['draft'=>'Draft / review only','approved'=>'Approved for company']),
            self::bandsField('commerce_cancellation_standard_bands','Standard cancellation charges by days before arrival'),
            self::choice('commerce_cancellation_peak_status','Peak cancellation schedule approval',
                ['draft'=>'Draft / review only','approved'=>'Approved for company']),
            self::bandsField('commerce_cancellation_peak_bands','Peak / special-season cancellation charges'),
            ['name'=>'commerce_peak_definition','title'=>'Peak / holiday applicability (company-specific; exact travel dates or tour override)','type'=>'textarea','description'=>'Narrative or season mapping. Blank means peak schedule is never selected automatically.'],
            self::choice('commerce_refund_method','Refund destination',['review'=>'Manual review','original_payment'=>'Original payment method','other_agreed'=>'Separately agreed method']),
            self::choice('commerce_refund_fee_mode','Payment processing fee treatment',['review'=>'Review documented actual fees','none'=>'No deduction','actual_nonrecoverable'=>'Actual documented nonrecoverable fees','percent'=>'Company-approved percentage']),
            ['name'=>'commerce_refund_fee_percent','title'=>'Refund fee percentage (only when approved and permitted)','type'=>'number','description'=>'0–100. Never deducted automatically by this module.'],
            self::choice('commerce_supplier_fees','Supplier cancellation charges',['review'=>'Case-by-case review','documented_actual'=>'Documented actual airline/hotel/cruise fees','none'=>'No extra supplier fees']),
            self::choice('commerce_amendment_mode','Changes to confirmed services',['review'=>'Manual review','availability'=>'Subject to availability and quoted costs','free_if_available'=>'Without admin charge subject to availability']),
            self::choice('commerce_no_show_mode','No-show treatment',['review'=>'Manual review','tiered'=>'Use approved cancellation schedule','fixed_percent'=>'Use company-defined percentage']),
            ['name'=>'commerce_no_show_percent','title'=>'No-show charge percentage (when fixed)','type'=>'number','description'=>'0–100; of agreed tour price. No automatic application.'],
            self::choice('commerce_force_majeure_mode','Force majeure / travel warning',['review'=>'Manual case assessment','refund_or_credit'=>'Offer approved refund or future travel credit','reschedule'=>'Reschedule subject to availability']),
            self::choice('commerce_short_notice_mode','Arrival sooner than balance deadline',['review'=>'Individual payment schedule review','full_on_confirmation'=>'Full verified amount due now (100%) for arrivals inside the balance deadline','case_by_case'=>'Case-by-case alternative payment timetable']),
            ['name'=>'commerce_cancellation_contact','title'=>'Written cancellation contact / route','type'=>'text','description'=>'Company-controlled contact channel. Do not copy from another business.'],
            ['name'=>'commerce_legal_rules_revision','title'=>'Company legal rules revision','type'=>'text','description'=>'Internal revision ID; distinct from approval of public localized policy text.'],
        ];
        $n=940100;
        foreach($fields as &$f) {$f+=['object_type'=>'field','width'=>'100%','id'=>$n++];}
        unset($f);
        return $fields;
    }
    private static function choice(string $name,string $title,array $values):array {
        return ['name'=>$name,'title'=>$title,'type'=>'select','options_source'=>'manual',
            'options'=>array_map(static fn($k,$v)=>['key'=>$k,'value'=>$v],array_keys($values),array_values($values))];
    }
    private static function bandsField(string $name,string $title):array {
        return ['name'=>$name,'title'=>$title,'type'=>'repeater',
            'description'=>'Each row: minimum and maximum whole days BEFORE arrival (inclusive), then percentage of agreed total. Rows must not overlap. No show or day 0 needs an explicit rule.',
            'repeater-fields'=>[
                ['name'=>'min_days','title'=>'Minimum days before arrival','type'=>'number','object_type'=>'field','id'=>940201,'width'=>'33.33%'],
                ['name'=>'max_days','title'=>'Maximum days before arrival','type'=>'number','object_type'=>'field','id'=>940202,'width'=>'33.33%'],
                ['name'=>'fee_percent','title'=>'Cancellation charge %','type'=>'number','object_type'=>'field','id'=>940203,'width'=>'33.33%'],
            ]];
    }
    public static function values():array {
        $o=get_option(self::OPTIONS,[]);
        return is_array($o)?$o:[];
    }
    public static function bands(string $kind):array {
        $key='commerce_cancellation_'.$kind.'_bands';
        if(!in_array($kind,['standard','peak'],true))return ['valid'=>false,'rows'=>[],'reason'=>'unknown_kind'];
        $raw=self::values()[$key]??[];
        if(!is_array($raw)||count($raw)>32)return ['valid'=>false,'rows'=>[],'reason'=>'invalid_list'];
        $rows=[];
        foreach($raw as $r) {
            if(!is_array($r))return ['valid'=>false,'rows'=>[],'reason'=>'invalid_row'];
            foreach(['min_days','max_days','fee_percent'] as $k){
                if(!isset($r[$k])||!is_scalar($r[$k])||!is_numeric($r[$k]))return ['valid'=>false,'rows'=>[],'reason'=>'incomplete_row'];
            }
            $min=(float)$r['min_days'];$max=(float)$r['max_days'];$pct=(float)$r['fee_percent'];
            if(!is_finite($min)||!is_finite($max)||!is_finite($pct)||$min<0||$max>36500||$max<$min||$min!==(float)(int)$min||$max!==(float)(int)$max||$pct<0||$pct>100)
                return ['valid'=>false,'rows'=>[],'reason'=>'invalid_range_or_percentage'];
            $rows[]=['min_days'=>(int)$min,'max_days'=>(int)$max,'fee_percent'=>$pct];
        }
        if(!$rows)return ['valid'=>false,'rows'=>[],'reason'=>'empty'];
        usort($rows,static fn($a,$b)=>$a['min_days']<=>$b['min_days']);
        if($rows[0]['min_days']!==0)return ['valid'=>false,'rows'=>$rows,'reason'=>'day_zero_not_covered'];
        foreach($rows as $i=>$r)if($i>0 && $r['min_days']!==$rows[$i-1]['max_days']+1)
            return ['valid'=>false,'rows'=>$rows,'reason'=>'overlap_or_gap'];
        if(end($rows)['max_days']!==36500)return ['valid'=>false,'rows'=>$rows,'reason'=>'long_lead_days_not_covered'];
        return ['valid'=>true,'rows'=>$rows,'reason'=>''];
    }
    public static function snapshot():array {
        $v=self::values();$standard=self::bands('standard');$peak=self::bands('peak');
        return ['contract'=>'mad4b.company-legal-rules.v1',
            'revision'=>sanitize_text_field((string)($v['commerce_legal_rules_revision']??'')),
            'standard'=>['approved'=>($v['commerce_cancellation_standard_status']??'draft')==='approved'&&$standard['valid'],'bands'=>$standard],
            'peak'=>['approved'=>($v['commerce_cancellation_peak_status']??'draft')==='approved'&&$peak['valid'],'bands'=>$peak],
            'refund_method'=>sanitize_key((string)($v['commerce_refund_method']??'review')),
            'refund_fee_mode'=>sanitize_key((string)($v['commerce_refund_fee_mode']??'review')),
            'supplier_fees'=>sanitize_key((string)($v['commerce_supplier_fees']??'review')),
            'amendment_mode'=>sanitize_key((string)($v['commerce_amendment_mode']??'review')),
            'force_majeure_mode'=>sanitize_key((string)($v['commerce_force_majeure_mode']??'review')),
            'no_show_mode'=>sanitize_key((string)($v['commerce_no_show_mode']??'review')),
            'short_notice_mode'=>sanitize_key((string)($v['commerce_short_notice_mode']??'review')),
        ];
    }
    private static function schedule(string $kind):string {
        $v=self::values();
        $r=self::bands($kind);
        $approved=($v['commerce_cancellation_'.$kind.'_status']??'draft')==='approved';
        $localApproved=($v['cancellation_approval']??'draft')==='approved';
        if(!$approved||!$localApproved||!$r['valid'])return '<p>'.esc_html__('Cancellation schedule is subject to individual review.','commerce-composition').'</p>';
        $rows=array_reverse($r['rows']);
        $html='<table class="mad4b-company-cancellation-schedule"><thead><tr><th>'.esc_html__('Days before arrival','commerce-composition').'</th><th>'.esc_html__('Cancellation charge','commerce-composition').'</th></tr></thead><tbody>';
        foreach($rows as $row){
            $range=$row['min_days'].'–'.$row['max_days'];
            if($row['max_days']===36500)$range=(string)$row['min_days'].'+';
            $html.='<tr><td>'.esc_html($range).'</td><td>'.esc_html(rtrim(rtrim(number_format($row['fee_percent'],2,'.',''),'0'),'.').'%').'</td></tr>';
        }
        return $html.'</tbody></table>';
    }
    public static function expand(string $html):string {
        $html=str_replace('[mad4b_cancellation_schedule]',self::schedule('standard'),$html);
        $html=str_replace('[mad4b_peak_cancellation_schedule]',self::schedule('peak'),$html);
        return $html;
    }
}
