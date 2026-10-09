<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->file(public_path('index.html')));

Route::fallback(function (Request $request) {
    abort_if($request->is('api/*'), 404);

    return response()->file(public_path('index.html'));
});
