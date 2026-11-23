<?php
declare(strict_types=1);
namespace App\Core;

final class AuthenticationRequiredException extends \RuntimeException
{
    public function __construct() { parent::__construct('ต้องเข้าสู่ระบบก่อน'); }
}
