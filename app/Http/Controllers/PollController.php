<?php

namespace App\Http\Controllers;

use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Vote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

class PollController extends Controller
{

    public function index(Request $request)
    {
        return Inertia::render("Polls/Index", [
            "polls" => [],
        ]);
    }

    public function show(Request $request, Poll $poll)
    {
        return Inertia::render("Polls/Show", [
            "poll" => null,
        ]);
    }

    public function feed(Request $request)
    {
        return response()->json([
            "data" => [],
        ]);
    }

    public function vote(Request $request, Poll $poll)
    {

        return response()->json([
            "poll" => null,
        ]);
    }
}
