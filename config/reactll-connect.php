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

    // The site owner's switches. A module switched off sends nothing (and Reactll drops it if anything arrives):
    // visitors off → no script; events off → the script counts page views only; forms off → submission() sends
    // nothing; sales off → order(), orders() and deleteOrders() send nothing. Reported to Reactll every hour.
    'modules' => [
        'visitors' => env('REACTLL_CONNECT_VISITORS', true),
        'events' => env('REACTLL_CONNECT_EVENTS', true),
        'forms' => env('REACTLL_CONNECT_FORMS', true),
        'sales' => env('REACTLL_CONNECT_SALES', true),
    ],

    // This app sends its form leads with ReactllConnect::submission() (Reactll then offers the Forms module).
    'sends_leads' => env('REACTLL_CONNECT_SENDS_LEADS', true),

    // Orders are sent once the response has gone out, so nothing slows a checkout or a webhook. Set a queue
    // connection name (or true for the default connection) to send them from a queued job instead — only when
    // a queue worker runs. Orders Reactll could not take wait in the cache and go again within the hour.
    'queue' => env('REACTLL_CONNECT_QUEUE', false),
];
