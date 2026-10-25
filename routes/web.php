<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LobbyController;
use App\Http\Controllers\RoomController;

Route::get('/', [LobbyController::class, 'index'])->name('lobby');
Route::post('/rooms', [LobbyController::class, 'store'])->name('rooms.store');
Route::post('/rooms/{room}/join', [RoomController::class, 'join'])->name('rooms.join');
Route::get('/rooms/{room}', [RoomController::class, 'show'])->name('rooms.show');
Route::get('/rooms/{room}/state', [RoomController::class, 'state'])->name('rooms.state');
Route::post('/rooms/{room}/commands', [\App\Http\Controllers\CommandController::class, 'store'])->name('rooms.commands')->middleware('throttle:10,1');
Route::post('/player/name', [\App\Http\Controllers\PlayerController::class, 'updateName'])->name('player.name');
Route::post('/player/livery', [\App\Http\Controllers\PlayerController::class, 'updateLivery'])->name('player.livery');
Route::post('/rooms/{room}/chat', [\App\Http\Controllers\ChatController::class, 'store'])->name('rooms.chat')->middleware('throttle:30,1');

// Broadcast auth
Broadcast::channel('tunnel-check', fn ($player) => true, ['guards' => ['player']]);
Broadcast::channel('private-race.{roomId}', fn ($player, $roomId) => true, ['guards' => ['player']]);