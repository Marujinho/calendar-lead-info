<?php

use App\Providers\AppServiceProvider;
use App\Providers\CalendarServiceProvider;
use App\Providers\DigestServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    CalendarServiceProvider::class,
    DigestServiceProvider::class,
];
