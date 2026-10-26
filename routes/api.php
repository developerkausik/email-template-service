<?php

use App\Http\Controllers\EmailTemplateController;
use Illuminate\Support\Facades\Route;

Route::post('/email-templates', EmailTemplateController::class)
    ->middleware('throttle:30,1')
    ->name('email-templates.generate');
