<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\View;
use App\Services\MicrosoftOidcConfiguration;
use App\Services\MicrosoftIdTokenValidator;

final class MicrosoftLoginController
{
    /** Transport injection is for offline tests; normal web routing uses HTTPS methods below. */
    public function __construct(private readonly ?\Closure $tokenExchange = null, private readonly ?\Closure $jwksProvider = null) {}

    public function start(): void
    {
        $config = MicrosoftOidcConfiguration::fromRuntimeEnvironment();
        if ($config === null) {
            http_response_code(503);
            View::render('auth/unavailable', [
                'pageTitle'=>'Microsoft 365 ยังไม่ได้ตั้งค่า',
                'message'=>'การเข้าสู่ระบบ Microsoft 365 ยังไม่พร้อมให้บริการ กรุณาติดต่อผู้ดูแล',
            ]);
            return;
        }
        try {
            $state = self::randomToken();
            $nonce = self::randomToken();
            $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $_SESSION['microsoft_oidc_pending'] = [
                'state'=>$state, 'nonce'=>$nonce, 'verifier'=>$verifier, 'created_at'=>time(),
            ];
            header('Cache-Control: no-store');
            header('Location: '.$config->authorizationUrl($state, $nonce, $verifier), true, 302);
        } catch (\Throwable) {
            http_response_code(503);
            View::render('auth/unavailable', [
                'pageTitle'=>'เริ่มการเข้าสู่ระบบไม่ได้',
                'message'=>'ไม่สามารถเริ่มการเข้าสู่ระบบ Microsoft 365 ได้ กรุณาลองใหม่ภายหลัง',
            ]);
        }
    }

    public function callback(): void
    {
        $pending=$_SESSION['microsoft_oidc_pending'] ?? null;
        unset($_SESSION['microsoft_oidc_pending']); // consume before any network operation; callback is single-use
        $config=MicrosoftOidcConfiguration::fromRuntimeEnvironment();
        $state=$_GET['state'] ?? null; $code=$_GET['code'] ?? null;
        if ($config===null || !is_array($pending) || !is_string($state) || !is_string($code)
            || $code==='' || strlen($code)>4096 || !is_string($pending['state']??null)
            || !is_string($pending['nonce']??null) || !is_string($pending['verifier']??null)
            || !is_int($pending['created_at']??null) || $pending['created_at']>time()
            || time()-$pending['created_at']>600 || !hash_equals($pending['state'],$state)) {
            $this->denied(); return;
        }
        try {
            if (!class_exists(\Firebase\JWT\JWT::class) || ($this->tokenExchange===null && !function_exists('curl_init'))) { $this->denied(); return; }
            $token=$this->tokenExchange!==null ? ($this->tokenExchange)($config,$code,$pending['verifier']) : $this->postToken($config,$code,$pending['verifier']);
            $keys=$this->jwksProvider!==null ? ($this->jwksProvider)($config) : $this->fetchJwks($config);
            $claims=(new MicrosoftIdTokenValidator())->validate($token,$keys,$config,$pending['nonce']);
            // Factory remains gated and fails closed if schema or reviewed grants are absent.
            $mapping=\App\Services\MicrosoftIdentityMappingFactory::create();
            $state=$mapping->resolveIdentity($claims->tid,$claims->oid);
            if ($state===null) { $this->denied(); return; }
            (new \App\Core\SessionManager())->establish($state->userId,$state->authVersion);
            header('Location: /dashboard',true,303);
        } catch (\Throwable) { $this->denied(); }
    }

    private function postToken(MicrosoftOidcConfiguration $config,string $code,string $verifier): string
    {
        $ch=curl_init('https://login.microsoftonline.com/'.$config->tenantId.'/oauth2/v2.0/token');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query([
            'client_id'=>$config->clientId,'client_secret'=>$config->clientSecret,'grant_type'=>'authorization_code',
            'code'=>$code,'redirect_uri'=>$config->redirectUri,'code_verifier'=>$verifier,
        ],'','&',PHP_QUERY_RFC3986),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/x-www-form-urlencoded']]);
        $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
        if (!is_string($body)||$status!==200) throw new \RuntimeException('OIDC_TOKEN_EXCHANGE_FAILED');
        $json=json_decode($body,true,16,JSON_THROW_ON_ERROR); unset($body);
        if (!is_array($json)||!is_string($json['id_token']??null)||strlen($json['id_token'])>32768) throw new \RuntimeException('OIDC_TOKEN_RESPONSE_INVALID');
        return $json['id_token'];
    }

    private function fetchJwks(MicrosoftOidcConfiguration $config): array
    {
        $ch=curl_init('https://login.microsoftonline.com/'.$config->tenantId.'/discovery/v2.0/keys');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
        $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
        if (!is_string($body)||$status!==200) throw new \RuntimeException('OIDC_JWKS_FETCH_FAILED');
        $json=json_decode($body,true,16,JSON_THROW_ON_ERROR); unset($body);
        if (!is_array($json)||!is_array($json['keys']??null)) throw new \RuntimeException('OIDC_JWKS_INVALID');
        return $json;
    }

    private function denied(): void
    {
        http_response_code(403);
        View::render('auth/unavailable',['pageTitle'=>'ไม่สามารถเข้าสู่ระบบได้','message'=>'ไม่พบบัญชีภายในที่ผู้ดูแลอนุญาตให้เชื่อมกับบัญชี Microsoft นี้']);
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
