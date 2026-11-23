<?php
declare(strict_types=1);
namespace App\Database;

use PDO;
use PDOException;

/** Evaluates direct, table/column-scoped, and explicitly resolved role privileges. */
final class GrantVerifier
{
    /** Match only complete, previously reviewed module profiles; never widen a profile. */
    public static function evaluateProfiles(array $grants, string $database, array $profiles, bool $partialRevokes, bool $rolesResolved): array
    {
        if ($profiles===[]) throw new \InvalidArgumentException('GRANT_PROFILES_REQUIRED');
        $best=null;
        foreach ($profiles as $name=>$rules) {
            $result=self::evaluate($grants,$database,$rules,$partialRevokes,$rolesResolved);
            $result['profile']=$name;
            if ($result['status']==='PASS') return $result;
            $score=[count($result['unexpected_privileges']),count($result['missing_privileges'])];
            if ($best===null || $score < $best['score']) $best=['score'=>$score,'result'=>$result];
        }
        return $best['result'];
    }
    /**
     * Read current-session grants, including currently active roles and mandatory roles.
     * Uses only SELECT and SHOW statements. A role parse/resolution failure is fail-closed.
     * @return array{grants:list<string>,roles_resolved:bool,active_roles:string}
     */
    public static function currentUserGrants(PDO $pdo): array
    {
        $direct = $pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
        $mandatory = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        $active = (string)($pdo->query('SELECT CURRENT_ROLE()')->fetchColumn() ?: 'NONE');
        $grants = array_values(array_unique(array_merge($direct, $mandatory)));
        if ($active === 'NONE') return ['grants'=>$grants,'roles_resolved'=>true,'active_roles'=>$active];

        // CURRENT_ROLE() returns a SQL-formatted list of account names. Accept only
        // that grammar before embedding it as identifiers in SHOW GRANTS ... USING.
        $quoted = "(?:`(?:[^`\\\\]|\\\\.)*`|'(?:[^'\\\\]|\\\\.)*')";
        $account = $quoted.'@'.$quoted;
        if (!preg_match('/\A'.$account.'(?:,'.$account.')*\z/D', $active)) {
            return ['grants'=>$grants,'roles_resolved'=>false,'active_roles'=>'PRESENT'];
        }
        try {
            $expanded = $pdo->query('SHOW GRANTS FOR CURRENT_USER USING '.$active)->fetchAll(PDO::FETCH_COLUMN);
            return ['grants'=>array_values(array_unique(array_merge($grants, $expanded))), 'roles_resolved'=>true, 'active_roles'=>'PRESENT'];
        } catch (PDOException) {
            return ['grants'=>$grants,'roles_resolved'=>false,'active_roles'=>'PRESENT'];
        }
    }

