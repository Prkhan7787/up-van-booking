<?php
declare(strict_types=1);
if (!is_file(dirname(__DIR__).'/vendor/autoload.php')) {
    fwrite(STDERR, "BLOCKED: firebase/php-jwt is not installed; no JWT validation was bypassed.".PHP_EOL);
    exit(2);
}
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap/app.php';

use App\Services\MicrosoftIdTokenValidator;
use App\Services\MicrosoftOidcConfiguration;
use Firebase\JWT\JWT;

$checks=0;
$assert=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;};
$reject=static function(callable $fn,string $label)use($assert):void{try{$fn();$assert(false,$label);}catch(UnexpectedValueException|InvalidArgumentException $e){$assert(true,$label);}};
$b64=static fn(string $v):string=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
$keyOptions=['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048,'config'=>__DIR__.'/fixtures/oidc-openssl.cnf'];
$key=openssl_pkey_new($keyOptions);
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
$other=openssl_pkey_new($keyOptions);
$reject(fn()=>$validator->validate($sign($claims,$other),$jwks,$config,'nonce-ok'),'wrong signature rejected');
$reject(fn()=>$validator->validate(JWT::encode($claims,$key,'RS256','unknown-kid'),$jwks,$config,'nonce-ok'),'unknown key id rejected');
$bad=$claims;$bad['iss']='https://untrusted.example.test/v2.0';$reject(fn()=>$validator->validate($sign($bad,$key),$jwks,$config,'nonce-ok'),'wrong issuer rejected');
$reject(fn()=>$validator->validate(JWT::encode($claims,'offline-hmac-key-for-negative-testing','HS256','fixture-a'),$jwks,$config,'nonce-ok'),'HS256 rejected');
$otherAlgorithms=$jwks;$otherAlgorithms['keys'][0]['alg']='RS512';
$reject(fn()=>$validator->validate(JWT::encode($claims,$key,'RS512','fixture-a'),$otherAlgorithms,$config,'nonce-ok'),'RS512 rejected even when supplied by JWKS');

// Exercise the actual callback, signature validator and one-use session state,
// injecting only HTTP transport so these tests never contact a tenant or MySQL.
$savedSession=$_SESSION; $savedGet=$_GET;
$env=['MICROSOFT_LOGIN_ENABLED'=>'true','MICROSOFT_TENANT_ID'=>$config->tenantId,'MICROSOFT_CLIENT_ID'=>$config->clientId,
    'MICROSOFT_CLIENT_SECRET'=>$config->clientSecret,'MICROSOFT_REDIRECT_URI'=>$config->redirectUri];
$savedEnv=[]; $calls=0; $receivedVerifier=null; $receivedCode=null;
try {
    foreach($env as $name=>$value){$savedEnv[$name]=getenv($name);putenv($name.'='.$value);}
    $_SESSION=[];
    $callback=new App\Controllers\MicrosoftLoginController(
        static function($configuration,$code,$verifier)use(&$calls,&$receivedVerifier,&$receivedCode,$good):string {
            $calls++;$receivedVerifier=$verifier;$receivedCode=$code;return $good;
        },
        static function($configuration)use(&$calls,$jwks):array{$calls++;return $jwks;}
    );
    $pending=['state'=>'offline-state','nonce'=>'nonce-ok','verifier'=>str_repeat('v',43),'created_at'=>time()];
    $_SESSION['microsoft_oidc_pending']=$pending;
    $_GET=['state'=>$pending['state'],'code'=>'offline-authorization-code'];
    ob_start();try{$callback->callback();$body=ob_get_contents();}finally{ob_end_clean();}
    $assert($calls===2 && $receivedVerifier===$pending['verifier'] && $receivedCode===$_GET['code'],'callback exchanges code with the stored PKCE verifier');
    $assert(http_response_code()===403 && !isset($_SESSION['auth']),'valid signed identity without Admin mapping cannot create an Auth Session');
    $assert(!isset($_SESSION['microsoft_oidc_pending']),'pending callback is consumed');
    $assert(!str_contains($body,$good)&&!str_contains($body,$config->clientSecret),'callback response does not expose token or secret');
    ob_start();try{$callback->callback();}finally{ob_end_clean();}
    $assert(http_response_code()===403 && $calls===2 && !isset($_SESSION['auth']),'callback replay rejected before any token exchange');
    $_SESSION['microsoft_oidc_pending']=$pending;$_GET['state']='wrong-state';
    ob_start();try{$callback->callback();}finally{ob_end_clean();}
    $assert(http_response_code()===403 && $calls===2 && !isset($_SESSION['auth']),'wrong state rejected before any token exchange');
    $_GET['state']=$pending['state'];$_SESSION['microsoft_oidc_pending']=$pending;
    $_SESSION['microsoft_oidc_pending']['created_at']=time()-601;
    ob_start();try{$callback->callback();}finally{ob_end_clean();}
    $assert(http_response_code()===403 && $calls===2 && !isset($_SESSION['auth']),'expired callback state rejected');
    $_SESSION['microsoft_oidc_pending']=$pending;putenv('MICROSOFT_CLIENT_SECRET=');
    ob_start();try{$callback->callback();}finally{ob_end_clean();}
    $assert(http_response_code()===403 && $calls===2 && !isset($_SESSION['auth']),'missing configuration fails closed before token exchange');
} finally {
    foreach($savedEnv as $name=>$value){putenv($value===false?$name:$name.'='.$value);}
    $_SESSION=$savedSession;$_GET=$savedGet;
}
echo 'PASS: '.$checks.' RSA/JWKS offline validation checks'.PHP_EOL;
