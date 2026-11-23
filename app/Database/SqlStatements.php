<?php
declare(strict_types=1);
namespace App\Database;

/** Small SQL-file lexer for this project's reviewed DDL/seeds; no DELIMITER/procedures. */
final class SqlStatements
{
    public static function split(string $sql): array
    {
        $statements = []; $buffer = ''; $quote = null; $comment = null;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $c = $sql[$i]; $next = $sql[$i + 1] ?? '';
            if ($comment === 'line') {
                if ($c === "\n") { $comment = null; $buffer .= "\n"; }
                continue;
            }
            if ($comment === 'block') {
                if ($c === '*' && $next === '/') { $comment = null; $i++; $buffer .= ' '; }
                continue;
            }
            if ($quote !== null) {
                $buffer .= $c;
                if ($c === '\\') { if ($next !== '') $buffer .= $sql[++$i]; continue; }
                if ($c === $quote) {
                    if ($next === $quote) $buffer .= $sql[++$i]; else $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $buffer .= $c; continue; }
            if ($c === '#' || ($c === '-' && $next === '-' && (($sql[$i + 2] ?? ' ') <= ' '))) { $comment = 'line'; continue; }
            if ($c === '/' && $next === '*') {
                if (($sql[$i + 2] ?? '') === '!') throw new SafetyException('Executable SQL comments are not allowed.');
                $comment = 'block'; $i++; continue;
            }
            if ($c === ';') { if (trim($buffer) !== '') $statements[] = trim($buffer); $buffer = ''; continue; }
            $buffer .= $c;
        }
        if ($quote !== null || $comment === 'block') throw new SafetyException('Unterminated SQL token.');
        if (trim($buffer) !== '') $statements[] = trim($buffer);
        return $statements;
    }
}
