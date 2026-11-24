<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/app.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
    $checks++;
};
$base = ['MICROSOFT_TENANT_ID'=>'11111111-1111-4111-8111-111111111111','MICROSOFT_CLIENT_ID'=>'22222222-2222-4222-8222-222222222222','MICROSOFT_CLIENT_SECRET'=>'offline-only-secret','MICROSOFT_REDIRECT_URI'=>'https://booking.example.test/auth/microsoft/callback'];
$assert(App\Services\MicrosoftOidcConfiguration::fromEnvironment([]) === null, 'missing configuration fails closed');
$assert(App\Services\MicrosoftOidcConfiguration::fromEnvironment($base) instanceof App\Services\MicrosoftOidcConfiguration, 'valid configuration accepted');
$bad=$base; $bad['MICROSOFT_TENANT_ID']='common';
$assert(App\Services\MicrosoftOidcConfiguration::fromEnvironment($bad) === null, 'non-single-tenant authority rejected');
$bad=$base; $bad['MICROSOFT_REDIRECT_URI']='http://booking.example.test/callback';
$assert(App\Services\MicrosoftOidcConfiguration::fromEnvironment($bad) === null, 'non-local HTTP redirect rejected');
$config=App\Services\MicrosoftOidcConfiguration::fromEnvironment($base);
$state='state-fixture'; $nonce='nonce-fixture'; $verifier='verifier-fixture-abcdefghijklmnopqrstuvwxyz0123456789';
$url=$config->authorizationUrl($state,$nonce,$verifier); $parts=parse_url($url); parse_str((string)($parts['query']??''),$query);
$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
$assert(($parts['scheme']??null)==='https' && ($parts['host']??null)==='login.microsoftonline.com', 'authorization URL uses Microsoft HTTPS host');
$assert(($parts['path']??null)==='/'.$base['MICROSOFT_TENANT_ID'].'/oauth2/v2.0/authorize', 'authorization URL is tenant-specific');
$assert(($query['response_type']??null)==='code' && ($query['response_mode']??null)==='query', 'authorization code flow selected');
$assert(($query['state']??null)===$state && ($query['nonce']??null)===$nonce, 'state and nonce included');
$assert(($query['code_challenge_method']??null)==='S256' && ($query['code_challenge']??null)===$challenge, 'PKCE S256 challenge matches verifier');
$routes=require dirname(__DIR__).'/routes/web.php';
$assert(isset($routes['GET /auth/microsoft/start']), 'Microsoft start endpoint routed');
$assert(!isset($routes['GET /driver/register'],$routes['POST /driver/register']), 'public driver registration routes remain closed');
$view=file_get_contents(dirname(__DIR__).'/app/Views/auth/login.php');
$assert(str_contains($view,'href="/auth/microsoft/start"'), 'login button links to real start endpoint');
echo 'PASS: '.$checks.' Microsoft login start offline checks'.PHP_EOL;
