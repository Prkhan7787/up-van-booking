<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Env;

final class MicrosoftOidcConfiguration
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
    ) {}

    public static function fromEnvironment(array $environment): ?self
    {
        if (($environment['MICROSOFT_LOGIN_ENABLED'] ?? null) !== 'true') return null;
        $tenant = strtolower(trim((string)($environment['MICROSOFT_TENANT_ID'] ?? '')));
        $client = strtolower(trim((string)($environment['MICROSOFT_CLIENT_ID'] ?? '')));
        $secret = (string)($environment['MICROSOFT_CLIENT_SECRET'] ?? '');
        $redirect = trim((string)($environment['MICROSOFT_REDIRECT_URI'] ?? ''));
        $uuid = '/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D';
        if (!preg_match($uuid, $tenant) || !preg_match($uuid, $client) || $secret === '' || strlen($secret) > 4096 || $redirect === '') return null;
        $parts = parse_url($redirect);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || !in_array($parts['scheme'], ['https', 'http'], true)
            || ($parts['scheme'] !== 'https' && !in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return null;
        return new self($tenant, $client, $secret, $redirect);
    }

    public static function fromRuntimeEnvironment(): ?self
    {
        return self::fromEnvironment([
            'MICROSOFT_LOGIN_ENABLED'=>Env::get('MICROSOFT_LOGIN_ENABLED'),
            'MICROSOFT_TENANT_ID'=>Env::get('MICROSOFT_TENANT_ID'),
            'MICROSOFT_CLIENT_ID'=>Env::get('MICROSOFT_CLIENT_ID'),
            'MICROSOFT_CLIENT_SECRET'=>Env::get('MICROSOFT_CLIENT_SECRET'),
            'MICROSOFT_REDIRECT_URI'=>Env::get('MICROSOFT_REDIRECT_URI'),
        ]);
    }

    public function authorizationUrl(string $state, string $nonce, string $verifier): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = http_build_query([
            'client_id'=>$this->clientId,
            'response_type'=>'code',
            'redirect_uri'=>$this->redirectUri,
            'response_mode'=>'query',
            'scope'=>'openid profile email',
            'state'=>$state,
            'nonce'=>$nonce,
            'code_challenge'=>$challenge,
            'code_challenge_method'=>'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        return 'https://login.microsoftonline.com/'.$this->tenantId.'/oauth2/v2.0/authorize?'.$query;
    }
}
