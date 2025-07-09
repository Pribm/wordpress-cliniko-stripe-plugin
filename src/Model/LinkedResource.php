<?php
namespace App\Model;
if (!defined('ABSPATH')) exit;

class LinkedResource
{
    public string $url;

    public function __construct(string $url)
    {
        $this->url = $url;
    }
}
