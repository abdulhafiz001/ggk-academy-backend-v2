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

    if (isset($_ENV['VERCEL_ENV']) || isset($_ENV['VERCEL'])) {
        // Set the primary storage path to the writable /tmp directory
        $app->useStoragePath('/tmp/storage');
    
        // CRITICAL FIX: Explicitly bind the view compiled path.
        // This prevents the InvalidArgumentException ("Please provide a valid cache path.")
        // by ensuring the View Compiler knows exactly where to put compiled views in the
        // serverless environment, which is read-only outside of /tmp.
        $app->instance('config', $app->make('config')->set(
            'view.compiled', 
            '/tmp/storage/framework/views'
        ));
    }
    
    return $app;