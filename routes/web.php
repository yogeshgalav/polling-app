<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PollController;
use App\Http\Controllers\Admin\PollApiController;
use Illuminate\Support\Facades\Route;

Route::get("/", [PollController::class, "index"])->name("polls.index");
Route::redirect("/polls", "/");
Route::get("/polls/feed", [PollController::class, "feed"])->name(
    "polls.feed",
);
Route::get("/polls/{poll:slug}", [PollController::class, "show"])->name(
    "polls.show",
);
Route::post("/polls/{poll}/vote", [PollController::class, "vote"])->name(
    "polls.vote",
)->middleware("throttle:poll-vote");

Route::middleware(["auth:sanctum", "admin"])
    ->prefix("admin")
    ->name("admin.")
    ->group(function () {
        Route::prefix("api")->name("api.")->group(function () {
            Route::post("polls", [PollApiController::class, "store"])->name(
                "polls.store",
            );
            Route::put("polls/{poll:slug}", [
                PollApiController::class,
                "update",
            ])->name("polls.update");
            Route::delete("polls/{poll:slug}", [
                PollApiController::class,
                "destroy",
            ])->name("polls.destroy");
        });

        Route::get("polls/{poll}/results", [
            App\Http\Controllers\Admin\PollController::class,
            "results",
        ])->name("polls.results");

        Route::resource(
            "polls",
            App\Http\Controllers\Admin\PollController::class,
        )->only(["index", "create", "store", "edit", "update", "destroy"]);
    });

Route::middleware("auth:sanctum")->group(function () {
    Route::get("/profile", [ProfileController::class, "edit"])->name(
        "profile.edit",
    );
    Route::patch("/profile", [ProfileController::class, "update"])->name(
        "profile.update",
    );
    Route::delete("/profile", [ProfileController::class, "destroy"])->name(
        "profile.destroy",
    );
});

require __DIR__ . "/auth.php";
