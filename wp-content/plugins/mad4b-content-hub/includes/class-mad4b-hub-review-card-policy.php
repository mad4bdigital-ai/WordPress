<?php
defined('ABSPATH') || exit;
/**
 * Small review-card contract for all review types, not a hardcoded provider.
 * Human decision is explicit. Source and QA fields cannot be inferred.
 */
final class MAD4B_Hub_Review_Card_Policy {
 const CONTRACT='mad4b.hub.review-card-policy.v1';
 const MAX_SUMMARY_WORDS=32;
 const MAX_EVIDENCE_POINTS=3;
 const DECISIONS=['approve','needs_changes','reject'];
 private static function first_sentence($summary){
  $summary=trim((string)$summary);
  $parts=preg_split('/(?<=[.!?؟])\s+/u',$summary,2,PREG_SPLIT_NO_EMPTY);
  $first=is_array($parts)&&$parts?$parts[0]:$summary;
  return mb_strlen($first)>290?wp_trim_words($first,self::MAX_SUMMARY_WORDS,'…'):$first;
 }
 public static function build($data){
  $output=is_array($data['output']??null)?$data['output']:[];
  $type=strtolower((string)($output['task_type']??'content'));
  $qa=!empty($data['format_pass']);
  $factual=!empty($data['facts_verified']);
  $sources=count((array)($output['evidence_refs']??[]));
  $limits=(array)($output['limitations']??[]);
  $q=[
   'research'=>['هل الأدلة كافية لاعتماد المسودة تحريرياً؟','Is the evidence sufficient for editorial approval?'],
   'content'=>['هل المسودة مناسبة للاعتماد التحريري؟','Is this draft ready for editorial approval?'],
   'translation'=>['هل الترجمة دقيقة وتطابق المصدر؟','Does the translation accurately reflect its source?'],
   'pricing'=>['هل بيانات الأسعار مدعومة بالمصدر ومعتمدة؟','Are the prices source-backed and approved?'],
   'seo'=>['هل المحتوى ملائم لمحركات البحث ومدعوم بالأدلة؟','Is the SEO content evidence-backed and appropriate?'],
  ][$type]??['هل تراجع المحتوى وتحدد القرار المناسب؟','Can you review the evidence and choose a decision?'];
  $points=[
   [
    'ar'=>$sources?($sources.' مراجع مرتبطة بالمصدر'):'لم تُرفق مراجع مصدر',
    'en'=>$sources?($sources.' source reference(s) attached'):'No source references attached',
    'ok'=>$sources>0
   ],
   [
    'ar'=>$qa?'عقد تنسيق المخرجات اجتاز الفحص':'فحص تنسيق المخرجات غير مكتمل',
    'en'=>$qa?'Output formatting QA passed':'Output formatting QA is incomplete',
    'ok'=>$qa
   ],
   [
    'ar'=>$factual?'تم توثيق التحقق المستقل من الحقائق':'الحقائق لم تُتحقق بشكل مستقل',
    'en'=>$factual?'Independent facts verified':'Independent fact-check not completed',
    'ok'=>$factual
   ]
  ];
  return [
   'contract'=>self::CONTRACT,
   'title'=>mb_substr((string)($output['title']??'Untitled'),0,140),
   'short_summary'=>self::first_sentence((string)($output['summary']??'')),
   'question'=>['ar'=>$q[0],'en'=>$q[1]],
   'points'=>array_slice($points,0,self::MAX_EVIDENCE_POINTS),
   'decisions'=>self::DECISIONS,
   'requires_human_decision'=>true,
   'auto_approve'=>false,
   'approval_does_not_publish'=>true,
   'attention'=>count($limits)>0?mb_substr((string)reset($limits),0,180):'',
   'factual_verified'=>$factual,
   'review_status'=>$data['review']['decision']??'AWAITING_MASTER_REVIEW'
  ];
 }
}
