<?php
declare(strict_types=1);
namespace Commerce\Composition;
require_once __DIR__.'/LifecycleAccess.php';

/** Native WP ERP balanced journals for advances only. Never issues sales invoices. */
final class RequestAdvanceAccounting
{
    const OPTION='commerce_advance_accounting_v1';
    public static function config():array {return array_replace(['enabled'=>true,'clearing_ledger_id'=>0,'advance_ledger_id'=>0],(array)get_option(self::OPTION,[]));}
    public static function status():array {
        $c=self::config();return ['available'=>function_exists('erp_acct_insert_journal'),'config'=>$c,'currency'=>function_exists('erp_get_currency')?erp_get_currency():null,'posting_kind'=>'customer_advance_liability','final_sales_invoice_created'=>false];
    }
    public static function post(array $p,$o):array|\WP_Error {
        if(!in_array($p['event'],['advance_received','refund_recorded'],true))return ['status'=>'no_accounting_event'];
        if(!\Commerce\Composition\LifecycleAccess::can('commerce_manage_accounting'))return new \WP_Error('accounting_actor_not_authorized');
        if(!function_exists('erp_acct_insert_journal'))return new \WP_Error('accounting_provider_unavailable');
        if(!RequestAdvancePayments::isAdvance($o)||!$o->get_meta('_request_received_utc')||!$o->get_date_paid('edit'))return new \WP_Error('accounting_received_receipt_required');
        $c=self::config();if(!$c['enabled'])return ['status'=>'accounting_disabled'];
        if($o->get_currency()!==erp_get_currency())return new \WP_Error('accounting_currency_requires_verified_fx');
        $asset=erp_acct_get_ledger((int)$c['clearing_ledger_id']);$liability=erp_acct_get_ledger((int)$c['advance_ledger_id']);
        global $wpdb;
        $charts=$wpdb->get_results("SELECT id,slug FROM {$wpdb->prefix}erp_acct_chart_of_accounts",OBJECT_K);
        if(!$asset||!$liability||!in_array($charts[$asset->chart_id]->slug??'', ['asset','bank'],true)||($charts[$liability->chart_id]->slug??'')!=='liability')return new \WP_Error('accounting_advance_accounts_invalid');
        $snapshot=$o->get_meta('_request_customer_policy_snapshot');
        if(is_array($snapshot))$o->update_meta_data('_request_erp_policy_fingerprint',$snapshot['fingerprint']);
        $paid=(float)$o->get_meta('_request_paid_amount');
        if($paid<=0||abs($paid-(float)$o->get_total())>0.00001)return new \WP_Error('accounting_receipt_amount_mismatch');
        $receipt=self::journal($o,'receipt',0,$paid,(int)$asset->id,(int)$liability->id);if(is_wp_error($receipt))return $receipt;
        if($p['event']==='refund_recorded'){
            $refund=wc_get_order((int)$p['refund_id']);
            if(!$refund||$refund->get_type()!=='shop_order_refund'||$refund->get_parent_id()!==$o->get_id())return new \WP_Error('accounting_refund_identity_invalid');
            $amount=(float)$refund->get_amount();if($amount<=0||$amount>$paid)return new \WP_Error('accounting_refund_amount_invalid');
            $result=self::journal($o,'refund',$refund->get_id(),$amount,(int)$liability->id,(int)$asset->id);if(is_wp_error($result))return $result;
        }
        return ['status'=>'accounting_posted','receipt_voucher'=>$receipt['voucher_no'],'sales_invoice_created'=>false];
    }
    private static function journal($o,string $kind,int $refund,float $amount,int $debit,int $credit):array|\WP_Error {
        global $wpdb;
        $ref='MAD4B-ADV-'.$o->get_id().'-'.$kind.($refund?'-'.$refund:'');$lock='acct_'.substr(hash('sha256',home_url().$ref),0,48);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return new \WP_Error('accounting_journal_busy');
        try {
            $rows=$wpdb->get_results($wpdb->prepare("SELECT voucher_no,voucher_amount FROM {$wpdb->prefix}erp_acct_journals WHERE ref=%s",$ref),ARRAY_A);
            if(count($rows)>1)return new \WP_Error('accounting_duplicate_journal_requires_review');
            if($rows)$voucher=(int)$rows[0]['voucher_no'];
            else {
                $data=['date'=>gmdate('Y-m-d'),'ref'=>$ref,'voucher_amount'=>$amount,'particulars'=>'Customer request advance '.$kind.'; final trip total not agreed. Woo receipt #'.$o->get_id(),
                    'line_items'=>[['ledger_id'=>$debit,'debit'=>$amount,'credit'=>0],['ledger_id'=>$credit,'debit'=>0,'credit'=>$amount]]];
                $r=erp_acct_insert_journal($data);if(is_wp_error($r)||!is_array($r)||empty($r['voucher_no']))return new \WP_Error('accounting_journal_readback_uncertain');$voucher=(int)$r['voucher_no'];
            }
            $lines=$wpdb->get_results($wpdb->prepare("SELECT ledger_id,debit,credit FROM {$wpdb->prefix}erp_acct_ledger_details WHERE trn_no=%d",$voucher),ARRAY_A);
            if(count($lines)!==2)return new \WP_Error('accounting_journal_readback_uncertain');
            $sumDebit=0.;$sumCredit=0.;$seen=[];
            foreach($lines as $line){$sumDebit+=(float)$line['debit'];$sumCredit+=(float)$line['credit'];$seen[(int)$line['ledger_id']]=[(float)$line['debit'],(float)$line['credit']];}
            if(abs($sumDebit-$amount)>0.00001||abs($sumCredit-$amount)>0.00001||!isset($seen[$debit],$seen[$credit])||abs($seen[$debit][0]-$amount)>0.00001||abs($seen[$credit][1]-$amount)>0.00001)return new \WP_Error('accounting_journal_readback_uncertain');
            $meta=(array)$o->get_meta('_request_erp_journals');$meta[$ref]=['voucher_no'=>$voucher,'kind'=>$kind,'amount'=>$amount,'currency'=>$o->get_currency()];$o->update_meta_data('_request_erp_journals',$meta);$o->save();
            return ['voucher_no'=>$voucher,'ref'=>$ref];
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
}
