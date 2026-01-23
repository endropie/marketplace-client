<?php

namespace Virmata\MarketplaceClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Force Laravel to treat the request as a JSON request internally
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        // Ensure the response Content-Type header is application/json
        if ($response->headers->get('Content-Type') !== 'application/json') {
             $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }
}
