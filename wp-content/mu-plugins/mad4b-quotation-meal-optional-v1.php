<?php
/**
 * Plugin Name: MAD4B Supplier Quotation — Hotel Meal & Optional Extra Review
 * Description: Admin-only, exact-property quotation notes and provisional meal offers from a source spreadsheet. No automatic commercial price writes.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

if(!class_exists('MAD4B_Quotation_Meal_Extras_V1',false)){
final class MAD4B_Quotation_Meal_Extras_V1{
    public const OPTION='mad4b_supplier_quotation_sheet_source_v1';
    public const CONTRACT='mad4b.supplier-quotation-meal-extras-review.v1';
    public const URL='https://docs.google.com/spreadsheets/d/1PxrMcF2CuZrRGZ6R-2ZEeuqHXoqL6dT150XkhZk04-c/edit';
    private static ?array $cache=null;
    private static array $memo=[];

    /**
     * Site-independent sheet row to REAL English Property slug. Every
     * source has an explicit type+location contract. Unmatched source rows
     * stay in the audit inventory, and cannot be fuzzy-matched into hotels.
     *
     * No WP post IDs here. In particular, Wayfa 70732-70739 may be another
     * post type on Tours.dev; never copy across company databases.
     */
    private const PROPERTY_MAP=[
        'Hotels:4'=>[['regency-pyramids-hotel','hotel',['cairo','giza']]],
        'Hotels:8'=>[['pyramids-park-resort-cairo','hotel',['giza']]],
        'Hotels:28'=>[['stay-inn-pyramids','hotel',['cairo','giza']]],
        'Hotels:32'=>[['azal-pyramids-hotel','hotel',['cairo','giza']]],
        'Hotels:37'=>[['tolip-gardens-hotel','hotel',['cairo','giza']]],
        'Hotels:53'=>[['the-gabriel-hotel','hotel',['cairo']]],
        'Hotels:56'=>[['barcelo-cairo-pyramids','hotel',['giza']]],
        'Hotels:64'=>[['sonesta-hotel-tower-casino-cairo','hotel',['cairo']]],
        'Hotels:68'=>[['triumph-plaza-hotel','hotel',['cairo']]],
        'Hotels:77'=>[['hilton-golf-6-oct','hotel',['giza']]],
        'Hotels:81'=>[['movenpick-media-city','hotel',['giza']]],
        'Hotels:85'=>[['hilton-grand-nile-tower','hotel',['cairo']]],
        'Hotels:93'=>[['triumph-luxury-hotel','hotel',['cairo']]],
        'Hotels:97'=>[['hilton-nile-maadi','hotel',['cairo']]],
        'Hotels:102'=>[['hyatt-centric-cairo-west','hotel',['cairo']]],
        'Hotels:106'=>[['sheraton-cairo-hotel-casino','hotel',['giza']]],
        'Hotels:110'=>[['steigenberger-pyramids-cairo','hotel',['giza']]],
        'Hotels:114'=>[['fairmont-nile-city','hotel',['cairo']]],
        'Hotels:118'=>[['sofitel-cairo-nile-el-gezirah','hotel',['cairo']]],
        'Hotels:122'=>[['sofitel-cairo-downtown-nile','hotel',['cairo']]],
        'Hotels:143'=>[['the-zen-wellness-resort','hotel',['aswan-gov','aswan-city']]],
        'Hotels:155'=>[['sonesta-st-gorge-luxor','hotel',['luxor']]],
        'Hotels:159'=>[['maritim-jolie-ville-luxor-resort','hotel',['luxor']]],
        // The following source spelling aliases are editorial only: they
        // never authorize the supplier identity of a priced Rate or booking.
        'Hotels:163'=>[['staigenberger-nile-place','hotel',['luxor']]],
        'Hotels:171'=>[['staigenberger-achti','hotel',['luxor']]],
        'Hotels:175'=>[['iberotel-luxor','hotel',['luxor']]],
        'Costal:4'=>[['caribbean-world-soma-bay-hotel','hotel',['hurghada']]],
        'Costal:37'=>[['cleopatra-luxury-resort-makadi-bay','hotel',['hurghada']]],
        'Cruises:4'=>[['m-s-hapi-5','cruise',[]]],
        'Cruises:41'=>[['grand-rose-nile-cruise','cruise',[]]],
        'Cruises:57'=>[['m-s-alessandra-nile-cruise','cruise',[]]],
        'Cruises:70'=>[['m-s-lady-carol','cruise',[]],['ms-lady-mary','cruise',[]]],
        'Cruises:78'=>[['magic-ii','cruise',[]]],
        'Cruises:94'=>[['treasure-nile-cruise','cruise',[]]],
        'Cruises:98'=>[['m-s-al-jamila','cruise',[]]],
        'Cruises:102'=>[['m-s-al-kahila','cruise',[]]],
        'Cruises:106'=>[['sun-times-cruise','cruise',[]]],
        'Cruises:110'=>[['m-s-amwaj-living-stone-nile-cruise','cruise',[]]],
        'Cruises:114'=>[['nile-capital-cruise','cruise',[]]],
        'Cruises:118'=>[['m-s-zeina','cruise',[]]],
        'Cruises:122'=>[['m-s-ciela','cruise',[]]],
        'Cruises:135'=>[['nile-serenity-nile-cruise','cruise',[]]],
        'Cruises:139'=>[['esmeralda-nile','cruise',[]]],
        'Cruises:147'=>[['m-s-acamar','cruise',[]]],
        'Cruises:151'=>[['m-s-hamees','cruise',[]]]
    ];

    public static function source():array{
        if(self::$cache!==null)return self::$cache;
        $v=get_option(self::OPTION,[]);
        if(!is_array($v)||($v['v']??0)!==1||
           ($v['sheet']??'')!=='1PxrMcF2CuZrRGZ6R-2ZEeuqHXoqL6dT150XkhZk04-c' ||
           !is_array($v['groups']??null))
            $v=[];
        return self::$cache=$v;
    }
    private static function sourceRow(string $key):?array{
        foreach((array)(self::source()['groups']??[]) as $g){
            if(!is_array($g))continue;
            if((string)($g['t']??'').':'.(int)($g['r']??0)===$key)
                return $g;
        }
        return null;
    }
    private static function canonical(int $id):int{
        if($id<1||get_post_type($id)!=='properties')return 0;
        $en=(int)apply_filters('wpml_object_id',$id,'properties',false,'en');
        return $en>0&&get_post_type($en)==='properties'?$en:$id;
    }
    private static function aliasEligible(int $id,array $alias):bool{
        [$slug,$type,$scopes]=$alias;
        if(get_post_type($id)!=='properties'||get_post_status($id)!=='publish' ||
           get_post_field('post_name',$id)!==$slug)return false;
        $types=wp_get_object_terms($id,'property-types',['fields'=>'slugs']);
        if(is_wp_error($types))return false;
        if($type==='hotel'&&!array_intersect((array)$types,['hotel','resort']))
            return false;
        if($type==='cruise'&&!in_array('cruise',(array)$types,true))
            return false;
        if($scopes){
            $terms=wp_get_object_terms($id,'destinations',['fields'=>'slugs']);
            if(is_wp_error($terms)||!array_intersect((array)$terms,$scopes))
                return false;
        }
        return true;
    }
    private static function parseAmount(string $text):array{
        $result=['amount'=>null,'currency'=>null,'review'=>true];
        if(preg_match('/USD\s*\/\s*Euro|EUR\s*\/\s*USD/i',$text))
            return $result;
        $amount=null;$currency=null;
        if(preg_match('/(?:US\$|USD|EUR|€|\$)\s*[:=]?\s*(\d+(?:\.\d+)?)/iu',$text,$m)){
            $amount=(float)$m[1];
            $currency=(stripos($m[0],'EUR')!==false||str_contains($m[0],'€'))
                ?'EUR':'USD';
        }elseif(preg_match('/(\d+(?:\.\d+)?)\s*(?:USD|EUR|\$|€)(?!\w)/iu',$text,$m)){
            $amount=(float)$m[1];
            $currency=(stripos($m[0],'EUR')!==false||str_contains($m[0],'€'))
                ?'EUR':'USD';
        }
        if($amount!==null && $amount>0)
            $result=['amount'=>$amount,'currency'=>$currency,'review'=>true];
        return $result;
    }
    private static function classify(string $text,string $hint):string{
        if(preg_match('/obligatory|compulsory|mandatory/i',$text))
            return 'mandatory_supplement_review';
        if(preg_match('/\boptional\b/i',$text))
            return 'optional_extra_draft';
        if(preg_match('/supplement\s+(?:for\s+)?h\.?\s*b|half.board supplement/i',$text))
            return 'optional_meal_upgrade_draft';
        if(preg_match('/including gala dinner|inclusive of (?:service|taxes)/i',$text))
            return 'included_term_review';
        if(in_array($hint,['optional','upgrade'],true))return 'optional_extra_draft';
        if($hint==='mandatory')return 'mandatory_supplement_review';
        if($hint==='condition')return 'seasonal_rate_or_validity_note';
        return 'source_note_needs_review';
    }
    private static function structuredNotes(array $g):array{
        $notes=[];
        foreach((array)($g['x']??[]) as $entry){
            if(!is_array($entry)||count($entry)<5)continue;
            [$season,$row,$column,$text,$hint]=$entry;
            $row=(int)$row;$column=(int)$column;
            if(!is_string($text)||$text==='')continue;
            // Link a sentence fragment back to the correct original
            // seasonal column (no carryover to a different supplier).
            $previous=count($notes)-1;
            $continuation=preg_match('/^(?:at\s+|years?\s+|[.,]\s*(?:20\d\d)?|gala dinner\.$)/i',trim($text));
            if($previous>=0 && $continuation &&
               $notes[$previous]['season']===(int)$season &&
               $notes[$previous]['column_index']===$column &&
               $row-$notes[$previous]['last_row']<=2){
                $notes[$previous]['text'].=' '.trim($text);
                $notes[$previous]['last_row']=$row;
                $notes[$previous]['source_rows'][]=$row;
                $joined=&$notes[$previous];
                $joined['classification']=self::classify($joined['text'],$joined['source_hint']);
                $cost=self::parseAmount($joined['text']);
                $joined['quoted_supplement']=$cost;
                unset($joined);
                continue;
            }
            $note=self::parseAmount($text);
            $notes[]=[
                'season'=>(int)$season,'source_row'=>$row,'last_row'=>$row,
                'source_rows'=>[$row],'column_index'=>$column,
                'column'=>substr('ABCDEFGHIJKLMNOP',$column,1),
                'text'=>$text,'source_hint'=>(string)$hint,
                'classification'=>self::classify($text,(string)$hint),
                'quoted_supplement'=>$note,
                'date_window_approved'=>false,
                'is_active_extra'=>false,'checkout_ready'=>false,
                'supplier_confirmation_required'=>true
            ];
        }
        return $notes;
    }
    private static function sourceEntry(array $g,string $stageBoard):array{
        $notes=self::structuredNotes($g);
        $optional=[];$mandatory=[];$review=[];$included=[];$conditions=[];
        foreach($notes as $n){
            switch($n['classification']){
                case 'optional_extra_draft':
                case 'optional_meal_upgrade_draft':$optional[]=$n;break;
                case 'mandatory_supplement_review':$mandatory[]=$n;break;
                case 'included_term_review':$included[]=$n;break;
                case 'seasonal_rate_or_validity_note':$conditions[]=$n;break;
                default:$review[]=$n;
            }
        }
        $bySeason=[];
        foreach((array)($g['b']??[]) as $idx=>$boards){
            $bySeason[(int)$idx]=array_values((array)$boards);
        }
        $base=in_array((string)($g['t']??''),['Cruises'],true)?'fb':
              (($g['t']??'')==='Costal'?'ai':'bb');
        $quoted=[];
        foreach((array)($g['p']??[]) as $r){
            if(!is_array($r)||count($r)<6)continue;
            $quoted[]=[
                'season'=>(int)$r[0],'row'=>(int)$r[1],
                'room_type'=>(string)$r[2],'price_text'=>(string)$r[3],
                'source_meal_plan'=>(string)$r[4],
                'person_basis_flag'=>(bool)$r[5],
                'status'=>'unverified_quote_reference_not_supplier_cost',
                'supplier_approved'=>false
            ];
        }
        return [
            'sheet_tab'=>(string)$g['t'],
            'source_group_row'=>(int)$g['r'],
            'source_name'=>(string)$g['n'],
            'source_url'=>self::URL.'?gid='.(int)$g['gid'].
                '#range=A'.(int)$g['r'],
            'contract'=>'source_quotation_editorial_only',
            'default_board_policy'=>$base,'stage_board'=>$stageBoard,
            'sheet_board_by_season'=>$bySeason,
            'board_is_included_not_extra'=>true,
            'optional_extra_candidates'=>$optional,
            'mandatory_supplements'=>$mandatory,
            'included_or_contract_notes'=>$included,
            'seasonal_rate_conditions'=>$conditions,
            'other_review_notes'=>$review,
            'all_notes'=>$notes,'rate_reference_count'=>count($quoted),
            'rate_references'=>$quoted,
            'price_approved'=>false,'checkout_ready'=>false
        ];
    }
    public static function property(int $id,string $stageBoard=''):array{
        if(!current_user_can('commerce_manage_pricing') &&
           !current_user_can('manage_options'))
            return ['status'=>'requires_pricing_authorization'];
        $id=self::canonical($id);
        if(!$id)return ['status'=>'property_id_invalid'];
        $key=$id.'|'.$stageBoard;
        if(isset(self::$memo[$key]))return self::$memo[$key];
        $row=[];$groups=self::source()['groups']??[];
        if(!$groups)return self::$memo[$key]=['status'=>'source_registry_missing'];
        foreach(self::PROPERTY_MAP as $sourceKey=>$aliases){
            $matches=false;
            foreach($aliases as $alias)
                if(self::aliasEligible($id,$alias)){$matches=true;break;}
            if(!$matches)continue;
            $record=self::sourceRow($sourceKey);
            if(is_array($record))$row[]=self::sourceEntry($record,$stageBoard);
        }
        return self::$memo[$key]=[
            'status'=>$row?'sheet_reference_matched_unapproved':
                'no_reviewed_sheet_property_match',
            'property_id'=>$id,'source_count'=>count($row),
            'groups'=>$row,
            'optional_extra_count'=>array_sum(array_map(
                static fn($v)=>count($v['optional_extra_candidates']),$row)),
            'mandatory_supplement_count'=>array_sum(array_map(
                static fn($v)=>count($v['mandatory_supplements']),$row)),
            'board_fallback'=>$stageBoard!==''?$stageBoard:
                (has_term('cruise','property-types',$id)?'fb':
                    (has_term('hurghada','destinations',$id) ||
                     has_term('sharm-el-sheikh','destinations',$id)?'ai':'bb')),
            'sheet_prices_are_supplier_costs'=>false,
            'no_auto_checkout_or_rate_generation'=>true
        ];
    }
    public static function coverage():array{
        if(!current_user_can('commerce_manage_pricing') &&
           !current_user_can('manage_options'))
            return ['status'=>'requires_pricing_authorization'];
        $groups=(array)(self::source()['groups']??[]);$mapped=[];$unmatched=[];
        foreach($groups as $row){
            $key=(string)($row['t']??'').':'.(int)($row['r']??0);
            $present=[];
            foreach(self::PROPERTY_MAP[$key]??[] as $alias){
                $p=get_page_by_path($alias[0],OBJECT,'properties');
                if($p instanceof WP_Post &&
                   self::aliasEligible((int)$p->ID,$alias)){
                    $present[]=['id'=>(int)$p->ID,'slug'=>$alias[0]];
                }
            }
            if($present)$mapped[]=['key'=>$key,'source_name'=>$row['n'],'properties'=>$present];
            else $unmatched[]=['key'=>$key,'source_name'=>$row['n'],
                'status'=>isset(self::PROPERTY_MAP[$key])?
                    'no_matching_source_property_on_this_site':
                    'requires_supplier_property_identity_review'];
        }
        return [
          'contract'=>self::CONTRACT,
          'source_sheet'=>self::URL,
          'source_groups'=>count($groups),
          'matched_groups'=>count($mapped),
          'unmatched_groups'=>count($unmatched),
          'unmatched'=>$unmatched,
          'mapped'=>$mapped,
          'note_policy'=>'mandatory_fees_never_converted_to_optional_extras',
          'supplier_cost_approved'=>false
        ];
    }
}
}
