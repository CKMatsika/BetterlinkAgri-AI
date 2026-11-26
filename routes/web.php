<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('portal-hub'))->name('home');
Route::redirect('/login', '/admin/login')->name('login');

Route::get('/widget-demo', fn () => view('widget.demo'))->name('widget.demo');
Route::get('/planner', fn () => view('planner.index'))->name('planner.index');
Route::get('/marketplace', fn () => view('marketplace.index'))->name('marketplace.index');
