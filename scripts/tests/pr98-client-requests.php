<?php
// Captures requests without contacting a server or changing WordPress options.
define('ABSPATH','/tmp/');define('HOUR_IN_SECONDS',3600);define('DAY_IN_SECONDS',86400);define('MINUTE_IN_SECONDS',60);define('ISF_VERSION','test');
function home_url(){return 'https://customer.test';}function wp_parse_url($u,$c){return parse_url($u,$c);}function get_bloginfo($v){return 'Test';}
function apply_filters($t,$v,...$a){return $GLOBALS['fingerprint']??$v;}function wp_json_encode($v){return json_encode($v);}
function wp_remote_post($u,$a){$GLOBALS['capture']=[$u,$a];return [];}
function wp_remote_get($u,$a){$GLOBALS['capture']=[$u,$a];return [];}
function is_wp_error($a){return false;}function wp_remote_retrieve_response_code($a){return 400;}
function wp_remote_retrieve_body($a){return '{"success":false,"error":"license_restricted","message":"Denied"}';}
function __($s,$d=''){return $s;}
require $argv[1];$suite=$argv[2]==='suite';$class=$suite?'Peanut_License':'ISF\\LicenseManager';
$o=(new ReflectionClass($class))->newInstanceWithoutConstructor();$m=new ReflectionMethod($o,$suite?'remote_validate':'validate_license_remote');
foreach(['','Bound-Fingerprint'] as $fingerprint){$GLOBALS['fingerprint']=$fingerprint;
 $result=$suite?$m->invoke($o,'SECRET'):$m->invoke($o,'SECRET',true);
 if($suite ? ($result['status'] !== 'invalid' || $result['tier'] !== 'free') : ($result['success'] !== false || $result['error'] !== 'license_restricted')) throw new Exception('restriction denial lost');[$url,$args]=$GLOBALS['capture'];
 $body=is_array($args['body'])?$args['body']:json_decode($args['body'],true);
 if($body['hardware_id']!==$fingerprint||$body['site_url']!=='https://customer.test')throw new Exception('activation identity mismatch');
}
if(!$suite){$m->invoke($o,'SECRET',false);[$url,$args]=$GLOBALS['capture'];if(str_contains($url,'SECRET')||$args['headers']['X-Peanut-License-Key']!=='SECRET')throw new Exception('status key transport');}
echo "PASS: exact fingerprint and domain forwarded; rejection retained".($suite?'':'; status key outside URL')."\n";
