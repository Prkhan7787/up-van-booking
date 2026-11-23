<?php
declare(strict_types=1);
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** MySQL connections expose TIMESTAMP values in UTC; display them in the project timezone. */
function format_system_timestamp(?string $utcTimestamp): string
{
    if ($utcTimestamp === null || $utcTimestamp === '') return '—';
    $utc = new DateTimeZone('UTC');
    $local = new DateTimeZone('Asia/Bangkok');
    $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utcTimestamp, $utc);
    $errors = DateTimeImmutable::getLastErrors();
    if ($value === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) return '—';
    return $value->setTimezone($local)->format('d/m/Y H:i');
}
