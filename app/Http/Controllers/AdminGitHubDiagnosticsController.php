<?php

namespace App\Http\Controllers;

use App\Services\AdminAccessService;
use App\Services\GitHubIntegrationDiagnosticsService;
use Illuminate\Http\Request;

final class AdminGitHubDiagnosticsController extends Controller
{
    public function __construct(
        private readonly AdminAccessService $adminAccess,
    ) {}

    public function index(
        Request $request,
        GitHubIntegrationDiagnosticsService $diagnostics,
    ) {
        if (! $this->adminAccess->authorized($request)) {
            return redirect()->route('admin.login');
        }

        $snapshot = $diagnostics->snapshot();

        return view('admin.github.index', compact('snapshot'));
    }
}
