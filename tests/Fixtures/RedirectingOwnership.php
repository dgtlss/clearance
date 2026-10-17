<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectingOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect('/login');
        }

        if ($user->role !== 'admin' && $request->route('order')->user_id !== $user->id) {
            return redirect('/');
        }

        return $next($request);
    }
}
