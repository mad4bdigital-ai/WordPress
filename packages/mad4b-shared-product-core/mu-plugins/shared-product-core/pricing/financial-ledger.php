<?php
/** Deterministic minor-unit ledger; preview only. Does not change orders/payments. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
/** Currency precision must be frozen with a quote; never infer it from the visitor's current display. */
function shared_product_ledger_currency_decimals($currency){
 $zero=['JPY','KRW','VND','CLP','ISK','PYG','RWF','UGX','XAF','XOF','XPF'];
 $three=['BHD','KWD','OMR','JOD','TND','IQD','LYD'];
 if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))return new WP_Error('invalid_ledger_currency');
 return in_array($currency,$zero,true)?0:(in_array($currency,$three,true)?3:2);
}
function shared_product_ledger_quote($lines,$discount_pct,$tax_pct,$fee,$policy,$selected=[],$currency="USD"){
 $p=shared_product_financial_override($policy);
 if(is_wp_error($p)||count($p)!==count(shared_product_financial_field_choices()))return new WP_Error('ledger_policy_incomplete');
 if(!is_array($lines)||!$lines||!is_array($selected))return new WP_Error('ledger_lines_required');
 foreach([$discount_pct,$tax_pct,$fee] as $v)if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0)return new WP_Error('ledger_invalid_number');
 if($discount_pct>100||$tax_pct>100)return new WP_Error('ledger_invalid_percent');
 $decimals=shared_product_ledger_currency_decimals($currency);if(is_wp_error($decimals))return $decimals;
 $factor=10**$decimals;
 $tax=(float)$tax_pct/100;$discount=(float)$discount_pct/100;$result=[];$sum=0;$seen=[];
 foreach($lines as $line){
  $id=$line['id']??null;$component=$line['component']??null;$amount=$line['amount']??null;
  if(!is_string($id)||$id===''||isset($seen[$id])||!is_string($component)||$component===''||!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<0)return new WP_Error('ledger_invalid_line');
  $seen[$id]=true;$price=round((float)$amount*$factor);$net=$p['tax_price_basis']==='inclusive'?round($price/(1+$tax)):$price;
  $eligible=match($p['discount_scope']){'all'=>true,'none'=>false,'base_only'=>$component==='base','selected_components'=>in_array($component,$selected,true),'exclude_selected_components'=>!in_array($component,$selected,true)};
  // Inclusive prices are tax-adjusted to avoid applying tax twice; after-tax discounts do not reduce tax.
  $discount_net=$eligible?round($net*$discount):0;
  $tax_cents=round($net*$tax);
  if($p['discount_tax_order']==='before_tax')$tax_cents=round(($net-$discount_net)*$tax);
  $discount_cents=$p['discount_tax_order']==='before_tax'?$discount_net:($eligible?round(($net+$tax_cents)*$discount):0);
  $payable=(int)($net+$tax_cents-$discount_cents);
  if($payable<0)return new WP_Error('ledger_negative_line');
  $result[]=['id'=>$id,'component'=>$component,'net_minor'=>(int)$net,'discount_minor'=>(int)$discount_cents,'tax_minor'=>(int)$tax_cents,'payable_minor'=>$payable];$sum+=$payable;
 }
 $fee_cents=(int)round((float)$fee*$factor);$customer_fee=match($p['payment_fee_bearer']){'customer'=>$fee_cents,'merchant'=>0,'split'=>(int)round($fee_cents/2)};
 $fee_tax=$p['payment_fee_taxable']==='yes'?(int)round($customer_fee*$tax):0;
 return ['schema'=>2,'currency'=>$currency,'currency_decimals'=>$decimals,'lines'=>$result,'customer_payment_fee_minor'=>$customer_fee,'merchant_payment_fee_minor'=>$fee_cents-$customer_fee,'payment_fee_tax_minor'=>$fee_tax,'customer_total_minor'=>$sum+$customer_fee+$fee_tax,'policy'=>$p,'status'=>'preview_only_not_checkout_authority'];
}
/** Refund proportional to frozen line payables; no live rate recalculation. */
function shared_product_ledger_refund_preview($ledger,$line_refunds_minor,$prior_refunds_minor=[]){
 if(!is_array($ledger)||($ledger['schema']??null)!==2||!is_array($line_refunds_minor)||!is_array($prior_refunds_minor))return new WP_Error('refund_invalid_ledger');
 $by_id=[];foreach(($ledger['lines']??[]) as $line)$by_id[$line['id']]=$line;
 $sum=0;$approved=[];
 foreach($line_refunds_minor as $id=>$requested){
  if(!isset($by_id[$id])||!is_int($requested)||$requested<0)return new WP_Error('refund_invalid_line_request');
  $used=$prior_refunds_minor[$id]??0;
  if(!is_int($used)||$used<0||$requested+$used>$by_id[$id]['payable_minor'])return new WP_Error('refund_exceeds_line_balance');
  $approved[$id]=$requested;$sum+=$requested;
 }
 // Fee allocation needs a separate explicit decision and payment provider confirmation.
 return ['line_refunds_minor'=>$approved,'line_refund_total_minor'=>$sum,'fee_refund_minor'=>0,'status'=>'preview_only_fee_refund_requires_approval'];
}

