<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/app.php';
use App\Modules\Booking\TravelEstimate;
use App\Modules\Booking\TravelSnapshot;
use App\Modules\Booking\BangchakPriceFeed;

function checkTravel(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); echo "PASS: $message\n"; }
$result=TravelEstimate::calculate(120000,125000,7200000,10.0,40.0);
checkTravel($result['one_way_km']==120.0 && $result['round_trip_km']==245.0 && $result['duration_minutes']==120.0 && $result['fuel_litres']==24.5 && $result['fuel_cost_baht']==980.0,'distinct outbound and return distances calculate fuel and cost');
$missing=TravelEstimate::calculate(120000,125000,7200000,null,null);
checkTravel($missing['fuel_litres']===null && $missing['fuel_cost_baht']===null,'missing vehicle fuel fields never produce guessed numbers');
try {TravelEstimate::calculate(100,100,100,0.0,40.0);throw new RuntimeException('bad efficiency accepted');} catch (InvalidArgumentException) {echo "PASS: zero efficiency rejected\n";}
$fixture=json_encode([['OilDateNow'=>'13/11/2569','OilPriceDate'=>'10/11/2569','OilPriceTime'=>'20:35','OilRemark2'=>'ราคามีผล ณ วันที่ 11 พ.ย. 69 เวลา 05.00 น.','OilList'=>json_encode([['OilName'=>'ไฮดีเซล S','PriceToday'=>40.79],['OilName'=>'ชนิดที่ไม่มีราคา','PriceToday'=>0]],JSON_UNESCAPED_UNICODE)]],JSON_UNESCAPED_UNICODE);
$price=TravelEstimate::bangchakPrices($fixture,strtotime('2026-11-13T12:00:00+07:00'));
checkTravel(($price['prices']['ไฮดีเซล S']??null)===40.79 && !isset($price['prices']['ชนิดที่ไม่มีราคา']) && str_contains($price['effective_note'],'11 พ.ย.'),'nested Bangchak OilList and effective-date remark parsed without guessing');
$temp=dirname(__DIR__).'/storage/cache/up-van-price-test-'.bin2hex(random_bytes(4)).'.json';
$calls=0;$fetch=static function()use(&$calls,$fixture){$calls++;return $fixture;};
try {BangchakPriceFeed::read($temp,$fetch,strtotime('2026-11-13T12:00:00+07:00'));checkTravel(is_file($temp),'fuel feed cache file is written');BangchakPriceFeed::read($temp,$fetch,strtotime('2026-11-13T12:00:00+07:00'));checkTravel($calls===1,'fuel feed cache avoids second API fetch');}
finally {@unlink($temp);}
$gasoholReference=App\Modules\Booking\FuelEconomyReferenceCatalog::forFuelType('แก๊สโซฮอล์ 95 S EVO');
$query=['origin'=>'สถาบันนวัตกรรมการเรียนรู้ มหาวิทยาลัยพะเยา','destination'=>'เชียงใหม่','destination_source'=>'search','origin_lat'=>'19.031572','origin_lng'=>'99.893832','destination_lat'=>'18.7883','destination_lng'=>'98.9853','route_outbound_m'=>'120000','route_return_m'=>'125000','route_duration_ms'=>'7200000','route_return_duration_ms'=>'7500000','route_source'=>'osrm','fuel_type'=>'แก๊สโซฮอล์ 95 S EVO','fuel_efficiency_reference_km_per_l'=>(string)$gasoholReference['mean'],'fuel_price_baht_per_l'=>'40','fuel_price_date'=>'11 พ.ย. 69 เวลา 05.00 น.','fuel_price_source'=>'bangchak_oil_price_api'];
$snapshot=TravelSnapshot::preview($query,'แก๊สโซฮอล์ 95 S EVO');
checkTravel($snapshot!==null && $snapshot['verified']===false && $snapshot['fuel_cost_baht']!==null && $snapshot['outbound_duration_minutes']==120 && $snapshot['return_duration_minutes']==125 && $snapshot['fuel_price_source']===TravelEstimate::PRICE_SOURCE,'GET preview validates and displays route, fuel reference and cost without treating them as verified');
$mapPinQuery=$query;$mapPinQuery['destination']='';$mapPinQuery['destination_source']='map';
$mapPinSnapshot=TravelSnapshot::preview($mapPinQuery,'แก๊สโซฮอล์ 95 S EVO');
checkTravel($mapPinSnapshot!==null && $mapPinSnapshot['destination']==='' && $mapPinSnapshot['destination_source']==='map' && $mapPinSnapshot['destination_lat']===18.7883 && $mapPinSnapshot['destination_lng']===98.9853,'map-only destination keeps coordinates and route data without a destination name');
$staleMapQuery=$mapPinQuery;$staleMapQuery['destination']='เชียงใหม่';
checkTravel(TravelSnapshot::preview($staleMapQuery,'แก๊สโซฮอล์ 95 S EVO')===null,'map-only destination rejects a stale prior search name');
$placeholderQuery=$query;$placeholderQuery['destination']='จุดที่เลือกบนแผนที่';$placeholderQuery['destination_source']='search';
checkTravel(TravelSnapshot::preview($placeholderQuery,'แก๊สโซฮอล์ 95 S EVO')===null,'map placeholder cannot be accepted as a searched place name');
$badEfficiency=$query;$badEfficiency['fuel_efficiency_reference_km_per_l']='10';
checkTravel(TravelSnapshot::preview($badEfficiency,'แก๊สโซฮอล์ 95 S EVO')===null,'tampered fuel efficiency reference is rejected against the source catalog');
$query['route_source']='google_maps_routes_js';
checkTravel(TravelSnapshot::preview($query,'แก๊สโซฮอล์ 95 S EVO')===null,'non-OSRM route source is rejected');
$query['route_outbound_m']='999999999999999';
checkTravel(TravelSnapshot::preview($query,'แก๊สโซฮอล์ 95 S EVO')===null,'oversized route values rejected');
$query['route_outbound_m']='120000';$query['route_source']='osrm';
checkTravel(TravelSnapshot::preview($query,'แก๊สโซฮอล์ 95 S EVO')['route_source']==='osrm','route snapshot is tagged for OSRM only');
$travelView=file_get_contents(dirname(__DIR__).'/app/Views/booking/travel-estimate.php');
$travelJs=file_get_contents(dirname(__DIR__).'/public/assets/travel-estimate.js');
$travelCss=file_get_contents(dirname(__DIR__).'/resources/css/app.css');
$frontController=file_get_contents(dirname(__DIR__).'/public/index.php');
checkTravel(str_contains($travelView,'leaflet@1.9.4') && str_contains($travelJs,'tile.openstreetmap.org/{z}/{x}/{y}.png') && str_contains($travelJs,'OpenStreetMap contributors'),'Leaflet and attributed OSM tile layer are configured');
checkTravel(!str_contains($travelView,'data-map-key') && !str_contains($travelView,'MAP_KEY_NOT_CONFIGURED') && !str_contains($travelView,'Google Maps') && !str_contains($travelJs,'maps.googleapis.com') && !str_contains($frontController,'UP_VAN_GOOGLE_MAPS_BROWSER_KEY'),'Google Maps key/API gate is removed');
checkTravel(str_contains($travelJs,'router.project-osrm.org') && str_contains($travelJs,'if (!localRouting)') && str_contains($travelJs,'แตะหรือคลิกเพื่อเลือกจุด') && str_contains($travelJs,'photon.komoot.io/api/'),'Photon search and localhost-only OSRM are configured');
checkTravel(str_contains($travelView,'id="travel-origin-search"') && str_contains($travelView,'id="travel-destination-search"') && !str_contains($travelView,'data-map-target') && str_contains($travelView,'travel-map-instructions'),'both location searches and mouse/touch instructions are present');
checkTravel(str_contains($travelView,'name="destination_source"') && str_contains($travelJs,"destinationName = selectedFromSearch") && str_contains($travelJs,"field('destination_source').value = destinationName ? 'search' : 'map'") && str_contains($travelJs,"field('destination_source').value = ''"),'destination search name is separated from map-pin placeholder and stale names are cleared');
checkTravel(str_contains($travelJs,'setPoint(kind, {lat: coords[1], lng: coords[0]}, label, true, Boolean(label), label)') && !str_contains($travelJs,'documentPlaceName(properties)'),'A4 receives the same complete search-result label shown in the destination search input');
checkTravel(str_contains($travelCss,'.travel-map-marker-origin') && str_contains($travelCss,'.travel-map-marker-destination') && str_contains($travelJs,'button.textContent = resultLabel('),'origin/destination markers are distinct and search result labels use text');
$routes=require dirname(__DIR__).'/routes/web.php';
checkTravel(isset($routes['GET /bookings/travel-estimate/fuel-prices']) && !isset($routes['POST /bookings/travel-estimate/fuel-prices']) && !isset($routes['POST /bookings/new']),'fuel endpoint and booking flow remain GET-only');

