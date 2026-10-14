<?php

namespace Reactll\Connect\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Reactll\Connect\Connect;
use Symfony\Component\HttpFoundation\Response;

/** Adds the Reactll Connect script before </head> on full HTML pages, once. */
class InjectScript
{
    public function __construct(private Connect $connect) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->wanted($request, $response)) {
            return $response;
        }

        $html = (string) $response->getContent();
        $at = stripos($html, '</head>');
        if ($at === false || str_contains($html, '/c.js" data-site=')) {
            return $response;
        }
        $response->setContent(substr_replace($html, $this->connect->script()."\n", $at, 0));

        return $response;
    }

    private function wanted(Request $request, Response $response): bool
    {
        return $this->connect->connected()
            && $this->connect->module('visitors')
            && $request->isMethod('GET')
            && ! $request->expectsJson()
            && ! $request->is(...(array) config('reactll-connect.except', []))
            && (config('reactll-connect.count_logged_in') || ! $request->user())
            && $response->getStatusCode() === 200
            && ! $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            && ! $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            && str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html');
    }
}
