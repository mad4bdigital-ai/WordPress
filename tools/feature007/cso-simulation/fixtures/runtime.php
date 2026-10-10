<?php
$key=openssl_pkey_new(array('private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA));
$signature='';$signed=openssl_sign('fixture-message',$signature,$key,OPENSSL_ALGO_SHA256);
$public=openssl_pkey_get_details($key);
$verified=openssl_verify('fixture-message',$signature,$public['key'],OPENSSL_ALGO_SHA256);
$tag='';$cipher=openssl_encrypt('fixture-gcm','aes-256-gcm',str_repeat('k',32),OPENSSL_RAW_DATA,str_repeat('i',12),$tag);
$plain=openssl_decrypt($cipher,'aes-256-gcm',str_repeat('k',32),OPENSSL_RAW_DATA,str_repeat('i',12),$tag);
echo json_encode(array('php'=>PHP_VERSION,'sapi'=>PHP_SAPI,'int_size'=>PHP_INT_SIZE,'openssl'=>OPENSSL_VERSION_TEXT,'rsa_ok'=>true===$signed&&1===$verified,'aes_gcm_ok'=>'fixture-gcm'===$plain,'sodium'=>extension_loaded('sodium'),'pcntl'=>extension_loaded('pcntl'),'env_home_absent'=>false===getenv('HOME'),'env_canary_absent'=>false===getenv('CSO_RUNTIME_HOST_CANARY'),'host_etc_absent'=>!file_exists('/etc/passwd'),'network_disabled'=>!filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN),'curl_disabled'=>!function_exists('curl_exec'))),"\n";
