<?php
declare(strict_types=1);
namespace App\Core;

final class SessionManager
{
    public const IDLE_TIMEOUT_SECONDS = 1800;
    public const ABSOLUTE_TIMEOUT_SECONDS = 28800;

    public function establish(int $userId, int $authVersion, ?int $now = null): void
    {
        $this->requireActiveSession();
        if ($userId < 1 || $authVersion < 0) throw new \InvalidArgumentException('Invalid authentication claims.');
        if (!session_regenerate_id(true)) throw new \RuntimeException('Session rotation failed.');
        $_SESSION = [];
        $now ??= time();
        $_SESSION['auth'] = [
            'user_id' => $userId,
            'auth_version' => $authVersion,
            'authenticated_at' => $now,
            'last_activity' => $now,
        ];
        Csrf::rotate();
    }

    /** @return array{user_id:int,auth_version:int,authenticated_at:int,last_activity:int}|null */
    public function claims(?int $now = null, int $idleTimeout = self::IDLE_TIMEOUT_SECONDS, int $absoluteTimeout = self::ABSOLUTE_TIMEOUT_SECONDS): ?array
    {
        $this->requireActiveSession();
        $claims = $_SESSION['auth'] ?? null;
        if (!is_array($claims)) return null;
        $required = ['user_id', 'auth_version', 'authenticated_at', 'last_activity'];
        foreach ($required as $key) {
            if (!isset($claims[$key]) || !is_int($claims[$key])) {
                $this->invalidateAuthentication();
                return null;
            }
        }
        if ($claims['user_id'] < 1 || $claims['auth_version'] < 0) {
            $this->invalidateAuthentication();
            return null;
        }
        $now ??= time();
        if ($idleTimeout < 1 || $absoluteTimeout < 1 || $claims['authenticated_at'] > $now || $claims['last_activity'] > $now
            || $now - $claims['last_activity'] >= $idleTimeout || $now - $claims['authenticated_at'] >= $absoluteTimeout) {
            $this->invalidateAuthentication();
            return null;
        }
        $_SESSION['auth']['last_activity'] = $now;
        return [
            'user_id' => $claims['user_id'],
            'auth_version' => $claims['auth_version'],
            'authenticated_at' => $claims['authenticated_at'],
            'last_activity' => $now,
        ];
    }

    public function invalidateAuthentication(): ?array
    {
        $this->requireActiveSession();
        $old = $_SESSION['auth'] ?? null;
        if (session_regenerate_id(true)) {
            $_SESSION = [];
            Csrf::rotate();
        } else {
            unset($_SESSION['auth']);
            Csrf::rotate();
        }
        return is_array($old) ? $old : null;
    }

    public function destroy(): void
    {
        $this->requireActiveSession();
        $_SESSION = [];
        $params = session_get_cookie_params();
        if (!headers_sent() && ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'],
                'secure' => (bool)$params['secure'],
                'httponly' => (bool)$params['httponly'],
                'samesite' => $params['samesite'] ?: 'Lax',
            ]);
        }
        session_destroy();
    }

    private function requireActiveSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) throw new \RuntimeException('Session is not active.');
    }
}
