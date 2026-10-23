<?php
/** Static admin contract: only registered providers appear in guided controls. */
$path=dirname(__DIR__).'/includes/search/class-mad4b-scp-search-experience.php';
$src=file_get_contents($path);
foreach(array(
 'MAD4B_SCP_Search_Providers::adapters()',
 'id="mad4b-search-control-provider"',
 'Select provider when applicable',
 'Advanced target control',
 'wp_nonce_field( \'mad4b_search_control\' )',
 'MAD4B_SCP_Search_Runtime::can_configure()',
 'expected_revision'
) as $marker) {
 if(strpos($src,$marker)===false){fwrite(STDERR,"Missing guided provider control: $marker\n");exit(1);}
}
if(strpos($src,'Provider ID <input name="provider_id"')!==false){fwrite(STDERR,"Legacy free-text provider entry remains\n");exit(1);}
echo "PASS: guided Search provider control uses registered adapter options and existing governance\n";
