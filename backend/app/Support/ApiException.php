<?php
namespace App\Support;

class ApiException extends \RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $status = 400, public array $details = [])
    {
        parent::__construct($message);
    }
}