/** Certified-format preview: cumulative minor-unit refunds with conservation of line components. */
function shared_product_ledger_refund_breakdown($ledger,$requests,$previous=[]){
 if(!is_array($ledger)||($ledger['schema']??null)!==2||!is_array($requests)||!is_array($previous))return new WP_Error('refund_invalid_ledger');
 $items=[];
 foreach(($ledger['lines']??[]) as $line){
  $id=$line['id']??null;
  if(!is_string($id)||$id===''||isset($items[$id]))return new WP_Error('refund_invalid_ledger_line');
  foreach(['net_minor','discount_minor','tax_minor','payable_minor'] as $field)if(!isset($line[$field])||!is_int($line[$field])||$line[$field]<0)return new WP_Error('refund_invalid_ledger_line');
  if($line['net_minor']-$line['discount_minor']+$line['tax_minor']!==$line['payable_minor'])return new WP_Error('refund_line_not_balanced');
  $items[$id]=$line;
 }
 $sum=0;$details=[];
 foreach($requests as $id=>$amount){
  if(!isset($items[$id])||!is_int($amount)||$amount<0)return new WP_Error('refund_invalid_request');
  $line=$items[$id];$used=$previous[$id]??0;
  if(!is_int($used)||$used<0||$used>$line['payable_minor']||$used+$amount>$line['payable_minor'])return new WP_Error('refund_exceeds_line_balance');
  $total=$line['payable_minor'];
  if($total===0){if($amount>0)return new WP_Error('refund_exceeds_line_balance');$details[$id]=['net_minor'=>0,'discount_minor'=>0,'tax_minor'=>0,'payable_minor'=>0];continue;}
  // Cumulative rounding: a second partial refund receives the difference between cumulative allocations.
  $allocated=[];
  foreach(['net_minor','discount_minor'] as $field){
   $next=(int)round($line[$field]*($used+$amount)/$total);
   $prior=(int)round($line[$field]*$used/$total);
   $allocated[$field]=$next-$prior;
  }
  $allocated['tax_minor']=$amount-$allocated['net_minor']+$allocated['discount_minor'];
  $allocated['payable_minor']=$amount;
  $details[$id]=$allocated;$sum+=$amount;
 }
 foreach($previous as $id=>$used)if(!isset($items[$id])||!is_int($used)||$used<0||$used>$items[$id]['payable_minor'])return new WP_Error('refund_invalid_previous_balance');
 return ['schema'=>2,'lines'=>$details,'line_refund_total_minor'=>$sum,'customer_fee_refund_minor'=>0,'status'=>'preview_only_payment_fee_requires_separate_approval'];
}

/** Policy-aware refund preview with cumulative fee caps; no gateway action. */
function shared_product_ledger_policy_refund($ledger,$request,$prior=[]){
 if(!is_array($ledger)||!is_array($request)||!is_array($prior))return new WP_Error('invalid_refund_policy_request');
 $p=shared_product_financial_override($ledger['policy']??null);
 if(is_wp_error($p)||count($p)!==count(shared_product_financial_field_choices()))return new WP_Error('refund_policy_missing');
 $lines=$request['lines']??null;
 if(!is_array($lines)||!$lines)return new WP_Error('refund_lines_required');
 $lookup=[];foreach(($ledger['lines']??[]) as $line)$lookup[$line['id']]=$line;
 $nonrefundable=$request['nonrefundable_components']??[];
 if(!is_array($nonrefundable))return new WP_Error('invalid_nonrefundable_components');
 if($p['refund_allocation']==='nonrefundable_components' && !array_key_exists('nonrefundable_components',$request))return new WP_Error('nonrefundable_components_required');
 $selected=$request['selected_line_ids']??[];
 if($p['refund_allocation']==='line_based' && (!is_array($selected)||!$selected))return new WP_Error('refund_line_selection_required');
 foreach($lines as $id=>$amount){
  if(!isset($lookup[$id]))return new WP_Error('refund_unknown_line');
  if($p['refund_allocation']==='line_based'&&!in_array($id,$selected,true))return new WP_Error('refund_line_not_selected');
  if($p['refund_allocation']==='nonrefundable_components'&&in_array($lookup[$id]['component']??null,$nonrefundable,true))return new WP_Error('refund_nonrefundable_component');
 }
 $breakdown=shared_product_ledger_refund_breakdown($ledger,$lines,$prior['lines']??[]);
 if(is_wp_error($breakdown))return $breakdown;
 $fee_total=(int)($ledger['customer_payment_fee_minor']??0)+(int)($ledger['payment_fee_tax_minor']??0);
 $previous_fee=$prior['fee_minor']??0;
 if(!is_int($previous_fee)||$previous_fee<0||$previous_fee>$fee_total)return new WP_Error('invalid_prior_fee_refunds');
 $requested_fee=$request['fee_minor']??0;
 if(!is_int($requested_fee)||$requested_fee<0||$previous_fee+$requested_fee>$fee_total)return new WP_Error('refund_fee_exceeds_balance');
 if($requested_fee>0 && $p['refund_payment_fee']==='nonrefundable')return new WP_Error('payment_fee_nonrefundable');
 if($requested_fee>0 && empty($request['provider_fee_refundable_confirmed']))return new WP_Error('provider_fee_refund_unconfirmed');
 $grand=(int)($ledger['customer_total_minor']??-1);
 $previous_total=array_sum($prior['lines']??[])+$previous_fee;
 if($grand<0||$previous_total+$breakdown['line_refund_total_minor']+$requested_fee>$grand)return new WP_Error('refund_exceeds_invoice_balance');
 $breakdown['customer_fee_refund_minor']=$requested_fee;
 $breakdown['total_refund_minor']=$breakdown['line_refund_total_minor']+$requested_fee;
 $breakdown['remaining_invoice_minor']=$grand-$previous_total-$breakdown['total_refund_minor'];
 $breakdown['currency']=$ledger['currency']??null;
 $breakdown['currency_decimals']=$ledger['currency_decimals']??null;
 $breakdown['status']='preview_only_no_woocommerce_refund';
 return $breakdown;
}
