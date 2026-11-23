<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
final class TransactionProbe extends PDO
{
    public array $events=[];public bool $active=false;
    public function __construct(){}
    public function exec(string $statement):int|false{$this->events[]=$statement;return 0;}
    public function beginTransaction():bool{$this->events[]='BEGIN';$this->active=true;return true;}
    public function commit():bool{$this->events[]='COMMIT';$this->active=false;return true;}
    public function rollBack():bool{$this->events[]='ROLLBACK';$this->active=false;return true;}
    public function inTransaction():bool{return $this->active;}
}
$checks=0;
foreach([App\Repositories\PdoBookingSubmissionRepository::class,App\Repositories\PdoRequesterDraftRepository::class] as $class){
    $reflection=new ReflectionClass($class);$repo=$reflection->newInstanceWithoutConstructor();$pdo=new TransactionProbe();$reflection->getProperty('db')->setValue($repo,$pdo);
    $value=$repo->transaction(function()use($pdo){$pdo->events[]='WORK';return 7;});
    if($value!==7||$pdo->events!==['SET TRANSACTION ISOLATION LEVEL READ COMMITTED','BEGIN','WORK','COMMIT'])throw new RuntimeException('Isolation must be set before BEGIN for '.$class);$checks++;
    $pdo->events=[];
    try{$repo->transaction(static function(){throw new DomainException('AUDIT_FAILURE');});}catch(DomainException){}
    if($pdo->events!==['SET TRANSACTION ISOLATION LEVEL READ COMMITTED','BEGIN','ROLLBACK'])throw new RuntimeException('Failure must rollback '.$class);$checks++;
    $pdo->active=true;$pdo->events=[];
    try{$repo->transaction(static fn()=>null);}catch(RuntimeException){}
    if($pdo->events!==[])throw new RuntimeException('Nested transaction must not change isolation');$checks++;
}
echo "PASS: $checks transaction ordering/rollback checks with PDO test double; no MySQL connection.\n";
