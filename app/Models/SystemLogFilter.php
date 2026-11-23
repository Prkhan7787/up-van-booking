<?php
declare(strict_types=1);
namespace App\Models;

final readonly class SystemLogFilter
{
    public const PAGE_SIZE=25;
    public string $action;
    public ?int $actor;
    public string $from;
    public string $to;
    public int $page;
    public ?string $fromUtc;
    public ?string $untilUtc;
    public function __construct(array $input,public ?int $targetUserId=null)
    {
        if ($targetUserId!==null && $targetUserId<1) throw new \InvalidArgumentException('Invalid history target.');
        foreach (['action','actor','from','to','page'] as $key) if (isset($input[$key]) && !is_string($input[$key])) throw new \InvalidArgumentException('Invalid log filter.');
        $this->action=$input['action'] ?? '';
        if ($this->action!=='' && !preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D',$this->action)) throw new \InvalidArgumentException('Invalid event filter.');
        $actor=$input['actor'] ?? '';
        if ($actor!=='' && (!ctype_digit($actor) || strlen($actor)>18 || (int)$actor<1)) throw new \InvalidArgumentException('Invalid actor filter.');
        $this->actor=$actor==='' ? null : (int)$actor;
        $page=$input['page'] ?? '1';
        if (!ctype_digit($page) || strlen($page)>5 || (int)$page<1 || (int)$page>10000) throw new \InvalidArgumentException('Invalid page.');
        $this->page=(int)$page;
        $this->from=$input['from'] ?? ''; $this->to=$input['to'] ?? '';
        $start=self::date($this->from); $end=self::date($this->to);
        if ($start!==null && $end!==null && $start>$end) throw new \InvalidArgumentException('Invalid date range.');
        $utc=new \DateTimeZone('UTC');
        $this->fromUtc=$start?->setTimezone($utc)->format('Y-m-d H:i:s');
        $this->untilUtc=$end?->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    }
    private static function date(string $value): ?\DateTimeImmutable
    {
        if ($value==='') return null;
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$value)) throw new \InvalidArgumentException('Invalid date.');
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Asia/Bangkok'));
        if ($d===false || $d->format('Y-m-d')!==$value) throw new \InvalidArgumentException('Invalid date.');
        return $d;
    }
    public function query(int $page): string { return http_build_query(['action'=>$this->action,'actor'=>$this->actor ?? '','from'=>$this->from,'to'=>$this->to,'page'=>$page]); }
}
