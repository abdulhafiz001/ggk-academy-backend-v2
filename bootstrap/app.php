<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// 1. Assign the application instance to the $app variable
$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Apply security headers to all API responses
        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeadersMiddleware::class,
        ]);
        
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'teacher' => \App\Http\Middleware\TeacherMiddleware::class,
            'student' => \App\Http\Middleware\StudentMiddleware::class,
            'form.teacher' => \App\Http\Middleware\FormTeacherMiddleware::class,
            'throttle.login' => \App\Http\Middleware\CustomThrottleMiddleware::class,
        ]);

        $middleware->trustProxies('*',
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create(); 

// 2. Use the assigned $app variable to set the storage path
if (isset($_ENV['VERCEL_ENV']) || isset($_ENV['VERCEL'])) {
    // This tells Laravel to use /tmp/storage/ for all writes (views, cache, sessions, logs)
    $app->useStoragePath('/tmp/storage');
}

// 3. Return the application instance
return $app;