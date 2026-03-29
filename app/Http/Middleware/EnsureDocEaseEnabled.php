<?php

namespace App\Http\Middleware;

use App\Domain\DocEase\DocEaseGateway;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDocEaseEnabled
{
    public function __construct(private readonly DocEaseGateway $docEaseGateway)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->docEaseGateway->enabled()) {
            abort(404);
        }

        return $next($request);
    }
}
