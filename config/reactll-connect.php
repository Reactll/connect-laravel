<?php

return [
    // The connection token from Reactll (Sites → the site → Install). Holds the secret: .env only.
    'token' => env('REACTLL_CONNECT_TOKEN'),

    // Add the Reactll Connect script to every HTML page of the web group (or use @reactllConnect).
    'inject' => env('REACTLL_CONNECT_INJECT', true),

    // Paths the script is never added to: your app's own panels and tools.
    'except' => ['admin', 'admin/*', 'panel', 'panel/*', 'dashboard', 'dashboard/*', 'horizon*', 'telescope*', 'pulse*', 'nova*', 'filament*'],

    // Signed-in users are not counted unless this is true.
    'count_logged_in' => env('REACTLL_CONNECT_COUNT_LOGGED_IN', false),

    // Seconds to wait for Reactll when a form lead is sent.
    'timeout' => 3,
];
