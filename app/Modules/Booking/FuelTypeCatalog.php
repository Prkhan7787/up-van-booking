<?php
declare(strict_types=1);
namespace App\Modules\Booking;

/** Exact Bangchak OilName identifiers currently returned by the official feed. */
final class FuelTypeCatalog
{
    public const TYPES=[
        'ดีเซล B20','ไฮดีเซล S','ไฮ พรีเมียม ดีเซล พลัส','ไฮ พรีเมียม 98 พลัส',
        'แก๊สโซฮอล์ E85 S EVO','แก๊สโซฮอล์ E20 S EVO','แก๊สโซฮอล์ 91 S EVO','แก๊สโซฮอล์ 95 S EVO',
    ];
    public static function contains(string $value): bool { return in_array($value,self::TYPES,true); }
}
