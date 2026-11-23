<?php
declare(strict_types=1);
namespace App\Modules\Booking;

/** Untrusted GET preview only. Never persist or use to approve a booking. */
final class TravelSnapshot
{
    public static function preview(array $query, string $vehicleFuelType=''): ?array
    {
        foreach(['origin_lat','origin_lng','destination_lat','destination_lng','route_outbound_m','route_return_m','route_duration_ms','route_return_duration_ms'] as $key)
            if(!isset($query[$key]) || !is_string($query[$key]))return null;
        $originLat=self::coordinate($query['origin_lat'],-90,90);$originLng=self::coordinate($query['origin_lng'],-180,180);
        $destinationLat=self::coordinate($query['destination_lat'],-90,90);$destinationLng=self::coordinate($query['destination_lng'],-180,180);
        if($originLat===null || $originLng===null || $destinationLat===null || $destinationLng===null)return null;
        $out=self::routeMeasure($query['route_outbound_m'],2000000);$return=self::routeMeasure($query['route_return_m'],2000000);
        $outDuration=self::routeMeasure($query['route_duration_ms'],604800000);$returnDuration=self::routeMeasure($query['route_return_duration_ms'],604800000);
        if($out===null || $return===null || $outDuration===null || $returnDuration===null)return null;
        if(($query['route_source']??null)!=='osrm')return null;
        if(!is_string($query['origin']??null) || trim($query['origin'])==='' || self::length($query['origin'])>160)return null;
        $destinationSource=$query['destination_source']??null;
        $destination=$query['destination']??'';
        if(!is_string($destinationSource) || !in_array($destinationSource,['map','search'],true) || !is_string($destination) || self::length($destination)>500)return null;
        $destination=trim($destination);
        if($destinationSource==='map' && $destination!=='')return null;
        if($destinationSource==='search' && ($destination==='' || $destination==='จุดที่เลือกบนแผนที่'))return null;
        $type=$query['fuel_type']??'';
        if(!is_string($type) || self::length($type)>100 || ($type!=='' && $type!==$vehicleFuelType))return null;
        $reference=$type!==''?FuelEconomyReferenceCatalog::forFuelType($vehicleFuelType):null;
        if(isset($query['fuel_efficiency_reference_km_per_l']) && $reference!==null) {
            $passed=self::optionalPositive($query['fuel_efficiency_reference_km_per_l'],100);
            if($passed===null || abs($passed-$reference['mean'])>0.0001)return null;
        }
        $price=self::optionalPositive($query['fuel_price_baht_per_l']??null,200);
        $priceDate=$query['fuel_price_date']??null;
        if($priceDate!==null && (!is_string($priceDate) || self::length($priceDate)>80 || preg_match('/[<>\\x00-\\x1F]/u',$priceDate)))return null;
        if($price!==null && (($query['fuel_price_source']??null)!=='bangchak_oil_price_api' || !is_string($priceDate) || trim($priceDate)==='')) {$price=null;$priceDate=null;}
        $duration=$outDuration+$returnDuration;
        $efficiency=$reference!==null && $reference['usable']===true ? (float)$reference['mean'] : null;
        $numbers=TravelEstimate::calculate($out,$return,$duration,$efficiency,$price);
        $litresMin=$reference!==null && $reference['usable']===true ? $numbers['round_trip_km']/$reference['max'] : null;
        $litresMax=$reference!==null && $reference['usable']===true ? $numbers['round_trip_km']/$reference['min'] : null;
        return $numbers+['origin'=>$query['origin'],'destination'=>$destination,'destination_source'=>$destinationSource,
            'origin_lat'=>$originLat,'origin_lng'=>$originLng,'destination_lat'=>$destinationLat,'destination_lng'=>$destinationLng,
            'return_km'=>$return/1000,'outbound_duration_minutes'=>$outDuration/60000,'return_duration_minutes'=>$returnDuration/60000,
            'outbound_m'=>$out,'return_m'=>$return,'outbound_duration_ms'=>$outDuration,'return_duration_ms'=>$returnDuration,
            'fuel_type'=>$type!==''?$type:null,'fuel_efficiency'=>$efficiency,
            'fuel_efficiency_min'=>$reference!==null && $reference['usable']===true?$reference['min']:null,
            'fuel_efficiency_max'=>$reference!==null && $reference['usable']===true?$reference['max']:null,
            'fuel_reference_source'=>$reference!==null && $reference['usable']===true?$reference['source_url']:null,
            'fuel_price_baht_per_l'=>$price,'fuel_price_date'=>$priceDate,
            'fuel_price_source'=>($query['fuel_price_source']??null)==='bangchak_oil_price_api'?TravelEstimate::PRICE_SOURCE:null,
            'fuel_litres_min'=>$litresMin,'fuel_litres_max'=>$litresMax,
            'fuel_cost_min'=>$litresMin!==null && $price!==null?$litresMin*$price:null,
            'fuel_cost_max'=>$litresMax!==null && $price!==null?$litresMax*$price:null,
            'route_source'=>'osrm','verified'=>false];
    }
    private static function length(string $value): int
    {
        if(function_exists('mb_strlen'))return mb_strlen($value);
        $matches=preg_match_all('/./us',$value);
        return $matches===false?PHP_INT_MAX:$matches;
    }
    private static function coordinate(string $value,float $min,float $max): ?float
    {
        if(!preg_match('/^-?\\d{1,3}(?:\\.\\d{1,8})?$/D',$value))return null;
        $number=(float)$value;
        return is_finite($number) && $number>=$min && $number<=$max?$number:null;
    }
    private static function routeMeasure(string $value,int $max): ?int
    {
        if(!preg_match('/^\\d{1,10}(?:\\.\\d{1,3})?$/D',$value))return null;
        $number=(float)$value;
        return is_finite($number) && $number>0 && $number<=$max?(int)round($number):null;
    }
    private static function optionalPositive(mixed $value,float $max): ?float
    {
        if(!is_string($value) || $value==='' || !is_numeric($value))return null;
        $number=(float)$value;
        return is_finite($number) && $number>0 && $number<=$max?$number:null;
    }
}
