<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// Vercel/Serverless Specific Fix: Configure paths BEFORE creating the application
$isVercel = isset($_ENV['VERCEL_ENV']) || isset($_ENV['VERCEL']) || getenv('VERCEL') || getenv('VERCEL_ENV');

if ($isVercel) {
    // Set storage path to writable /tmp directory
    $storagePath = '/tmp/storage';
    
    // Create necessary directory structure if it doesn't exist
    $directories = [
        $storagePath,
        $storagePath . '/app',
        $storagePath . '/app/public',
        $storagePath . '/framework',
        $storagePath . '/framework/cache',
        $storagePath . '/framework/cache/data',
        $storagePath . '/framework/sessions',
        $storagePath . '/framework/testing',
        $storagePath . '/framework/views',
        $storagePath . '/logs',
    ];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
    
    // Set environment variable for storage path
    $_ENV['APP_STORAGE_PATH'] = $storagePath;
    putenv('APP_STORAGE_PATH=' . $storagePath);
}

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
        // Ensure API routes return JSON instead of redirects for unauthenticated requests
        $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
            return $request->is('api/*');
        });
    })->create(); 

// Vercel/Serverless Specific Fix: Configure paths IMMEDIATELY after app creation
// This must happen before any services try to resolve dependencies
if ($isVercel) {
    $storagePath = '/tmp/storage';
    
    // Set the storage path (this ensures Laravel uses /tmp/storage)
    $app->useStoragePath($storagePath);
    
    // Set view compiled path and other cache paths immediately
    // Use array access to avoid dependency resolution issues
    // Add safety check to ensure config is bound before accessing it
    if ($app->bound('config')) {
        $app['config']->set('view.compiled', $storagePath . '/framework/views');
        $app['config']->set('cache.stores.file.path', $storagePath . '/framework/cache/data');
        $app['config']->set('cache.stores.file.lock_path', $storagePath . '/framework/cache/data');
        $app['config']->set('session.files', $storagePath . '/framework/sessions');
        $app['config']->set('logging.channels.single.path', $storagePath . '/logs/laravel.log');
    }
}
    
return $app;