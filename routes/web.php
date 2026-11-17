<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'BetterlinkAgr AI — Zimbabwe Agri-Input Sales Representative',
    'status' => 'online',
    'version' => 'v1.0.0',
]));

Route::get('/widget-demo', fn () => view('widget.demo'))->name('widget.demo');
Route::get('/planner', fn () => view('planner.index'))->name('planner.index');
