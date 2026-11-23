<?php
declare(strict_types=1);

// Offline model for a proposed failed-only limiter. This is not wired to AuthService or MySQL.
final class FailedOnlyLimiterModel
{
    private array $counters=[];
    private array $pending=[];
    private int $nextToken=0;

    public function reserve(string $username, string $ip, int $now): ?int
    {
        $u=$this->key('u',$username); $i=$this->key('i',$ip);
        $this->resetIfExpired($u,$now); $this->resetIfExpired($i,$now);
        if (($this->counters[$u]['failures']??0)+($this->pendingCount($u)>0?$this->pendingCount($u):0)>=5
            || ($this->counters[$i]['failures']??0)+$this->pendingCount($i)>=20) return null;
        $token=++$this->nextToken;
        $this->pending[$token]=[$u,$i,$now];
        return $token;
    }

    public function finish(int $token, bool $failed, int $now): void
    {
        if (!isset($this->pending[$token])) throw new LogicException('Unknown reservation.');
        [$u,$i,$started]=$this->pending[$token]; unset($this->pending[$token]);
        foreach ([$u,$i] as $key) {
            $this->counters[$key]??=['started'=>$started,'failures'=>0];
            if ($failed) $this->counters[$key]['failures']++;
        }
    }

    public function failures(string $scope,string $value): int { return $this->counters[$this->key($scope,$value)]['failures']??0; }
    private function pendingCount(string $key): int { return count(array_filter($this->pending,static fn(array $p):bool=>$p[0]===$key||$p[1]===$key)); }
    private function key(string $scope,string $value): string { return $scope.':'.hash_hmac('sha256',$value,'offline-only-test-key'); }
    private function resetIfExpired(string $key,int $now): void
    {
        $state=$this->counters[$key]??null;
        if ($state!==null && ($now<$state['started'] || $now-$state['started']>=900)) $this->counters[$key]=['started'=>$now,'failures'=>0];
    }
}

$checks=0;
function failedLimitCheck(bool $ok,string $label):void { global $checks; if(!$ok)throw new RuntimeException('FAIL: '.$label); $checks++; echo 'PASS: '.$label.PHP_EOL; }
$m=new FailedOnlyLimiterModel();
$success=$m->reserve('sample-user','192.0.2.1',1000); $m->finish($success,false,1000);
failedLimitCheck($m->failures('u','sample-user')===0 && $m->failures('i','192.0.2.1')===0,'successful attempt releases reservation without adding failure counts');
$tokens=[]; for($n=0;$n<5;$n++) $tokens[]=$m->reserve('sample-user','198.51.100.'.$n,1100+$n);
failedLimitCheck(count(array_filter($tokens))===5 && $m->reserve('sample-user','203.0.113.1',1105)===null,'five concurrent username reservations are admitted and sixth is denied');
foreach($tokens as $token) $m->finish($token,true,1200);
failedLimitCheck($m->failures('u','sample-user')===5 && $m->reserve('sample-user','203.0.113.2',1201)===null,'only finalized failures consume the username threshold');
failedLimitCheck($m->reserve('sample-user','203.0.113.2',2000)!==null,'username failure window resets at 15 minutes');
$ipTokens=[]; for($n=0;$n<20;$n++) $ipTokens[]=$m->reserve('person-'.$n,'192.0.2.99',3000+$n);
failedLimitCheck(count(array_filter($ipTokens))===20 && $m->reserve('person-extra','192.0.2.99',3020)===null,'twenty concurrent IP reservations are admitted and twenty-first is denied');
foreach($ipTokens as $token) $m->finish($token,true,3100);
failedLimitCheck($m->failures('i','192.0.2.99')===20,'IP failure counter records finalized failed attempts');
echo "PASS: $checks failed-only rate-limit proposal checks; simulation only, no DB or AuthService used.".PHP_EOL;
