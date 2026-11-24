<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap/app.php';

use App\Services\MicrosoftIdTokenValidator;
use App\Services\MicrosoftOidcConfiguration;
use Firebase\JWT\JWT;

$checks=0;
$assert=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;};
$reject=static function(callable $fn,string $label)use($assert):void{try{$fn();$assert(false,$label);}catch(UnexpectedValueException|InvalidArgumentException $e){$assert(true,$label);}};
$b64=static fn(string $v):string=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
if($key===false)throw new RuntimeException('RSA fixture key generation failed');
$details=openssl_pkey_get_details($key); if(!is_array($details)||!isset($details['rsa']['n'],$details['rsa']['e']))throw new RuntimeException('RSA fixture public key unavailable');
$jwks=['keys'=>[['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>'fixture-a','n'=>$b64($details['rsa']['n']),'e'=>$b64($details['rsa']['e'])]]];
$config=new MicrosoftOidcConfiguration('11111111-1111-4111-8111-111111111111','22222222-2222-4222-8222-222222222222','offline-secret','https://example.test/callback');
$now=time();
$claims=['iss'=>'https://login.microsoftonline.com/'.$config->tenantId.'/v2.0','aud'=>$config->clientId,'tid'=>$config->tenantId,'oid'=>'33333333-3333-4333-8333-333333333333','nonce'=>'nonce-ok','iat'=>$now,'nbf'=>$now-1,'exp'=>$now+300];
$sign=static fn(array $payload,$signingKey,string $kid='fixture-a'):string=>JWT::encode($payload,$signingKey,'RS256',$kid);
$validator=new MicrosoftIdTokenValidator();
$good=$sign($claims,$key); $verified=$validator->validate($good,$jwks,$config,'nonce-ok');
$assert(($verified->oid??null)===$claims['oid'],'valid signed token accepted');
$bad=$claims;$bad['aud']='wrong-client';$reject(fn()=>$validator->validate($sign($bad,$key),$jwks,$config,'nonce-ok'),'wrong audience rejected');
$bad=$claims;$bad['tid']='44444444-4444-4444-8444-444444444444';$reject(fn()=>$validator->validate($sign($bad,$key),$jwks,$config,'nonce-ok'),'wrong tenant rejected');
$bad=$claims;$bad['exp']=$now-10;$reject(fn()=>$validator->validate($sign($bad,$key),$jwks,$config,'nonce-ok'),'expired token rejected');
$reject(fn()=>$validator->validate($good,$jwks,$config,'wrong-nonce'),'invalid nonce rejected');
$other=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
$reject(fn()=>$validator->validate($sign($claims,$other),$jwks,$config,'nonce-ok'),'wrong signature rejected');
$reject(fn()=>$validator->validate(JWT::encode($claims,$key,'RS256','unknown-kid'),$jwks,$config,'nonce-ok'),'unknown key id rejected');
echo 'PASS: '.$checks.' RSA/JWKS offline validation checks'.PHP_EOL;