$testNow=strtotime('2026-11-13T12:00:00+07:00');
$validRecord=json_decode($fixture,true);
checkTravel($price['effective_at']==='2026-11-11T05:00:00+07:00' && $price['source_date']==='2026-11-13' && $price['updated_today']===true,'effective date, source date and today flag remain distinct');
$cases=['malformed JSON'=>'{','bad inner JSON'=>json_encode([array_replace($validRecord[0],['OilList'=>'{'])]),'stale source'=>json_encode([array_replace($validRecord[0],['OilDateNow'=>'10/11/2569'])]),'future effective'=>str_replace('11 พ.ย. 69','14 พ.ย. 69',$fixture),'unconfirmed effective'=>json_encode([array_replace($validRecord[0],['OilRemark2'=>'unknown'])]),'wrong exact name'=>json_encode([array_replace($validRecord[0],['OilList'=>json_encode([['OilName'=>'ไฮดีเซล S ','PriceToday'=>40.79]])])]),'invalid price'=>json_encode([array_replace($validRecord[0],['OilList'=>json_encode([['OilName'=>'ไฮดีเซล S','PriceToday'=>0]])])])];
foreach($cases as $name=>$bad){
    $rejected=false;try{TravelEstimate::bangchakPrices($bad,$testNow);}catch(Throwable){$rejected=true;}
    checkTravel($rejected,'feed rejects '.$name);
}
$failurePath=dirname(__DIR__).'/storage/cache/up-van-feed-failure-'.bin2hex(random_bytes(4)).'.json';
try{
    $calls=0;$fetch=static function()use(&$calls,$fixture){$calls++;return $fixture;};
    $first=BangchakPriceFeed::read($failurePath,$fetch,$testNow);
    $cached=BangchakPriceFeed::read($failurePath,$fetch,$testNow+30);
    checkTravel($calls===1 && $cached['cached'] && $first['fetched_at']===$cached['fetched_at'],'cache preserves original fetch timestamp');
    $failed=false;try{BangchakPriceFeed::read($failurePath,static function(){throw new RuntimeException('timeout');},$testNow+3601);}catch(Throwable){$failed=true;}
    checkTravel($failed,'expired cache is never served when API fails');
    BangchakPriceFeed::read($failurePath,$fetch,$testNow+3602);
    checkTravel($calls===2,'cache refreshes after one hour');
}finally{@unlink($failurePath);}
checkTravel(!isset($price['prices']['แก๊สโซฮอล์ 95 S EVO']) && isset($price['prices']['ไฮดีเซล S']),'changing fuel cannot substitute another fuel price');

