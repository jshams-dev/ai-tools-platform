<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\ToolServiceProvider;

return [
    AppServiceProvider::class,
    AiServiceProvider::class,
    FortifyServiceProvider::class,
    ToolServiceProvider::class,
];
