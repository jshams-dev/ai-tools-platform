<?php

declare(strict_types=1);

use App\Tools\Definitions\TextTransformerTool;

return [

    /*
    |--------------------------------------------------------------------------
    | Registered tools
    |--------------------------------------------------------------------------
    |
    | Every tool the platform serves, in display order. The first one is the
    | homepage tool. Adding a tool is one definition class, one handler class,
    | and one line here — see §1 of the architecture specification.
    |
    */

    'definitions' => [
        TextTransformerTool::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-caller throttle
    |--------------------------------------------------------------------------
    |
    | The first of the three spend guards (§11.3). Applied per guest IP hash or
    | per user id, to every tool run from every entry point. It stops the
    | trivial case; the daily spend cap in config/ai.php stops the rest.
    |
    */

    'throttle' => [
        'max_runs' => (int) env('TOOLS_THROTTLE_MAX_RUNS', 20),
        'window_seconds' => (int) env('TOOLS_THROTTLE_WINDOW_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Free runs for guests
    |--------------------------------------------------------------------------
    |
    | A visitor can try any tool without an account, this many times a day
    | (§18.1). After that the chat asks them to sign in.
    |
    */

    'guest_runs_per_day' => (int) env('TOOLS_GUEST_RUNS_PER_DAY', 3),

];
