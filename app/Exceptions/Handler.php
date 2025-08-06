<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (AccessDeniedException|AccessDeniedHttpException $e) {
            if (request()->is('api/*')) {
                return response()->json([
                    'message' => 'Forbidden',
                    'error' =>  'You don\'t have permission to access this page.',
                ], 403);
            }
        });

        $this->renderable(function (ModelNotFoundException $e) {
            if (request()->is('api/*')) {
                return response()->json([
                    'message' => 'Not Found',
                    'error' =>  'The data was not found.',
                ], 404);
            }
        });
        $this->renderable(function (NotFoundHttpException $e) {
            if (request()->is('api/*')) {
                return response()->json([
                    'message' => 'Not Found',
                    'error' =>  'The requested URL was not found.',
                ], 404);
            }
        });

        $this->renderable(function (Throwable|Exception $e) {

            if (request()->is('api/*') && config('app.env') == 'production') {
                return response()->json([
                    'message' => 'Internal Server Error',
                    'errors' => 'The server encountered an internal error and was unable to complete your request. Either the server is overloaded or there is an error in the application.'
                ], 500);
            }
        });
    }
}