    /**
     * Rules support table-wide lists (legacy) or column maps, e.g.
     * ['users'=>['SELECT'=>['id','username'],'UPDATE'=>['password_hash']]].
     * Database-wide grants are checked separately and cannot silently satisfy a
     * least-privilege column rule.
     */
    public static function evaluate(array $grants, string $database, array $rules, bool $partialRevokes, bool $rolesResolved = false): array
    {
        $databasePrivileges = [];
        $tablePrivileges = [];
        $columnPrivileges = [];
        $unsupported = !$rolesResolved;
        $hardFailure = false;
        $reasonCodes = [];
        $expectedDbScope = $partialRevokes ? $database : str_replace('_', '\\_', $database);

        foreach ($grants as $grant) {
            if (!is_string($grant)) { $unsupported = true; $reasonCodes[] = 'unrecognized_grant_record'; continue; }
            if (stripos($grant, 'WITH GRANT OPTION') !== false) { $hardFailure = true; $reasonCodes[] = 'grant_option'; continue; }
            if (preg_match('/^GRANT\s+USAGE\s+ON\s+\*\.\*\s+TO\s+/i', $grant)) continue;
            if (preg_match('/^GRANT\s+.+\s+ON\s+\*\.\*\s+TO\s+/i', $grant)) { $hardFailure = true; $reasonCodes[] = 'global_privilege'; continue; }
            if (preg_match('/^GRANT\s+(?:`[^`]+`|\'[^\']+\')@(?:`[^`]+`|\'[^\']+\')(?:\s*,\s*(?:`[^`]+`|\'[^\']+\')@(?:`[^`]+`|\'[^\']+\'))*\s+TO\s+/i', $grant)) {
                if (!$rolesResolved) { $unsupported = true; $reasonCodes[] = 'role_assignment'; }
                continue;
            }
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+`([^`]+)`\.(\*|`[^`]+`)\s+TO\s+/i', $grant, $m)) {
                $schema = $m[2];
                $scope = $m[3];
                if ($scope === '*') {
                    if ($schema !== $expectedDbScope) { $hardFailure = true; $reasonCodes[] = 'other_or_wildcard_database'; continue; }
                } elseif ($schema !== $database && str_replace('\\_', '_', $schema) !== $database) {
                    $hardFailure = true; $reasonCodes[] = 'other_database_table_scope'; continue;
                }
                $table = $scope === '*' ? null : strtolower(substr($scope, 1, -1));
                foreach (self::parsePrivilegeClauses($m[1]) as $clause) {
                    [$privilege, $columns] = $clause;
                    if ($table === null) $databasePrivileges[$privilege] = true;
                    elseif ($columns === null) $tablePrivileges[$table][$privilege] = true;
                    else foreach ($columns as $column) $columnPrivileges[$table][$privilege][$column] = true;
                }
                continue;
            }
            if (preg_match('/^REVOKE\s+/i', $grant)) {
                // Partial revokes alter effective database-level privileges; require
                // a resolver extension instead of interpreting these as absent grants.
                $unsupported = true; $reasonCodes[] = 'partial_revoke_resolution_required'; continue;
            }
            $unsupported = true;
            $reasonCodes[] = 'unsupported_grant_form';
        }

        $dbRules = array_fill_keys(array_map('strtoupper', $rules['database'] ?? []), true);
        $requiredTables = [];
        foreach (($rules['tables'] ?? []) as $table => $privileges) {
            $table = strtolower((string)$table);
            if (array_is_list($privileges)) {
                $requiredTables[$table] = ['table'=>array_fill_keys(array_map('strtoupper', $privileges), true), 'columns'=>[]];
            } else {
                $columns = [];
                $tableRules = [];
                foreach ($privileges as $privilege => $names) {
                    // Explicit null requires a table-scoped privilege (e.g. DELETE,
                    // which MySQL cannot grant at column scope).
                    if ($names === null) { $tableRules[strtoupper((string)$privilege)] = true; continue; }
                    if (!is_array($names)) { $unsupported = true; $reasonCodes[] = 'invalid_column_rule'; continue; }
                    $columns[strtoupper((string)$privilege)] = array_fill_keys(array_map(static fn($v): string => strtolower((string)$v), $names), true);
                }
                $requiredTables[$table] = ['table'=>$tableRules, 'columns'=>$columns];
            }
        }

        $missing = [];
        $unexpected = [];
        foreach (array_keys($databasePrivileges) as $privilege) if (!isset($dbRules[$privilege])) $unexpected[] = 'database.*:'.$privilege;
        foreach (array_keys($dbRules) as $privilege) if (!isset($databasePrivileges[$privilege])) $missing[] = 'database.*:'.$privilege;

        foreach ($tablePrivileges as $table => $privileges) {
            $allowed = $requiredTables[$table]['table'] ?? [];
            foreach (array_keys($privileges) as $privilege) {
                if (!isset($allowed[$privilege])) $unexpected[] = 'table.'.$table.':'.$privilege;
            }
        }
        foreach ($columnPrivileges as $table => $privileges) {
            $expectedColumns = $requiredTables[$table]['columns'] ?? [];
            foreach ($privileges as $privilege => $columns) {
                $allowedColumns=$expectedColumns[$privilege]??[];
                // Explicit opt-in only. A redundant legacy column cannot satisfy
                // a required table privilege or widen any existing profile.
                if(isset($requiredTables[$table]['table'][$privilege],$tablePrivileges[$table][$privilege])) {
                    foreach(($rules['optional_redundant_columns'][$table][$privilege]??[]) as $name)$allowedColumns[strtolower($name)]=true;
                }
                if ($allowedColumns===[]) {
                    foreach (array_keys($columns) as $column) $unexpected[] = 'column.'.$table.'.'.$column.':'.$privilege;
                    continue;
                }
                foreach (array_keys($columns) as $column) {
                    if (!isset($allowedColumns[$column])) $unexpected[] = 'column.'.$table.'.'.$column.':'.$privilege;
                }
            }
        }
        foreach ($requiredTables as $table => $required) {
            foreach (array_keys($required['table']) as $privilege) {
                if (!isset($tablePrivileges[$table][$privilege]) && !isset($databasePrivileges[$privilege])) $missing[] = 'table.'.$table.':'.$privilege;
            }
            foreach ($required['columns'] as $privilege => $columns) {
                foreach (array_keys($columns) as $column) {
                    if (!isset($tablePrivileges[$table][$privilege]) && !isset($databasePrivileges[$privilege]) && !isset($columnPrivileges[$table][$privilege][$column])) {
                        $missing[] = 'column.'.$table.'.'.$column.':'.$privilege;
                    }
                }
            }
        }

        if ($unexpected !== []) { $hardFailure = true; $reasonCodes[] = 'unexpected_privilege'; }
        if ($hardFailure) $status = 'FAIL';
        elseif ($unsupported) $status = 'UNVERIFIED';
        elseif ($missing !== []) { $status = 'FAIL'; $reasonCodes[] = 'missing_privilege'; }
        else $status = 'PASS';

        $effective = ['database'=>array_keys($databasePrivileges)];
        foreach ($requiredTables as $table => $_) {
            $effective[$table] = array_values(array_unique(array_merge(
                array_keys($databasePrivileges),
                array_keys($tablePrivileges[$table] ?? []),
                array_keys($columnPrivileges[$table] ?? []),
            )));
            sort($effective[$table]);
        }
        sort($effective['database']);
        return [
            'status'=>$status,
            'effective_privileges'=>$effective,
            'missing_privileges'=>array_values(array_unique($missing)),
            'unexpected_privileges'=>array_values(array_unique($unexpected)),
            'reason_codes'=>array_values(array_unique($reasonCodes)),
            'role_privileges_resolved'=>$rolesResolved,
        ];
    }

    /** @return list<array{0:string,1:list<string>|null}> */
    private static function parsePrivilegeClauses(string $expression): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        foreach (str_split($expression) as $char) {
            if ($char === '(') $depth++;
            if ($char === ')') $depth--;
            if ($char === ',' && $depth === 0) { $parts[] = trim($buffer); $buffer = ''; continue; }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') $parts[] = trim($buffer);
        $parsed = [];
        foreach ($parts as $part) {
            if (preg_match('/^([A-Z ]+)\s*\((.*)\)$/i', $part, $m)) {
                $privilege = strtoupper(trim($m[1]));
                $columns = array_map(static fn(string $column): string => strtolower(trim(trim($column), '` ')), explode(',', $m[2]));
                if ($columns === [] || in_array('', $columns, true)) continue;
                $parsed[] = [$privilege, $columns];
            } else {
                $privilege = strtoupper(trim($part));
                if ($privilege !== '') $parsed[] = [$privilege, null];
            }
        }
        return $parsed;
    }
}
