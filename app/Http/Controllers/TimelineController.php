<?php

namespace App\Http\Controllers;

use App\Services\TimelinePageDataService;
use Illuminate\Http\Request;

class TimelineController extends Controller
{
    public function index(Request $request, TimelinePageDataService $page)
    {
        return view('timeline.index', $page->build($request));
    }
}
