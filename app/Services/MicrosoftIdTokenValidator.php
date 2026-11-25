<?php
declare(strict_types=1);
namespace App\Services;

final class MicrosoftIdTokenValidator
{
    /** @param array<string,mixed> $jwks @return object verified claims */
    public function validate(string $jwt,array $jwks,MicrosoftOidcConfiguration $config,string $expectedNonce): object
    {
        if (!class_exists(\Firebase\JWT\JWT::class) || !class_exists(\Firebase\JWT\JWK::class)) {
            throw new \RuntimeException('OIDC_JWT_LIBRARY_MISSING');
        }
        if ($jwt===''||strlen($jwt)>32768||$expectedNonce==='') throw new \UnexpectedValueException('OIDC_TOKEN_INVALID');
        // A parser default does not override an algorithm supplied by the JWKS.
        // Only trusted RSA signing keys for RS256 may reach the library decoder.
        $allowed=[];
        foreach ($jwks['keys'] ?? [] as $key) {
            if (!is_array($key) || ($key['kty'] ?? null)!=='RSA'
                || ($key['alg'] ?? 'RS256')!=='RS256' || ($key['use'] ?? 'sig')!=='sig'
                || (isset($key['key_ops']) && (!is_array($key['key_ops']) || !in_array('verify',$key['key_ops'],true)))
                || !is_string($key['kid'] ?? null) || $key['kid']==='') continue;
            if (isset($allowed[$key['kid']])) throw new \UnexpectedValueException('OIDC_JWKS_DUPLICATE_KEY');
            $key['alg']='RS256';
            $allowed[$key['kid']]=$key;
        }
        if ($allowed===[]) throw new \UnexpectedValueException('OIDC_JWKS_NO_RS256_KEYS');
        $keys=\Firebase\JWT\JWK::parseKeySet(['keys'=>array_values($allowed)],'RS256');
        $claims=\Firebase\JWT\JWT::decode($jwt,$keys);
        $issuer='https://login.microsoftonline.com/'.$config->tenantId.'/v2.0';
        $aud=$claims->aud??null;
        $audiences=is_array($aud)?$aud:[$aud];
        if (($claims->iss??null)!==$issuer || !is_string($claims->tid??null) || strtolower($claims->tid)!==$config->tenantId
            || !is_string($claims->oid??null) || !preg_match('/\A[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}\z/D',$claims->oid)
            || !in_array($config->clientId,$audiences,true) || (count($audiences)>1 && ($claims->azp??null)!==$config->clientId)
            || !is_string($claims->nonce??null) || !hash_equals($expectedNonce,$claims->nonce)
            || !is_numeric($claims->exp??null) || (int)$claims->exp<=time()
            || !is_numeric($claims->iat??null) || (int)$claims->iat>time()+60
            || (isset($claims->nbf) && (!is_numeric($claims->nbf)||(int)$claims->nbf>time()+60))) {
            throw new \UnexpectedValueException('OIDC_TOKEN_CLAIMS_REJECTED');
        }
        return $claims;
    }
}
