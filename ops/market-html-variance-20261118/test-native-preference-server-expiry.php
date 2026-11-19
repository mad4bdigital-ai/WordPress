<?php
/** Executed via WP-CLI eval-file, against first-party plugin runtime. */
$names=[
 ['cookie'=>'mad4b_country_selected_v1','contract'=>'mad4b.country-switcher.v1',
  'site_uuid'=>MAD4B_SCP_Market_Profiles::site_uuid(),
  'time_field'=>'time','read'=>['MAD4B_Country_Switcher','saved_country']],
 ['cookie'=>'travel_booking_nationality_preference_v1',
  'contract'=>'travel_booking.traveller-nationality.v1',
  'site_uuid'=>hash('sha256',rtrim((string)get_option('home',''),'/').'/'),
  'time_field'=>'saved_at','read'=>['Travel_Booking_Traveller_Nationality_Switcher','selected_manual']],
];
$slot=7776000;
$tests=0;
foreach($names as $cfg) {
    foreach(['fresh'=>intdiv(time(),$slot)*$slot,
             'legacy-zero'=>0,
             'expired'=>time()-3*$slot,
             'future'=>time()+1000] as $case=>$timestamp) {
        $raw=[
            'contract'=>$cfg['contract'],'site_uuid'=>$cfg['site_uuid'],
            'country'=>'US',$cfg['time_field']=>$timestamp
        ];
        $payload=rtrim(strtr(base64_encode(wp_json_encode($raw)),'+/','-_'),'=');
        $_COOKIE[$cfg['cookie']]=$payload.'.'.hash_hmac('sha256',$payload,wp_salt('auth'));
        $result=call_user_func($cfg['read']);
        $expected=($case==='fresh'||($case==='legacy-zero'&&time()<1803859200))?'US':'';
        if($result!==$expected) {
            fwrite(STDERR,'FAIL '.$cfg['cookie'].' '.$case.PHP_EOL);
            exit(4);
        }
        echo 'PASS '.$cfg['cookie'].' '.$case.PHP_EOL;
        ++$tests;
        unset($_COOKIE[$cfg['cookie']]);
    }
}
echo 'LIVE_NATIVE_SIGNED_PREFERENCE_SERVER_EXPIRY '.$tests.PHP_EOL;
