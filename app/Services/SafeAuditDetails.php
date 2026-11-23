<?php
declare(strict_types=1);
namespace App\Services;

/** Never renders arbitrary stored metadata, even under innocuous-looking keys. */
final class SafeAuditDetails
{
    public static function extract(string $action, mixed $metadata): array
    {
        if (is_string($metadata)) { try {$metadata=json_decode($metadata,true,16,JSON_THROW_ON_ERROR);} catch (\Throwable) {return [];} }
        if (!is_array($metadata)) return [];
        $out=[];
        if ($action==='admin.user.created') {
            $out['roles']=self::roles($metadata['roles'] ?? null);
        } elseif (in_array($action,['admin.user.roles_changed','admin.user.status_changed'],true)) {
            foreach (['before','after'] as $key) {
                $value=$metadata[$key] ?? null;
                if (!is_array($value)) continue;
                $out[$key]=['roles'=>self::roles($value['roles'] ?? null)];
                if (isset($value['active']) && is_bool($value['active'])) $out[$key]['active']=$value['active'];
            }
        }
        return $out;
    }
    private static function roles(mixed $roles): array
    {
        if (!is_array($roles)) return [];
        return array_values(array_unique(array_filter($roles,static fn(mixed $role): bool=>is_string($role) && isset(RoleLabels::ALL[$role]))));
    }
}
