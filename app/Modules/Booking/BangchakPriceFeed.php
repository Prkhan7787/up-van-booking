<?php
declare(strict_types=1);
namespace App\Modules\Booking;

/** Fixed HTTPS origin, verified TLS, no redirects, one-hour cache, no stale fallback. */
final class BangchakPriceFeed
{
    public static function read(string $cachePath,?callable $fetch=null,int $now=0): array
    {
        $now=$now ?: time();
        if(is_file($cachePath) && filesize($cachePath)>0 && filesize($cachePath)<300000){
            try {
                $cached=json_decode((string)file_get_contents($cachePath),true,16,JSON_THROW_ON_ERROR);
                $age=$now-(int)($cached['fetched_at']??0);
                if($age>=0 && $age<3600 && is_string($cached['body']??null))
                    return TravelEstimate::bangchakPrices($cached['body'],$now)+['fetched_at'=>gmdate(DATE_ATOM,(int)$cached['fetched_at']),'cached'=>true];
            }catch(\Throwable){}
        }
        $fetch ??=static function(): string {
            $context=stream_context_create(['http'=>['timeout'=>8,'follow_location'=>0,'max_redirects'=>0,'user_agent'=>'UPVanBooking/1.0','header'=>"Accept: application/json\r\n"],
                'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
            $body=@file_get_contents(TravelEstimate::PRICE_SOURCE,false,$context,0,262145);
            if(!is_string($body) || strlen($body)>262144 || !preg_match('~^HTTP/\S+ 200(?: |$)~',$http_response_header[0]??''))throw new \RuntimeException('FUEL_FEED_UNAVAILABLE');
            return $body;
        };
        $body=$fetch();
        if(strlen($body)>262144)throw new \UnexpectedValueException('FUEL_RESPONSE_TOO_LARGE');
        $parsed=TravelEstimate::bangchakPrices($body,$now);
        $directory=dirname($cachePath);
        if(is_dir($directory)){
            $temporary=$cachePath.'.'.bin2hex(random_bytes(6)).'.tmp';
            if(@file_put_contents($temporary,json_encode(['fetched_at'=>$now,'body'=>$body],JSON_THROW_ON_ERROR),LOCK_EX)!==false){
                if(!@rename($temporary,$cachePath))@unlink($temporary);
            }else{@unlink($temporary);}
        }
        return $parsed+['fetched_at'=>gmdate(DATE_ATOM,$now),'cached'=>false];
    }
}
