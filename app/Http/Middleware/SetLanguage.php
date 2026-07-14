<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\App;

class SetLanguage
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Accept-Language', 'en');
        $lang = strtolower(trim(explode(',', $header)[0]));

        if (str_contains($lang, '-')) {
            $lang = explode('-', $lang)[0];
        }

        if (!in_array($lang, ['en', 'ar', 'de'], true)) {
            $lang = 'en';
        }

        App::setLocale($lang);

        return $next($request);
    }
}
