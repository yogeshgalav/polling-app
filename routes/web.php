<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PollController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get("/", function () {
    return Inertia::render("Welcome", [
        "canLogin" => Route::has("login"),
        "canRegister" => Route::has("register"),
        "laravelVersion" => Application::VERSION,
        "phpVersion" => PHP_VERSION,
    ]);
});

Route::get("/polls", [PollController::class, "index"])->name(
    "polls.index",
);
Route::get("/polls/feed", [PollController::class, "feed"])->name(
    "polls.feed",
);
Route::get("/polls/{poll:slug}", [PollController::class, "show"])->name(
    "polls.show",
);
Route::post("/polls/{poll}/vote", [PollController::class, "vote"])->name(
    "polls.vote",
)->middleware("throttle:poll-vote");

Route::get("/dashboard", DashboardController::class)->middleware([
    "auth",
    "verified",
])->name("dashboard");

Route::middleware(["auth", "admin"])
    ->prefix("admin")
    ->name("admin.")
    ->group(function () {
        Route::get(
            "/dashboard",
            fn() => redirect()->route("admin.polls.index"),
        )->name("dashboard");

        Route::get("polls/{poll}/results", [
            App\Http\Controllers\Admin\PollController::class,
            "results",
        ])->name("polls.results");

        Route::resource(
            "polls",
            App\Http\Controllers\Admin\PollController::class,
        )->only(["index", "create", "store", "edit", "update", "destroy"]);
    });

Route::middleware("auth")->group(function () {
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
