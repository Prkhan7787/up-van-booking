<?php
declare(strict_types=1);
namespace App\Modules\Booking;

/** Read-only calculation and strict parsing of the official Bangchak response. */
final class TravelEstimate
{
    public const ORIGIN_LAT=19.031572;
    public const ORIGIN_LNG=99.893832;
    public const PRICE_SOURCE='https://oil-price.bangchak.co.th/ApiOilPrice2/th';

    public static function calculate(int $oneWayMeters,int $returnMeters,int $durationMillis,?float $kmPerLitre,?float $bahtPerLitre): array
    {
        if($oneWayMeters<0 || $returnMeters<0 || $durationMillis<0)throw new \InvalidArgumentException('ROUTE_MEASURE_INVALID');
        if($kmPerLitre!==null && (!is_finite($kmPerLitre) || $kmPerLitre<=0))throw new \InvalidArgumentException('FUEL_EFFICIENCY_INVALID');
        if($bahtPerLitre!==null && (!is_finite($bahtPerLitre) || $bahtPerLitre<=0))throw new \InvalidArgumentException('FUEL_PRICE_INVALID');
        $litres=$kmPerLitre===null?null:(($oneWayMeters+$returnMeters)/1000)/$kmPerLitre;
        return ['one_way_km'=>$oneWayMeters/1000,'round_trip_km'=>($oneWayMeters+$returnMeters)/1000,
            'duration_minutes'=>$durationMillis/60000,'fuel_litres'=>$litres,
            'fuel_cost_baht'=>$litres!==null && $bahtPerLitre!==null?$litres*$bahtPerLitre:null];
    }

    public static function bangchakPrices(string $json, int $now=0): array
    {
        $now=$now ?: time(); $zone=new \DateTimeZone('Asia/Bangkok');
        $current=(new \DateTimeImmutable('@'.$now))->setTimezone($zone);
        $outer=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($outer) || !isset($outer[0]) || !is_array($outer[0]))throw new \UnexpectedValueException('FUEL_RESPONSE_INVALID');
        $record=$outer[0];
        if(!is_string($record['OilList']??null) || !is_string($record['OilPriceDate']??null))throw new \UnexpectedValueException('FUEL_RESPONSE_INVALID');
        $parseDate=static function(string $date,string $clock='00:00')use($zone): \DateTimeImmutable {
            if(!preg_match('~^(\d{2})/(\d{2})/(\d{4})$~D',$date,$m))throw new \UnexpectedValueException('FUEL_DATE_INVALID');
            $year=(int)$m[3]; if($year>=2400)$year-=543;
            $value=sprintf('%04d-%s-%s %s',$year,$m[2],$m[1],$clock);
            $result=\DateTimeImmutable::createFromFormat('!Y-m-d H:i',$value,$zone);
            if(!$result || $result->format('Y-m-d H:i')!==$value)throw new \UnexpectedValueException('FUEL_DATE_INVALID');
            return $result;
        };
        $sourceDate=$parseDate((string)($record['OilDateNow']??''));
        // A price may remain unchanged for days. Freshness uses the feed's own date,
        // independently of the publication/effective dates; accept today/yesterday only.
        if($sourceDate>$current || $sourceDate<$current->setTime(0,0)->modify('-1 day'))throw new \UnexpectedValueException('FUEL_SOURCE_STALE');
        $published=$parseDate($record['OilPriceDate'],(string)($record['OilPriceTime']??''));
        $remark=preg_replace('/\s+/u',' ',(string)($record['OilRemark2']??''));
        $months=['ม.ค.'=>1,'ก.พ.'=>2,'มี.ค.'=>3,'เม.ย.'=>4,'พ.ค.'=>5,'มิ.ย.'=>6,'ก.ค.'=>7,'ส.ค.'=>8,'ก.ย.'=>9,'ต.ค.'=>10,'พ.ย.'=>11,'ธ.ค.'=>12];
        if(!preg_match('/วันที่ (\d{1,2}) (\S+) (\d{2}|\d{4}) เวลา (\d{2})[.:](\d{2})/u',$remark,$m) || !isset($months[$m[2]]))throw new \UnexpectedValueException('FUEL_EFFECTIVE_DATE_UNCONFIRMED');
        $year=(int)$m[3]; if($year<100)$year+=2500;
        $effective=$parseDate(sprintf('%02d/%02d/%04d',(int)$m[1],$months[$m[2]],$year),$m[4].':'.$m[5]);
        if($published>$current || $effective>$current || $published>$effective)throw new \UnexpectedValueException('FUEL_DATE_INCONSISTENT');
        $items=json_decode($record['OilList'],true,16,JSON_THROW_ON_ERROR);
        if(!is_array($items) || $items===[])throw new \UnexpectedValueException('FUEL_LIST_INVALID');
        $prices=[];
        foreach($items as $item){
            if(!is_array($item) || !is_string($item['OilName']??null) || !is_numeric($item['PriceToday']??null))continue;
            $name=$item['OilName'];$price=(float)$item['PriceToday'];
            if(!FuelTypeCatalog::contains($name) || mb_strlen($name)>100 || !is_finite($price) || $price<=0 || $price>200)continue;
            if(isset($prices[$name]))throw new \UnexpectedValueException('FUEL_DUPLICATE_NAME');
            $prices[$name]=$price;
        }
        if($prices===[])throw new \UnexpectedValueException('FUEL_PRICES_UNAVAILABLE');
        $effectiveNote=$record['OilRemark2']??null;
        return ['prices'=>$prices,'price_record_date'=>mb_substr($record['OilPriceDate'],0,40),
            'effective_note'=>is_string($effectiveNote)?mb_substr(trim($effectiveNote),0,160):null,
            'effective_at'=>$effective->format(DATE_ATOM),'published_at'=>$published->format(DATE_ATOM),'source_date'=>$sourceDate->format('Y-m-d'),'updated_today'=>$sourceDate->format('Y-m-d')===$current->format('Y-m-d'),'source'=>self::PRICE_SOURCE,'area'=>'ราคาขายปลีกอ้างอิงกรุงเทพฯ อาจต่างจากพื้นที่อื่น'];
    }
}
