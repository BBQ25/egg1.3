<?php

namespace App\Http\Middleware;

use App\Domain\DocEase\DocEaseGateway;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDocEaseAccess
{
    public function __construct(private readonly DocEaseGateway $docEaseGateway)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->docEaseGateway->userCanAccess($request->user())) {
            abort(403);
        }

        return $next($request);
    }
}