$gasohol=\App\Modules\Booking\FuelEconomyReferenceCatalog::forFuelType('แก๊สโซฮอล์ 95 S EVO');
checkTravel($gasohol!==null && $gasohol['samples']===[7.95,9.76,9.19] && abs($gasohol['mean']-(26.90/3))<0.0000001 && $gasohol['sample_count']===3,'OTP table sample mean uses all three rows, not range midpoint');
checkTravel($gasohol['min']===7.95 && $gasohol['max']===9.76 && $gasohol['usable'] && $gasohol['pdf_page']===232,'OTP sample range and source page remain traceable');
checkTravel(\App\Modules\Booking\FuelEconomyReferenceCatalog::forFuelType('แก๊สโซฮอล์ 91 S EVO')['id']===$gasohol['id'],'Gasohol 91 and 95 share the studied group');
foreach(['','ไฮดีเซล S','ดีเซล B20','แก๊สโซฮอล์ E20 S EVO','ไฮ พรีเมียม 98 พลัส'] as $unsupported)checkTravel(\App\Modules\Booking\FuelEconomyReferenceCatalog::forFuelType($unsupported)===null,'unconfirmed fuel group is not inferred: '.$unsupported);
checkTravel(!\App\Modules\Booking\FuelEconomyReferenceCatalog::forFuelType('เบนซิน')['usable'],'Gasoline inconsistency is retained and not silently treated as confirmed');
