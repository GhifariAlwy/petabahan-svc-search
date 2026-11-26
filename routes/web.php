<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'petabahan-svc-search', 'version' => '2.0.0']));
