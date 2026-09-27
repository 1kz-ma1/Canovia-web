<?php

namespace App\Http\Controllers;

use App\Services\CalendarPageDataService;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function index(Request $request, CalendarPageDataService $page)
    {
        return view('calendar.index', $page->build($request));
    }
}
