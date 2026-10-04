<?php

use App\Http\Controllers\DeployController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/deploy', [DeployController::class, 'webhook'])
    ->name('webhooks.deploy')
    ->middleware('throttle:10,1');
