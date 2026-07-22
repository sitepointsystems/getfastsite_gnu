<?php

declare(strict_types=1);

use Fastsite\Kernel\Application;

require \dirname(__DIR__) . '/vendor/autoload.php';

Application::boot()->handleHttp();
