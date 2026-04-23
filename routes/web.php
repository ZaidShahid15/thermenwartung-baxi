<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('/datenschutz', function () {
    return view('datenschutz');
})->name('datenschutz');

Route::get('/impressum', function () {
    return view('impressum');
})->name('impressum');
