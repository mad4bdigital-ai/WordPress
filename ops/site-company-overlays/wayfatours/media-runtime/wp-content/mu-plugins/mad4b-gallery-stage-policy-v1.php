<?php
/**
 * Plugin Name: MAD4B Gallery Visit Stage Policy
 * Description: Present day tours, half-day and extra_trip as timed visit stages, never as artificial calendar days.
 * Version: 1.0.0
 */
defined('ABSPATH')||exit;
final class MAD4B_Gallery_Stage_Policy_V1 {
  public const META='_mad4b_gallery_stage_times_v1';
  public static function classify(string $mode,array $type_slugs):bool {
    if($mode==='cruise_tour')return false; // Program days remain authoritative.
    if(in_array($mode,['extra_trip','activity'],true))return true;
    foreach($type_slugs as $slug)
      if(in_array((string)$slug,['day-tours','half-day','day-tour','half-day-tour'],true))
        return true;
    return false;
  }
  public static function enabled(int $id):bool {
    if($id<1||get_post_type($id)!=='tours-and-activities')return false;
    $mode=(string)get_post_meta($id,'tour_mode',true);
    if(in_array($mode,['extra_trip','activity','cruise_tour'],true))
       return self::classify($mode,[]);
    $terms=wp_get_object_terms($id,'tour-types',['fields'=>'all']);
    if(is_wp_error($terms)||!is_array($terms))return false;
    $slugs=[];
    foreach($terms as $term){
      if(!$term instanceof WP_Term)continue;
      $line=array_merge([$term->term_id],get_ancestors($term->term_id,'tour-types','taxonomy'));
      foreach($line as $tid){
        $t=get_term((int)$tid,'tour-types');
        if($t instanceof WP_Term)$slugs[]=$t->slug;
      }
    }
    return self::classify($mode,$slugs);
  }
  public static function valid_time($raw):string {
    if(!is_string($raw))return '';
    $raw=trim($raw);
    if($raw==='')return '';
    if(!preg_match('/^(?:([01]?\d|2[0-3]):([0-5]\d)|((?:1[0-2]|0?[1-9]))(?::([0-5]\d))?\s*(AM|PM))$/iD',$raw,$m))
      return '';
    if(!empty($m[5])){
      $hour=(int)$m[3]%12+ (strtoupper($m[5])==='PM'?12:0);
      $minute=(int)($m[4]??0);
    }else{$hour=(int)$m[1];$minute=(int)$m[2];}
    return sprintf('%02d:%02d',$hour,$minute);
  }
  public static function format_time(string $value):string {
    if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$value))return '';
    $dt=DateTimeImmutable::createFromFormat('!H:i',$value,new DateTimeZone('UTC'));
    return $dt?$dt->format('g:i A'):'';
  }
  private static function title_hash($title):string {
    return hash('sha256',trim(html_entity_decode(wp_strip_all_tags((string)$title),ENT_QUOTES|ENT_HTML5,'UTF-8')));
  }
  private static function exact_schedule(int $post,string $rowKey,array $row):array {
    foreach(['stage_time','start_time','time'] as $key){
      $v=self::valid_time(is_scalar($row[$key]??null)?(string)$row[$key]:'');
      if($v!==''){
        $end=self::valid_time(is_scalar($row['end_time']??null)?(string)$row['end_time']:'');
        return ['start'=>$v,'end'=>$end,'origin'=>'itinerary_item:'.$key];
      }
    }
    $map=get_post_meta($post,self::META,true);
    $entry=is_array($map)?($map[$rowKey]??null):null;
    if(!is_array($entry)||($entry['title_hash']??'')!==self::title_hash($row['title']??''))return [];
    $v=self::valid_time((string)($entry['start']??''));
    $end=self::valid_time((string)($entry['end']??''));
    return $v!==''?['start'=>$v,'end'=>$end,'origin'=>self::META]:[];
  }
  public static function rows(int $post,int $canonical=0):array {
    $canonical=$canonical?:$post;
    if(!self::enabled($canonical))return [];
    $main=get_post_meta($canonical,'itinerary_item',true);
    if(!is_array($main)||!$main)return [];
    $translated=$post!==$canonical?get_post_meta($post,'itinerary_item',true):$main;
    // Translated Tour text is used only if its stage count matches source.
    $sameCount=is_array($translated)&&count($translated)===count($main);
    $sourceKeys=array_keys($main);$translationKeys=$sameCount?array_keys($translated):[];
    $rows=[];$max=45;
    foreach($main as $index=>$row){
      if(!is_array($row))continue;
      $label=trim(html_entity_decode(wp_strip_all_tags((string)($row['title']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8'));
      if($label==='')continue;
      $key=(string)$index;
      $use=$row;
      if($sameCount){
        $translatedAt=(array_search($key,$translationKeys,true));
        if($translatedAt!==false){
          $t=$translated[$key]??null;
          if(is_array($t)&&trim((string)($t['title']??''))!=='')$use=$t;
        }
      }
      $title=trim(html_entity_decode(wp_strip_all_tags((string)($use['title']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8'));
      // Legacy "Day 1" is a label, not duration evidence, on a day trip.
      $title=trim(preg_replace('/^\s*(?:Day|Día|Jour|Tag|Giorno|اليوم)\s*\d+\s*[:\-–.]?\s*/iu','',$title));
      $description=trim(html_entity_decode(wp_strip_all_tags((string)($use['description']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8'));
      $time=self::exact_schedule($post,$key,$use);
      if(!$time&&$post!==$canonical)$time=self::exact_schedule($canonical,$key,$row);
      $rows[]=[
        'stage'=>count($rows)+1,'row_key'=>$key,'title'=>mb_substr($title,0,135),
        'description'=>mb_substr(preg_replace('/\s+/u',' ',$description),0,600),
        'start_time'=>$time['start']??'','end_time'=>$time['end']??'',
        'time_source'=>$time['origin']??'',
        'time_label'=>isset($time['start'])?self::format_time($time['start']).
         (!empty($time['end'])?' – '.self::format_time($time['end']):''):'',
        'optional'=>(bool)preg_match('/\b(?:optional|opcional|facultatif|optionnel)\b/iu',$title),
      ];
      if(count($rows)>=$max)break;
    }
    return $rows;
  }
  public static function admin_box($post):void {
    $rows=get_post_meta($post->ID,'itinerary_item',true);
    if(!is_array($rows)||!$rows){echo '<p>No itinerary stages are entered.</p>';return;}
    wp_nonce_field('mad4b_gallery_stage_times_'.$post->ID,'mad4b_gallery_stage_times_nonce');
    echo '<p>Optional exact start/end time for each visit stage. No time is inferred from the Tour departure time. Leave blank when the supplier has not confirmed it.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Stage</th><th>Starts</th><th>Ends</th></tr></thead><tbody>';
    foreach($rows as $key=>$row){
      if(!is_array($row)||empty($row['title']))continue;
      $entry=self::exact_schedule((int)$post->ID,(string)$key,$row);
      $index=esc_attr((string)$key);
      echo '<tr><td>'.esc_html(wp_strip_all_tags((string)$row['title'])).'</td>';
      foreach(['start','end']as $field){
        $value=(string)($entry[$field]??'');
        echo '<td><input type="time" step="60" name="mad4b_gallery_stages['.$index.']['.
         esc_attr($field).']" value="'.esc_attr($value).'" style="max-width:130px;width:100%" /></td>';
      }
      echo '</tr>';
    }
    echo '</tbody></table><p><small>If a stage title changes, its saved time is not used until reviewed. Time changes affect only the gallery presentation, not booking/departure prices.</small></p>';
  }
  public static function boxes():void {
    if(!current_user_can('edit_posts'))return;
    global $post;
    if($post instanceof WP_Post&&self::enabled((int)$post->ID))
      add_meta_box('mad4b-gallery-stage-times','Visit Stage Times — Gallery',
        [self::class,'admin_box'],'tours-and-activities','normal','default');
  }
  public static function save(int $id,$post):void {
    if(!$post instanceof WP_Post||$post->post_type!=='tours-and-activities'
      ||wp_is_post_revision($id)||wp_is_post_autosave($id)
      ||!current_user_can('edit_post',$id)
      ||!self::enabled($id)
      ||!isset($_POST['mad4b_gallery_stage_times_nonce'])
      ||!wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_POST['mad4b_gallery_stage_times_nonce'])),
       'mad4b_gallery_stage_times_'.$id))return;
    $raw=$_POST['mad4b_gallery_stages']??null;
    if(!is_array($raw))return;
    $rows=get_post_meta($id,'itinerary_item',true);
    if(!is_array($rows))return;
    $data=[];
    foreach($rows as $key=>$row){
      if(!is_array($row)||!isset($raw[$key])||!is_array($raw[$key]))continue;
      $start=self::valid_time(sanitize_text_field(wp_unslash((string)($raw[$key]['start']??''))));
      $end=self::valid_time(sanitize_text_field(wp_unslash((string)($raw[$key]['end']??''))));
      if($start==='')continue;
      if($end!=='' &&strcmp($end,$start)<=0)continue; // No implicit overnight stages.
      $data[(string)$key]=['title_hash'=>self::title_hash($row['title']??''),
        'start'=>$start,'end'=>$end];
    }
    if($data)update_post_meta($id,self::META,$data);
    else delete_post_meta($id,self::META);
  }
}
add_action('add_meta_boxes_tours-and-activities',[MAD4B_Gallery_Stage_Policy_V1::class,'boxes']);
add_action('save_post_tours-and-activities',[MAD4B_Gallery_Stage_Policy_V1::class,'save'],999,2);
