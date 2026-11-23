<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use Closure;

/** Fetches only road geometry for display; provider distance and duration are discarded. */
final class RoadRouteGeometry
{
    private const ENDPOINT='https://router.project-osrm.org/route/v1/driving/';
    private const MAX_RESPONSE_BYTES=1048576;
    private const MAX_POINTS=5000;
    private readonly Closure $fetch;

    public function __construct(?callable $fetch=null)
    {
        $this->fetch=$fetch===null ? self::providerFetch(...) : Closure::fromCallable($fetch);
    }

    /** @return array{type:'LineString',coordinates:list<array{float,float}>} */
    public function between(mixed $originLat,mixed $originLng,mixed $destinationLat,mixed $destinationLng): array
    {
        $origin=self::point($originLat,$originLng);
        $destination=self::point($destinationLat,$destinationLng);
        $url=self::ENDPOINT.sprintf('%.6F,%.6F;%.6F,%.6F',$origin[1],$origin[0],$destination[1],$destination[0]).'?overview=full&geometries=geojson&steps=false&alternatives=false&radiuses=1000;1000';
        $body=($this->fetch)($url);
        if (!is_string($body) || strlen($body)>self::MAX_RESPONSE_BYTES) throw new \RuntimeException('ROUTE_PROVIDER_UNAVAILABLE');
        try {$response=json_decode($body,true,32,JSON_THROW_ON_ERROR);} catch (\JsonException) {throw new \RuntimeException('ROUTE_PROVIDER_INVALID_RESPONSE');}
        if (!is_array($response) || ($response['code']??null)!=='Ok') throw new \RuntimeException('ROUTE_NOT_AVAILABLE');
        if (($response['routes'][0]['geometry']['type']??null)!=='LineString') throw new \RuntimeException('ROUTE_GEOMETRY_INVALID');
        $coordinates=$response['routes'][0]['geometry']['coordinates']??null;
        if (!is_array($coordinates) || count($coordinates)<2 || count($coordinates)>self::MAX_POINTS) throw new \RuntimeException('ROUTE_GEOMETRY_INVALID');
        $clean=[];
        foreach ($coordinates as $coordinate) {
            if (!is_array($coordinate) || count($coordinate)<2 || !is_numeric($coordinate[0]) || !is_numeric($coordinate[1])) throw new \RuntimeException('ROUTE_GEOMETRY_INVALID');
            $lng=(float)$coordinate[0];$lat=(float)$coordinate[1];
            if (!is_finite($lat) || !is_finite($lng) || abs($lat)>90 || abs($lng)>180) throw new \RuntimeException('ROUTE_GEOMETRY_INVALID');
            $clean[]=[round($lng,6),round($lat,6)];
        }
        return ['type'=>'LineString','coordinates'=>$clean];
    }

    /** @return array{float,float} */
    private static function point(mixed $lat,mixed $lng): array
    {
        if (!is_string($lat) || !is_string($lng) || strlen($lat)>20 || strlen($lng)>20 || !is_numeric($lat) || !is_numeric($lng)) throw new \InvalidArgumentException('ROUTE_COORDINATE_INVALID');
        $latValue=(float)$lat;$lngValue=(float)$lng;
        if (!is_finite($latValue) || !is_finite($lngValue) || abs($latValue)>90 || abs($lngValue)>180) throw new \InvalidArgumentException('ROUTE_COORDINATE_INVALID');
        return [$latValue,$lngValue];
    }

    private static function providerFetch(string $url): string
    {
        $context=stream_context_create(['http'=>[
            'method'=>'GET','timeout'=>12,'follow_location'=>0,'max_redirects'=>0,
            'user_agent'=>'UPVanBooking/1.0','header'=>"Accept: application/json\r\n",
        ],'https'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $body=@file_get_contents($url,false,$context,0,self::MAX_RESPONSE_BYTES+1);
        if (!is_string($body)) throw new \RuntimeException('ROUTE_PROVIDER_UNAVAILABLE');
        return $body;
    }
}
