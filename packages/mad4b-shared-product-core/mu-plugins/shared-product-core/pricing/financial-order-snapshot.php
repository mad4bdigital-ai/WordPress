<?php
/** Lab-only immutable quote envelope and order readback; no automatic order writes. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_ledger_snapshot_prepare($ledger) {
 if (!is_array($ledger) || ($ledger['schema']??null)!==2 || !is_array($ledger['lines']??null)) return new WP_Error('snapshot_invalid_ledger');
 $currency=$ledger['currency']??null;
 $decimals=shared_product_ledger_currency_decimals($currency);
 if(is_wp_error($decimals)||($ledger['currency_decimals']??null)!==$decimals)return new WP_Error('snapshot_currency_mismatch');
 $sum=0;$ids=[];
 foreach($ledger['lines'] as $line){
  if(!is_array($line)||!is_string($line['id']??null)||($line['id']??'')===''||isset($ids[$line['id']]))return new WP_Error('snapshot_invalid_line');
  $ids[$line['id']]=true;
  foreach(['net_minor','discount_minor','tax_minor','payable_minor'] as $k)if(!isset($line[$k])||!is_int($line[$k])||$line[$k]<0)return new WP_Error('snapshot_invalid_amount');
  if($line['net_minor']-$line['discount_minor']+$line['tax_minor']!==$line['payable_minor'])return new WP_Error('snapshot_line_imbalance');
  $sum+=$line['payable_minor'];
 }
 foreach(['customer_payment_fee_minor','payment_fee_tax_minor','customer_total_minor'] as $k)if(!isset($ledger[$k])||!is_int($ledger[$k])||$ledger[$k]<0)return new WP_Error('snapshot_invalid_amount');
 if($sum+$ledger['customer_payment_fee_minor']+$ledger['payment_fee_tax_minor']!==$ledger['customer_total_minor'])return new WP_Error('snapshot_total_mismatch');
 $payload=['version'=>1,'ledger'=>$ledger];
 $canonical=wp_json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 return ['payload'=>$payload,'sha256'=>hash('sha256',$canonical),'status'=>'prepared_only_not_saved'];
}
function shared_product_ledger_snapshot_verify($envelope){
 if(!is_array($envelope)||!is_array($envelope['payload']??null)||!is_string($envelope['sha256']??null))return new WP_Error('snapshot_invalid_envelope');
 $ledger=$envelope['payload']['ledger']??null;
 $prepared=shared_product_ledger_snapshot_prepare($ledger);
 if(is_wp_error($prepared))return $prepared;
 if(!hash_equals($prepared['sha256'],$envelope['sha256']))return new WP_Error('snapshot_digest_mismatch');
 return true;
}
