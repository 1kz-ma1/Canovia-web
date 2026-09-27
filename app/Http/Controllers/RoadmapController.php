<?php

namespace App\Http\Controllers;

use App\Services\RoadmapPageDataService;
use Illuminate\Http\Request;

class RoadmapController extends Controller
{
    public function index(Request $request, RoadmapPageDataService $page)
    {
        return view('roadmap.index', $page->build($request));
    }
}
