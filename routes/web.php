<?php

use Illuminate\Support\Facades\Route;
use App\Enums\FeatureKey;
use App\Http\Controllers\WorkLogController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\CanoviaMapController;
use App\Http\Controllers\CapabilityActivationController;
use App\Http\Controllers\MapPersonalizationController;
use App\Http\Controllers\FirstRunController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlanActionDraftController;
use App\Http\Controllers\PlanActionDraftSpotlightController;
use App\Http\Controllers\PlanSpecializationController;
use App\Http\Controllers\PlanDashboardController;
use App\Http\Controllers\GoalDiscoveryController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\AdminTemplateController;
use App\Http\Controllers\PublicPlanController;
use App\Http\Controllers\MyPlanController;
use App\Http\Controllers\AiTaskAssistantController;
use App\Http\Controllers\PlanReviewAssistantController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\BehaviorEventController;
use App\Http\Controllers\NavigationController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\WorkSessionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OfflineWorkSessionController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\CanoviaFutureController;
use App\Http\Controllers\AdminFeedbackController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminTelemetryController;
use App\Http\Controllers\AdminEarlyAccessController;
use App\Http\Controllers\AdminGitHubDiagnosticsController;
use App\Http\Controllers\AdminQuestionPackController;
use App\Http\Controllers\AdminPracticeDemandController;
use App\Http\Controllers\AdminGoalPatternDemandController;
use App\Http\Controllers\AdminEconomyController;
use App\Http\Controllers\AdminPreviewController;
use App\Http\Controllers\AdminReleaseLevelController;
use App\Http\Controllers\AdminReleaseGateController;
use App\Http\Controllers\AdminStudyScenarioLabController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\RoadmapController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\PlanCollaborationController;
use App\Http\Controllers\PlanResourceController;
use App\Http\Controllers\PlanResourceAssistantController;
use App\Http\Controllers\PlanArtifactController;
use App\Http\Controllers\GitHubWorkflowController;
use App\Http\Controllers\DevelopmentReadinessController;
use App\Http\Controllers\DevelopmentProviderTriageController;
use App\Http\Controllers\DevelopmentCodingAgentHandoffController;
use App\Http\Controllers\DevelopmentPreviewController;
use App\Http\Controllers\StudyPracticeController;
use App\Http\Controllers\AdaptiveLearningController;
use App\Http\Controllers\AdaptiveLearningExamController;
use App\Http\Controllers\AdaptiveLearningEvaluationAdjustmentController;
use App\Http\Controllers\StudyActivityController;
use App\Http\Controllers\StudyLanguageActivityController;
use App\Http\Controllers\StudyResourceActivityController;
use App\Http\Controllers\StudyScopeCaptureController;
use App\Http\Controllers\StudyScoreController;
use App\Http\Controllers\BookkeepingPlacementController;
use App\Http\Controllers\BookkeepingJournalPracticeController;
use App\Http\Controllers\StudyLearningTypeController;
use App\Http\Controllers\StudyAdaptiveActionController;
use App\Http\Controllers\StudyRecallController;
use App\Http\Controllers\StudyRecallCandidateController;
use App\Http\Controllers\FutureMemoController;
use App\Http\Controllers\CareerWorkspaceController;
use App\Http\Controllers\CareerModeWorkspaceController;
use App\Http\Controllers\CareerExplorationController;
use App\Http\Controllers\InterviewReviewController;
use App\Http\Controllers\GuidedExecutionController;
use App\Http\Controllers\ExecutionOrchestrationController;
use App\Http\Controllers\ExecutionGitHubHandoffController;
use App\Http\Controllers\ExecutionDistributionController;
use App\Http\Controllers\CompanionController;
use App\Http\Controllers\CoreFragmentBundleController;
use App\Http\Controllers\ClientPerformanceController;
use App\Http\Controllers\WorkspaceModeController;
use App\Http\Controllers\StudyWorkspaceController;
use App\Http\Controllers\StudyWorkspaceTopController;
use App\Http\Controllers\DevelopmentWorkspaceController;
use App\Http\Controllers\DevelopmentRoadmapContextController;
use App\Http\Controllers\DevelopmentPrivateAiContextPreviewController;
use App\Http\Controllers\McpProtectedResourceMetadataController;
use App\Http\Controllers\McpDelegatedRevocationController;
use App\Http\Controllers\McpOAuthAccountLinkController;
use App\Http\Controllers\DevelopmentAiSharingPreferenceController;
use App\Http\Controllers\DevelopmentCreativePlanSelectionController;
use App\Http\Controllers\DevelopmentWorkspaceTopController;
use App\Http\Controllers\DevelopmentActivityObservationController;
use App\Http\Controllers\OverviewWorkspaceController;
use App\Http\Controllers\ExecutionSetupController;
use App\Http\Controllers\ExecutionValidationProviderController;
use App\Http\Controllers\ProviderConnectionController;
use App\Http\Controllers\ProviderExecutionContextController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\ProductPreviewController;
use App\Http\Controllers\PersonalizationController;
use App\Http\Controllers\PersonalizationContextUpdateController;

Route::get('/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/support', [LegalController::class, 'support'])->name('legal.support');
Route::get('/product-preview', ProductPreviewController::class)
    ->middleware('release.level:2')
    ->name('product.preview.index');

Route::get('/welcome', [FirstRunController::class, 'show'])->name('first_run.show');
Route::post('/welcome/start', [FirstRunController::class, 'start'])->name('first_run.start');
Route::middleware('release.level:1')->group(function () {
    Route::get('/personalization', [PersonalizationController::class, 'show'])->name('personalization.show');
    Route::middleware('auth')->group(function () {
        Route::get('/personalization/updates', [PersonalizationContextUpdateController::class, 'index'])->name('personalization.updates.index');
        Route::post('/personalization/updates/refresh', [PersonalizationContextUpdateController::class, 'refresh'])->middleware('throttle:12,1')->name('personalization.updates.refresh');
        Route::post('/personalization/updates/{candidateKey}/confirm', [PersonalizationContextUpdateController::class, 'confirm'])->middleware('throttle:12,1')->name('personalization.updates.confirm');
        Route::post('/personalization/updates/{candidateKey}/dismiss', [PersonalizationContextUpdateController::class, 'dismiss'])->middleware('throttle:12,1')->name('personalization.updates.dismiss');
    });
    Route::post('/personalization', [PersonalizationController::class, 'store'])->middleware('throttle:20,1')->name('personalization.store');
    Route::post('/personalization/skip', [PersonalizationController::class, 'skip'])->middleware('throttle:20,1')->name('personalization.skip');
    Route::get('/personalization/result', [PersonalizationController::class, 'result'])->name('personalization.result');
    Route::post('/personalization/seeds/{seedKey}', [PersonalizationController::class, 'acceptSeed'])->middleware('throttle:20,1')->name('personalization.seed.accept');
    Route::post('/personalization/capabilities/{capability}/interest', [PersonalizationController::class, 'capabilityInterest'])->middleware('throttle:20,1')->name('personalization.capability.interest');
});
// OAuth Protected Resource Metadata (RFC 9728). Non-session clients may
// discover these routes, but only after explicitly enabling safe IdP config.
Route::get('/.well-known/oauth-protected-resource', McpProtectedResourceMetadataController::class)
    ->middleware('throttle:60,1')
    ->name('mcp.oauth_protected_resource');
Route::get('/.well-known/oauth-protected-resource/api/mcp', McpProtectedResourceMetadataController::class)
    ->middleware('throttle:60,1')
    ->name('mcp.oauth_protected_resource_path');
Route::get('/', [HomeController::class, 'index'])->middleware('early_access.visit')->name('home');
Route::get('/workspace/overview', OverviewWorkspaceController::class)->middleware('early_access.visit')->name('workspace.overview.index');
Route::get('/workspace/study/top', StudyWorkspaceTopController::class)->middleware('early_access.visit')->name('workspace.study.top');
Route::get('/workspace/study', StudyWorkspaceController::class)->middleware('early_access.visit')->name('workspace.study.index');
Route::get('/workspace/development/top', DevelopmentWorkspaceTopController::class)->middleware('early_access.visit')->name('workspace.development.top');
Route::get('/workspace/development', DevelopmentWorkspaceController::class)->middleware('early_access.visit')->name('workspace.development.index');
Route::get('/workspace/development/context/{plan}', DevelopmentRoadmapContextController::class)
    ->middleware(['auth', 'throttle:12,1'])
    ->name('workspace.development.context');
Route::get('/workspace/development/private-context/{plan}/preview', DevelopmentPrivateAiContextPreviewController::class)
    ->middleware(['auth', 'throttle:10,1'])
    ->name('workspace.development.private_context.preview');
Route::post('/workspace/development/private-context/{plan}/sharing-preference', [DevelopmentAiSharingPreferenceController::class, 'store'])
    ->middleware(['auth', 'throttle:6,1'])
    ->name('workspace.development.sharing_preference.store');
Route::delete('/workspace/development/private-context/{plan}/sharing-preference', [DevelopmentAiSharingPreferenceController::class, 'destroy'])
    ->middleware(['auth', 'throttle:6,1'])
    ->name('workspace.development.sharing_preference.destroy');
Route::post('/workspace/development/creative/{plan}', [DevelopmentCreativePlanSelectionController::class, 'store'])
    ->middleware('throttle:12,1')
    ->name('workspace.development.creative.store');
Route::delete('/workspace/development/creative/{plan}', [DevelopmentCreativePlanSelectionController::class, 'destroy'])
    ->middleware('throttle:12,1')
    ->name('workspace.development.creative.destroy');
Route::post('/plans/{plan}/development-preview', [DevelopmentPreviewController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('plans.development_preview.store');
Route::delete('/plans/{plan}/development-preview', [DevelopmentPreviewController::class, 'destroy'])
    ->middleware('throttle:20,1')
    ->name('plans.development_preview.destroy');
Route::get('/workspace/career', CareerModeWorkspaceController::class)->middleware(['release.level:3', 'early_access.visit'])->name('workspace.career.index');
Route::middleware('release.level:3')->group(function () {
    Route::get('/workspace/career/explore', [CareerExplorationController::class, 'show'])->name('career.explore.show');
    Route::post('/workspace/career/explore', [CareerExplorationController::class, 'store'])->middleware('throttle:12,1')->name('career.explore.store');
    Route::post('/workspace/career/explore/plan', [CareerExplorationController::class, 'createPlan'])->name('career.explore.plan');
});
Route::get('/workspace', [WorkspaceModeController::class, 'resume'])->name('workspace_modes.resume');
Route::get('/workspace/mode/{workspaceMode}', [WorkspaceModeController::class, 'enter'])->name('workspace_modes.enter');
Route::post('/workspace/{workspaceMode}/select', [WorkspaceModeController::class, 'select'])->name('workspace_modes.select');
Route::delete('/workspace/preference', [WorkspaceModeController::class, 'reset'])->name('workspace_modes.preference.reset');
Route::post('/plans/{plan}/tasks/{task}/execution-setup', [ExecutionSetupController::class, 'store'])
    ->name('plans.tasks.execution_setup.store');
Route::post('/plans/{plan}/tasks/{task}/development-coding-agent-handoff', DevelopmentCodingAgentHandoffController::class)
    ->middleware('throttle:12,1')
    ->name('plans.tasks.development_coding_agent_handoff.prepare');
Route::post('/execution/providers/{providerKey}/connections', [ProviderConnectionController::class, 'store'])
    ->middleware(['auth', 'throttle:12,1'])
    ->name('execution.provider_connections.store');
Route::delete('/execution/provider-connections/{connection}', [ProviderConnectionController::class, 'destroy'])
    ->middleware('auth')
    ->name('execution.provider_connections.destroy');
Route::post('/plans/{plan}/tasks/{task}/provider-connections/{connection}/execution-context', [ProviderExecutionContextController::class, 'store'])
    ->middleware(['auth', 'throttle:60,1'])
    ->name('execution.provider_contexts.store');
Route::get('/execution-validation/study/{plan}/tasks/{task}', [ExecutionValidationProviderController::class, 'show'])
    ->name('execution.validation.study_practice');
Route::post('/execution-validation/study/{plan}/tasks/{task}/result', [ExecutionValidationProviderController::class, 'storeStudyPracticeResult'])
    ->name('execution.validation.study_practice.result');
Route::get('/map', [CanoviaMapController::class, 'index'])->name('map.index');
Route::post('/map/personalization/pins/{plan}', [MapPersonalizationController::class, 'store'])
    ->middleware('auth')
    ->name('map.personalization.pins.store');
Route::delete('/map/personalization/pins/{plan}', [MapPersonalizationController::class, 'destroy'])
    ->middleware('auth')
    ->name('map.personalization.pins.destroy');
Route::get('/instant/core-bundle', CoreFragmentBundleController::class)->name('instant.core_bundle');
Route::post('/performance/client', ClientPerformanceController::class)
    ->middleware('throttle:120,1')
    ->name('performance.client');

// 未来メモ / goal discovery
Route::get('/future-memos', [FutureMemoController::class, 'index'])->name('future_memos.index');
Route::post('/future-memos', [FutureMemoController::class, 'store'])->name('future_memos.store');
Route::put('/future-memos/{futureMemo}', [FutureMemoController::class, 'update'])->name('future_memos.update');
Route::delete('/future-memos/{futureMemo}', [FutureMemoController::class, 'destroy'])->name('future_memos.destroy');
Route::get('/future-memos/organize/ai', [FutureMemoController::class, 'organize'])->name('future_memos.organize');
Route::post('/future-memos/organize/ai/prompt', [FutureMemoController::class, 'generatePrompt'])->name('future_memos.organize.prompt');
Route::post('/future-memos/organize/ai/preview', [FutureMemoController::class, 'preview'])->name('future_memos.organize.preview');
Route::post('/future-memos/goal-candidates/memo', [FutureMemoController::class, 'candidateToMemo'])->name('future_memos.candidate.memo');
Route::post('/future-memos/goal-candidates/plan', [FutureMemoController::class, 'candidateToPlan'])->name('future_memos.candidate.plan');

// PWA identity bridge. The normal manifest has a stable Home start URL.
// Only the explicit install guide receives a short-lived first-launch handoff,
// because Safari and an iOS Home Screen app may use separate cookie stores.
Route::get('/app.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/pwa/install', [PwaController::class, 'prepareInstall'])->name('pwa.install.prepare');
Route::get('/pwa/install/{token}', [PwaController::class, 'installGuide'])->name('pwa.install.guide');
Route::get('/pwa/handoff/{token}', [PwaController::class, 'handoff'])->name('pwa.handoff');

Route::get('/health', fn () => response()->noContent()
    ->header('Access-Control-Allow-Origin', '*')
    ->header('Access-Control-Expose-Headers', 'X-Canovia-Ready, X-PaceKeeper-Ready')
    ->header('X-Canovia-Ready', '1')
    // Legacy readiness header kept so an older cached welcome page still works.
    ->header('X-PaceKeeper-Ready', '1')
    ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0'))
    ->name('health');
Route::get('/login', [AuthController::class, 'showLogin'])->middleware('guest')->name('auth.login.form');
Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:10,1'])->name('auth.login');
Route::get('/register', [AuthController::class, 'showRegister'])->middleware('guest')->name('auth.register.form');
Route::post('/register', [AuthController::class, 'register'])->middleware(['guest', 'throttle:6,1'])->name('auth.register');
Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->middleware('guest')->name('password.request');
Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware(['guest', 'throttle:6,1'])->name('password.email');
Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->middleware('guest')->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware(['guest', 'throttle:6,1'])->name('password.update');
Route::get('/account', [AuthController::class, 'account'])->middleware('auth')->name('auth.account');
Route::post('/account/mcp/link/start', [McpOAuthAccountLinkController::class, 'start'])
    ->middleware(['auth', 'throttle:5,1'])
    ->name('auth.account.mcp_link.start');
Route::get('/account/mcp/link/callback', [McpOAuthAccountLinkController::class, 'callback'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('auth.account.mcp_link.callback');
Route::post('/account/mcp/link/confirm', [McpOAuthAccountLinkController::class, 'confirm'])
    ->middleware(['auth', 'throttle:5,1'])
    ->name('auth.account.mcp_link.confirm');
Route::post('/account/mcp/link/cancel', [McpOAuthAccountLinkController::class, 'cancel'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('auth.account.mcp_link.cancel');
Route::delete('/account/mcp/grants/{grant}', [McpDelegatedRevocationController::class, 'grant'])
    ->whereNumber('grant')
    ->middleware(['auth', 'throttle:8,1'])
    ->name('auth.account.mcp_grant.revoke');
Route::delete('/account/mcp/subjects/{subject}', [McpDelegatedRevocationController::class, 'subject'])
    ->whereNumber('subject')
    ->middleware(['auth', 'throttle:8,1'])
    ->name('auth.account.mcp_subject.revoke');
Route::delete('/account', [AuthController::class, 'destroyAccount'])
    ->middleware(['auth', 'throttle:3,1'])
    ->name('auth.account.destroy');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('auth.logout');
Route::post('/offline/work-sessions/sync', [OfflineWorkSessionController::class, 'sync'])->name('offline.work_sessions.sync');
Route::get('/feedback', [CanoviaFutureController::class, 'index'])->name('feedback.index');
Route::post('/feedback/future/{roadmapFeature}/support', [CanoviaFutureController::class, 'support'])->middleware('throttle:30,1')->name('feedback.future.support');
Route::delete('/feedback/future/{roadmapFeature}/support', [CanoviaFutureController::class, 'unsupport'])->middleware('throttle:30,1')->name('feedback.future.unsupport');
Route::post('/feedback', [FeedbackController::class, 'store'])->middleware('throttle:12,1')->name('feedback.store');
Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->middleware('throttle:30,1')->name('onboarding.complete');
Route::post('/onboarding/skip', [OnboardingController::class, 'skip'])->middleware('throttle:30,1')->name('onboarding.skip');
Route::middleware('admin.access')->group(function () {
    Route::get('/admin/login', [AdminFeedbackController::class, 'login'])->name('admin.login');
    Route::post('/admin/login', [AdminFeedbackController::class, 'authenticate'])->middleware('throttle:10,1')->name('admin.authenticate');
    Route::get('/admin', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    // Legacy Feedback Admin login URLs stay valid for existing bookmarks, but
    // they are protected by the same single-account Admin boundary.
    Route::get('/admin/feedback/login', fn () => redirect()->route('admin.login'))->name('admin.feedback.login');
    Route::post('/admin/feedback/login', [AdminFeedbackController::class, 'authenticate'])->middleware('throttle:10,1')->name('admin.feedback.authenticate');
    Route::get('/admin/feedback', [AdminFeedbackController::class, 'index'])->name('admin.feedback.index');
    Route::get('/admin/telemetry', [AdminTelemetryController::class, 'index'])->name('admin.telemetry.index');
    Route::get('/admin/early-access', [AdminEarlyAccessController::class, 'index'])->name('admin.early_access.index');
    Route::get('/admin/github', [AdminGitHubDiagnosticsController::class, 'index'])->name('admin.github.index');
    Route::get('/admin/question-packs', [AdminQuestionPackController::class, 'index'])->name('admin.question_packs.index');
    Route::get('/admin/practice-demand', [AdminPracticeDemandController::class, 'index'])->name('admin.practice_demand.index');
    Route::get('/admin/goal-pattern-demand', [AdminGoalPatternDemandController::class, 'index'])->name('admin.goal_pattern_demand.index');
    Route::get('/admin/practice-demand/candidates/{candidate}', [AdminPracticeDemandController::class, 'showCandidate'])->name('admin.practice_demand.candidates.show');
    Route::post('/admin/practice-demand/candidates/{candidate}/promote', [AdminPracticeDemandController::class, 'promoteCandidate'])->name('admin.practice_demand.candidates.promote');
    Route::post('/admin/practice-demand/candidates/{candidate}/reject', [AdminPracticeDemandController::class, 'rejectCandidate'])->name('admin.practice_demand.candidates.reject');
    Route::post('/admin/practice-demand/candidates/{candidate}/reopen', [AdminPracticeDemandController::class, 'reopenCandidate'])->name('admin.practice_demand.candidates.reopen');
    Route::get('/admin/study-scenarios', [AdminStudyScenarioLabController::class, 'index'])
        ->name('admin.study_scenarios.index');
    Route::post('/admin/study-scenarios/{scenarioKey}', [AdminStudyScenarioLabController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('admin.study_scenarios.store');
    Route::delete('/admin/study-scenarios/fixtures/{fixture}', [AdminStudyScenarioLabController::class, 'destroy'])
        ->name('admin.study_scenarios.destroy');
    Route::delete('/admin/study-scenarios', [AdminStudyScenarioLabController::class, 'destroyAll'])
        ->name('admin.study_scenarios.destroy_all');

    Route::get('/admin/release-gate', [AdminReleaseGateController::class, 'index'])->name('admin.release_gate.index');
    Route::post('/admin/release-gate/review', [AdminReleaseGateController::class, 'updateReview'])->name('admin.release_gate.review.update');
    Route::delete('/admin/release-gate/review', [AdminReleaseGateController::class, 'resetReview'])->name('admin.release_gate.review.reset');
    Route::get('/admin/economy', [AdminEconomyController::class, 'index'])->name('admin.economy.index');
    Route::post('/admin/economy/grants', [AdminEconomyController::class, 'storeGrant'])->name('admin.economy.grants.store');
    Route::delete('/admin/economy/grants/{grant}', [AdminEconomyController::class, 'destroyGrant'])->name('admin.economy.grants.destroy');
    Route::post('/admin/economy/complimentary-premium', [AdminEconomyController::class, 'storeComplimentaryPremium'])->name('admin.economy.complimentary.store');
    Route::delete('/admin/economy/complimentary-premium/{user}', [AdminEconomyController::class, 'destroyComplimentaryPremium'])->name('admin.economy.complimentary.destroy');
    Route::post('/admin/preview', [AdminPreviewController::class, 'update'])->name('admin.preview.update');
    Route::post('/admin/release-level/preview', [AdminReleaseLevelController::class, 'updatePreview'])->name('admin.release_level.preview.update');
    Route::post('/admin/release-level/users/{user}', [AdminReleaseLevelController::class, 'updateUser'])->name('admin.release_level.user.update');
    Route::post('/admin/question-packs/import', [AdminQuestionPackController::class, 'import'])->name('admin.question_packs.import');
    Route::post('/admin/question-packs/import-bundled', [AdminQuestionPackController::class, 'importBundled'])->name('admin.question_packs.import_bundled');
    Route::patch('/admin/question-packs/{questionPack}/status', [AdminQuestionPackController::class, 'updateStatus'])->name('admin.question_packs.status');
    Route::patch('/admin/feedback/{feedback}/status', [AdminFeedbackController::class, 'updateStatus'])->name('admin.feedback.status');
    Route::patch('/admin/feedback/{feedback}/archive', [AdminFeedbackController::class, 'archive'])->name('admin.feedback.archive');
    Route::patch('/admin/feedback/{feedback}/restore', [AdminFeedbackController::class, 'restore'])->name('admin.feedback.restore');
    Route::post('/admin/feedback/{feedback}/release-note', [AdminFeedbackController::class, 'publishReleaseNote'])->name('admin.feedback.release_note.publish');
    Route::delete('/admin/feedback/{feedback}/release-note/{releaseNote}', [AdminFeedbackController::class, 'unpublishReleaseNote'])->name('admin.feedback.release_note.unpublish');
});


// 共同計画。共有URLは未ログインでも招待内容を確認でき、認証後に元の招待へ戻ります。
Route::get('/join/{token}', [PlanCollaborationController::class, 'joinByToken'])->middleware('throttle:30,1')->name('collaboration.join.token');

Route::middleware('auth')->group(function () {
    Route::get('/companion', [CompanionController::class, 'index'])->name('companion.index');
    Route::post('/companion/entry', [CompanionController::class, 'entry'])->name('companion.entry');
    Route::post('/companion/threads', [CompanionController::class, 'storeThread'])->name('companion.threads.store');
    Route::get('/companion/threads/{companionThread}', [CompanionController::class, 'show'])->name('companion.show');
    Route::post('/companion/threads/{companionThread}/messages', [CompanionController::class, 'send'])
        ->middleware('throttle:20,1')
        ->name('companion.messages.store');
    Route::post('/companion/threads/{companionThread}/candidates/{candidate}/apply', [CompanionController::class, 'applyCandidate'])
        ->name('companion.candidates.apply');
    Route::post('/companion/threads/{companionThread}/candidates/{candidate}/dismiss', [CompanionController::class, 'dismissCandidate'])
        ->name('companion.candidates.dismiss');

    Route::get('/collaboration/join', [PlanCollaborationController::class, 'joinForm'])->name('collaboration.join.form');
    Route::post('/collaboration/join', [PlanCollaborationController::class, 'joinByCode'])->middleware('throttle:12,1')->name('collaboration.join.code');
    Route::get('/plans/{plan}/collaboration', [PlanCollaborationController::class, 'settings'])->name('plans.collaboration.settings');
    Route::post('/plans/{plan}/collaboration', [PlanCollaborationController::class, 'enable'])->name('plans.collaboration.enable');
    Route::delete('/plans/{plan}/collaboration', [PlanCollaborationController::class, 'disable'])->name('plans.collaboration.disable');
    Route::post('/plans/{plan}/collaboration/regenerate', [PlanCollaborationController::class, 'regenerateInvite'])->name('plans.collaboration.regenerate');
    Route::patch('/plans/{plan}/collaboration/members/{member}', [PlanCollaborationController::class, 'updateMember'])->name('plans.collaboration.members.update');
    Route::delete('/plans/{plan}/collaboration/members/{member}', [PlanCollaborationController::class, 'removeMember'])->name('plans.collaboration.members.remove');
});

Route::get('/dashboard/tools', [HomeController::class, 'legacy'])->name('dashboard.tools');
Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
Route::get('/roadmap', [RoadmapController::class, 'index'])->name('roadmap.index');
Route::get('/timeline', [TimelineController::class, 'index'])->name('timeline.index');
Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
Route::post('/inbox', [InboxController::class, 'store'])->name('inbox.store');
Route::post('/inbox/{inboxItem}/suggest', [InboxController::class, 'suggest'])->name('inbox.suggest');
Route::post('/inbox/{inboxItem}/route', [InboxController::class, 'routeItem'])->name('inbox.route');
Route::patch('/inbox/{inboxItem}/status', [InboxController::class, 'updateStatus'])->name('inbox.status');
Route::get('/inbox/{inboxItem}/file', [InboxController::class, 'file'])->name('inbox.file');

Route::middleware(['auth', 'release.level:1'])->group(function () {
    Route::post('/capabilities/{capability}/plans/{plan}/setup/start', [CapabilityActivationController::class, 'start'])
        ->middleware('throttle:12,1')
        ->name('capabilities.setup.start');
    Route::post('/capabilities/{capability}/plans/{plan}/setup/abandon', [CapabilityActivationController::class, 'abandon'])
        ->middleware('throttle:12,1')
        ->name('capabilities.setup.abandon');
});

Route::post('/behavior/events', [BehaviorEventController::class, 'store'])->name('behavior_events.store');
Route::post('/recommendations/alternative', [RecommendationController::class, 'alternative'])->name('recommendations.alternative');

Route::get('/navigate', [NavigationController::class, 'index'])->name('navigation.index');
Route::post('/navigate/intent', [NavigationController::class, 'chooseIntent'])->name('navigation.intent');
Route::post('/navigate/time', [NavigationController::class, 'chooseTime'])->name('navigation.time');
Route::post('/navigate/alternative', [NavigationController::class, 'alternative'])->name('navigation.alternative');
Route::post('/navigate/reset', [NavigationController::class, 'reset'])->name('navigation.reset');

Route::post('/work-sessions', [WorkSessionController::class, 'start'])->name('work_sessions.start');
Route::get('/work-sessions/{workSession}', [WorkSessionController::class, 'active'])->name('work_sessions.active');
Route::post('/work-sessions/{workSession}/pause', [WorkSessionController::class, 'pause'])->name('work_sessions.pause');
Route::post('/work-sessions/{workSession}/resume', [WorkSessionController::class, 'resume'])->name('work_sessions.resume');
Route::post('/work-sessions/{workSession}/complete', [WorkSessionController::class, 'complete'])->name('work_sessions.complete');
Route::get('/work-sessions/{workSession}/review', [WorkSessionController::class, 'review'])->name('work_sessions.review');
Route::post('/work-sessions/{workSession}/review', [WorkSessionController::class, 'storeReview'])->name('work_sessions.review.store');
Route::post('/work-sessions/{workSession}/dismiss-plan-update', [WorkSessionController::class, 'dismissPlanUpdate'])->name('work_sessions.dismiss_plan_update');
Route::post('/work-sessions/{workSession}/interrupt', [WorkSessionController::class, 'interrupt'])->name('work_sessions.interrupt');

// ダッシュボード共通のAI JSON入力
Route::post('/dashboard/ai-json/preview', [PlanReviewAssistantController::class, 'previewFromDashboard'])
    ->name('dashboard.ai_json.preview');
Route::post('/dashboard/ai-json/resolve-legacy', [PlanReviewAssistantController::class, 'resolveLegacyFromDashboard'])
    ->name('dashboard.ai_json.resolve_legacy');
Route::post('/dashboard/ai-json/reset', [PlanReviewAssistantController::class, 'resetFromDashboard'])
    ->name('dashboard.ai_json.reset');

// 日常操作の入口となるチャットUI
Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
Route::get('/achievements', [ChatController::class, 'achievements'])->name('achievements.index');
Route::get('/achievements/{plan}', [ChatController::class, 'achievement'])->name('achievements.show');
Route::post('/chat/start/{flow}', [ChatController::class, 'start'])->name('chat.start');
Route::post('/chat/answer', [ChatController::class, 'answer'])->name('chat.answer');
Route::post('/chat/confirm', [ChatController::class, 'confirm'])->name('chat.confirm');
Route::post('/chat/context-exported', [ChatController::class, 'markContextExported'])->name('chat.context_exported');
Route::post('/chat/reset', [ChatController::class, 'reset'])->name('chat.reset');
Route::get('/my-plans', [MyPlanController::class, 'index'])->name('my_plans.index');

// 公開計画
Route::get('/public-plans', [PublicPlanController::class, 'index'])->name('public_plans.index');
Route::get('/p/{publicSlug}', [PublicPlanController::class, 'show'])->name('public_plans.show');

// 計画
Route::get('/plans/create', [GoalDiscoveryController::class, 'create'])->name('plans.create');
Route::post('/goal-discovery', [GoalDiscoveryController::class, 'store'])->middleware('throttle:12,1')->name('goal_discovery.store');
Route::get('/goal-discovery/{goalContext}', [GoalDiscoveryController::class, 'show'])->name('goal_discovery.show');
Route::post('/goal-discovery/{goalContext}/answer', [GoalDiscoveryController::class, 'answer'])->middleware('throttle:30,1')->name('goal_discovery.answer');
Route::get('/plans/create/manual', [PlanController::class, 'create'])->name('plans.create.manual');
Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
Route::get('/plans/{plan}/dashboard', PlanDashboardController::class)->name('plans.dashboard');
Route::get('/plans/{plan}', [PlanController::class, 'show'])->name('plans.show');
// V58.71: a proposal is inert until the owner accepts it as a new Task.
Route::get('/plans/{plan}/action-drafts', [PlanActionDraftController::class, 'index'])->name('plans.action_drafts.index');
Route::post('/plans/{plan}/action-drafts/spotlight/acknowledge', [PlanActionDraftSpotlightController::class, 'acknowledge'])->middleware('throttle:12,1')->name('plans.action_drafts.spotlight.acknowledge');
Route::post('/plans/{plan}/action-drafts', [PlanActionDraftController::class, 'store'])->middleware('throttle:12,1')->name('plans.action_drafts.store');
Route::post('/plans/{plan}/action-drafts/compose', [PlanActionDraftController::class, 'compose'])->middleware('throttle:12,1')->name('plans.action_drafts.compose');
Route::post('/plans/{plan}/action-drafts/compose-observed', [PlanActionDraftController::class, 'composeObserved'])->middleware('throttle:12,1')->name('plans.action_drafts.compose_observed');
Route::post('/plans/{plan}/action-drafts/{draft}/refresh-preview', [PlanActionDraftController::class, 'previewRefresh'])->middleware('throttle:12,1')->name('plans.action_drafts.refresh.preview');
Route::post('/plans/{plan}/action-drafts/{draft}/refresh-apply', [PlanActionDraftController::class, 'applyRefresh'])->middleware('throttle:12,1')->name('plans.action_drafts.refresh.apply');
Route::patch('/plans/{plan}/action-drafts/{draft}', [PlanActionDraftController::class, 'update'])->name('plans.action_drafts.update');
Route::post('/plans/{plan}/action-drafts/{draft}/prepare-steps', [PlanActionDraftController::class, 'prepareSteps'])->middleware('throttle:12,1')->name('plans.action_drafts.steps.prepare');
Route::patch('/plans/{plan}/action-drafts/{draft}/steps', [PlanActionDraftController::class, 'updateSteps'])->name('plans.action_drafts.steps.update');
Route::post('/plans/{plan}/action-drafts/{draft}/accept', [PlanActionDraftController::class, 'accept'])->name('plans.action_drafts.accept');
Route::post('/plans/{plan}/action-drafts/{draft}/dismiss', [PlanActionDraftController::class, 'dismiss'])->name('plans.action_drafts.dismiss');
Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
Route::put('/plans/{plan}/specialization', [PlanSpecializationController::class, 'update'])->middleware('throttle:12,1')->name('plans.specialization.update');
Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');
Route::get('/plans/{plan}/resources', [PlanResourceController::class, 'index'])->name('plans.resources.index');
Route::post('/plans/{plan}/resources', [PlanResourceController::class, 'store'])->name('plans.resources.store');
Route::get('/plans/{plan}/resources/assistant', [PlanResourceAssistantController::class, 'show'])->name('plans.resources.assistant');
Route::post('/plans/{plan}/resources/assistant/preview', [PlanResourceAssistantController::class, 'preview'])->name('plans.resources.assistant.preview');
Route::post('/plans/{plan}/resources/assistant/apply', [PlanResourceAssistantController::class, 'apply'])->name('plans.resources.assistant.apply');
Route::post('/plans/{plan}/resources/assistant/reset', [PlanResourceAssistantController::class, 'reset'])->name('plans.resources.assistant.reset');
Route::put('/plans/{plan}/resources/{resource}', [PlanResourceController::class, 'update'])->name('plans.resources.update');
Route::delete('/plans/{plan}/resources/{resource}', [PlanResourceController::class, 'destroy'])->name('plans.resources.destroy');

// Career workspace / low-input capture / interview learning loop.
Route::get('/plans/{plan}/career', [CareerWorkspaceController::class, 'index'])->middleware('release.level:3')->name('plans.career.index');
Route::post('/plans/{plan}/career/captures', [CareerWorkspaceController::class, 'storeCapture'])->middleware('release.level:3')->name('plans.career.captures.store');
Route::get('/plans/{plan}/career/captures/{capture}/screenshot', [CareerWorkspaceController::class, 'screenshot'])->middleware('release.level:3')->name('plans.career.captures.screenshot');
Route::delete('/plans/{plan}/career/captures/{capture}', [CareerWorkspaceController::class, 'destroyCapture'])->middleware('release.level:3')->name('plans.career.captures.destroy');
Route::post('/plans/{plan}/career/captures/{capture}/link', [CareerWorkspaceController::class, 'linkCapture'])->middleware('release.level:3')->name('plans.career.captures.link');
Route::post('/plans/{plan}/career/applications', [CareerWorkspaceController::class, 'storeApplication'])->middleware('release.level:3')->name('plans.career.applications.store');
Route::patch('/plans/{plan}/career/applications/{application}', [CareerWorkspaceController::class, 'updateApplication'])->middleware('release.level:3')->name('plans.career.applications.update');
Route::post('/plans/{plan}/career/applications/{application}/events', [CareerWorkspaceController::class, 'storeSelectionEvent'])->middleware('release.level:3')->name('plans.career.events.store');
Route::patch('/plans/{plan}/career/events/{event}/result', [CareerWorkspaceController::class, 'updateSelectionEventResult'])->middleware('release.level:3')->name('plans.career.events.result');
Route::patch('/plans/{plan}/career/events/{event}/cancel', [CareerWorkspaceController::class, 'cancelSelectionEvent'])->middleware('release.level:3')->name('plans.career.events.cancel');
Route::get('/plans/{plan}/career/interviews/{event}/review', [InterviewReviewController::class, 'show'])->middleware('release.level:3')->name('plans.career.interview_reviews.show');
Route::post('/plans/{plan}/career/interviews/{event}/review', [InterviewReviewController::class, 'store'])->middleware('release.level:3')->name('plans.career.interview_reviews.store');
Route::middleware('feature.access:'.FeatureKey::ProjectArtifact->value)->group(function () {
    Route::get('/plans/{plan}/artifacts', [PlanArtifactController::class, 'index'])->name('plans.artifacts.index');
    Route::post('/plans/{plan}/artifacts', [PlanArtifactController::class, 'store'])->name('plans.artifacts.store');
    Route::put('/plans/{plan}/artifacts/{artifact}', [PlanArtifactController::class, 'update'])->name('plans.artifacts.update');
    Route::delete('/plans/{plan}/artifacts/{artifact}', [PlanArtifactController::class, 'destroy'])->name('plans.artifacts.destroy');

    Route::get('/github-workflow', [GitHubWorkflowController::class, 'index'])->name('github_workflow.index');
    Route::post('/github-workflow', [GitHubWorkflowController::class, 'store'])->name('github_workflow.store');
    Route::patch('/github-workflow/artifacts/{artifact}/state', [GitHubWorkflowController::class, 'updateState'])
        ->name('github_workflow.state.update');
    Route::post('/github-workflow/artifacts/{artifact}/repository-refresh', [GitHubWorkflowController::class, 'refreshRepository'])
        ->middleware('throttle:3,1')
        ->name('github_workflow.repository.refresh');
    Route::post('/github-workflow/artifacts/{artifact}/repository-change', [GitHubWorkflowController::class, 'proposeRepositoryChange'])
        ->middleware('throttle:3,1')
        ->name('github_workflow.repository.change');
    Route::post('/github-workflow/artifacts/{artifact}/github-app/connect', [GitHubWorkflowController::class, 'beginRepositoryConnection'])
        ->middleware('throttle:6,1')
        ->name('github_workflow.app.connect');
    Route::get('/github-workflow/github-app/setup', [GitHubWorkflowController::class, 'completeRepositoryConnection'])
        ->middleware('throttle:12,1')
        ->name('github_workflow.app.setup');
    Route::post('/github-workflow/artifacts/{artifact}/github-app/check', [GitHubWorkflowController::class, 'checkRepositoryConnection'])
        ->middleware('throttle:6,1')
        ->name('github_workflow.app.check');
    Route::post('/plans/{plan}/development-observations/{observation}/link', [DevelopmentActivityObservationController::class, 'link'])
        ->middleware('throttle:30,1')
        ->name('plans.development_observations.link');
    Route::post('/plans/{plan}/development-observations/{observation}/ignore', [DevelopmentActivityObservationController::class, 'ignore'])
        ->middleware('throttle:30,1')
        ->name('plans.development_observations.ignore');
    Route::post('/plans/{plan}/development-readiness/tasks/{task}/quality-gate', [DevelopmentReadinessController::class, 'confirmQualityGate'])
        ->middleware('throttle:12,1')
        ->name('plans.development_readiness.quality_gate.confirm');
    Route::post('/plans/{plan}/tasks/{task}/development-provider-triage', DevelopmentProviderTriageController::class)
        ->middleware('throttle:12,1')
        ->name('plans.development_provider_triage.inspect');
});

// 資格学習のActivity選択はAI演習より上位の共通入口として扱う。
Route::get('/plans/{plan}/tasks/{task}/guided-execution', [GuidedExecutionController::class, 'show'])
    ->name('plans.tasks.guided_execution.show');
Route::post('/plans/{plan}/tasks/{task}/guided-execution', [GuidedExecutionController::class, 'prepare'])
    ->name('plans.tasks.guided_execution.prepare');
Route::post('/plans/{plan}/tasks/{task}/guided-execution/{guidedExecution}/reflect', [GuidedExecutionController::class, 'reflect'])
    ->name('plans.tasks.guided_execution.reflect');
Route::post('/plans/{plan}/tasks/{task}/guided-execution/{guidedExecution}/cancel', [GuidedExecutionController::class, 'cancel'])
    ->name('plans.tasks.guided_execution.cancel');

// Context-aware Execution Orchestration. PacketはTaskの現在Contextから都度導出し、DBへ二重保存しない。
Route::get('/plans/{plan}/tasks/{task}/execution-orchestration', [ExecutionOrchestrationController::class, 'show'])
    ->name('plans.tasks.execution_orchestration.show');
Route::post('/plans/{plan}/tasks/{task}/execution-orchestration/prepare', [ExecutionOrchestrationController::class, 'prepare'])
    ->name('plans.tasks.execution_orchestration.prepare');
Route::post('/plans/{plan}/tasks/{task}/execution-orchestration/import', [ExecutionOrchestrationController::class, 'importExternal'])
    ->name('plans.tasks.execution_orchestration.import');
Route::post('/plans/{plan}/tasks/{task}/execution-orchestration/reset', [ExecutionOrchestrationController::class, 'reset'])
    ->name('plans.tasks.execution_orchestration.reset');
Route::post('/plans/{plan}/tasks/{task}/execution-orchestration/github/prepare', [ExecutionGitHubHandoffController::class, 'prepare'])
    ->middleware('throttle:6,1')
    ->name('plans.tasks.execution_orchestration.github.prepare');
Route::post('/plans/{plan}/tasks/{task}/execution-orchestration/github/confirm', [ExecutionGitHubHandoffController::class, 'confirm'])
    ->middleware('throttle:3,1')
    ->name('plans.tasks.execution_orchestration.github.confirm');
Route::post('/plans/{plan}/tasks/{task}/execution-orchestration/github/discard', [ExecutionGitHubHandoffController::class, 'discard'])
    ->name('plans.tasks.execution_orchestration.github.discard');
Route::post('/plans/{plan}/tasks/{task}/execution-orchestration/github/{artifact}/return-sync', [ExecutionGitHubHandoffController::class, 'syncReturn'])
    ->middleware('throttle:6,1')
    ->name('plans.tasks.execution_orchestration.github.return_sync');
Route::post('/plans/{plan}/tasks/{task}/execution-orchestration/github/{artifact}/decision/apply', [ExecutionGitHubHandoffController::class, 'applyEvidenceDecision'])
    ->middleware('throttle:6,1')
    ->name('plans.tasks.execution_orchestration.github.decision.apply');

// One Plan can prepare multiple task-scoped packets while keeping the same canonical Plan context.
Route::get('/plans/{plan}/execution-distribution', [ExecutionDistributionController::class, 'show'])
    ->name('plans.execution_distribution.show');
Route::post('/plans/{plan}/execution-distribution/prepare', [ExecutionDistributionController::class, 'prepare'])
    ->name('plans.execution_distribution.prepare');
Route::post('/plans/{plan}/execution-distribution/reset', [ExecutionDistributionController::class, 'reset'])
    ->name('plans.execution_distribution.reset');

// V53.4 Study Scope Capture: Plan-level test range intake and human confirmation.
Route::get('/plans/{plan}/study-scope', [StudyScopeCaptureController::class, 'index'])
    ->name('plans.study_scope.index');
Route::post('/plans/{plan}/study-scope', [StudyScopeCaptureController::class, 'store'])
    ->middleware('throttle:8,1')
    ->name('plans.study_scope.store');
Route::post('/plans/{plan}/study-scope/{capture}/analyze', [StudyScopeCaptureController::class, 'analyze'])
    ->middleware('throttle:6,1')
    ->name('plans.study_scope.analyze');
Route::post('/plans/{plan}/study-scope/{capture}/confirm', [StudyScopeCaptureController::class, 'confirm'])
    ->name('plans.study_scope.confirm');
Route::delete('/plans/{plan}/study-scope/{capture}', [StudyScopeCaptureController::class, 'destroy'])
    ->name('plans.study_scope.destroy');

// V58.61 A user-confirmed bookkeeping foundation diagnostic records its own
// source-labelled evidence, without changing Task progress or forcing grade order.
Route::get('/plans/{plan}/bookkeeping-placement', [BookkeepingPlacementController::class, 'show'])
    ->middleware('release.level:1')
    ->name('plans.bookkeeping_placement.show');
Route::post('/plans/{plan}/bookkeeping-placement', [BookkeepingPlacementController::class, 'store'])
    ->middleware(['release.level:1', 'throttle:12,1'])
    ->name('plans.bookkeeping_placement.store');

// V58.62 explicit, Plan-scoped bookkeeping journal practice.
Route::get('/plans/{plan}/bookkeeping-journal', [BookkeepingJournalPracticeController::class, 'show'])
    ->middleware('release.level:1')
    ->name('plans.bookkeeping_journal.show');
Route::post('/plans/{plan}/bookkeeping-journal', [BookkeepingJournalPracticeController::class, 'store'])
    ->middleware(['release.level:1', 'throttle:12,1'])
    ->name('plans.bookkeeping_journal.store');

// V56.16 Score / Baseline Evidence: external score scale is kept separate from Practice accuracy.
Route::get('/plans/{plan}/study-scores', [StudyScoreController::class, 'index'])
    ->name('plans.study_scores.index');
Route::post('/plans/{plan}/study-scores', [StudyScoreController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('plans.study_scores.store');
Route::delete('/plans/{plan}/study-scores/{studyScoreObservation}', [StudyScoreController::class, 'destroy'])
    ->name('plans.study_scores.destroy');

// V56.17 explicit Learning Type confirmation / override.
Route::put('/plans/{plan}/study-learning-type', [StudyLearningTypeController::class, 'update'])
    ->name('plans.study_learning_type.update');
Route::delete('/plans/{plan}/study-learning-type', [StudyLearningTypeController::class, 'destroy'])
    ->name('plans.study_learning_type.destroy');

Route::post('/plans/{plan}/study-action/execute', [StudyAdaptiveActionController::class, 'execute'])
    ->name('plans.study_action.execute');

// 資格学習のActivity選択はAI演習より上位の共通入口として扱う。
Route::get('/plans/{plan}/tasks/{task}/study-activity', [StudyActivityController::class, 'show'])
    ->name('plans.tasks.study_activity.show');

Route::get('/plans/{plan}/tasks/{task}/study-language/{activity}', [StudyLanguageActivityController::class, 'show'])
    ->name('plans.tasks.study_language.show');
Route::post('/plans/{plan}/tasks/{task}/study-language/{activity}', [StudyLanguageActivityController::class, 'store'])
    ->name('plans.tasks.study_language.store');

Route::get('/plans/{plan}/tasks/{task}/study-resource', [StudyResourceActivityController::class, 'show'])
    ->name('plans.tasks.study_resource.show');
Route::post('/plans/{plan}/tasks/{task}/study-resource', [StudyResourceActivityController::class, 'store'])
    ->name('plans.tasks.study_resource.store');

Route::get('/plans/{plan}/tasks/{task}/study-recall', [StudyRecallController::class, 'show'])
    ->name('plans.tasks.study_recall.show');
Route::post('/plans/{plan}/tasks/{task}/study-recall/items', [StudyRecallController::class, 'store'])
    ->name('plans.tasks.study_recall.items.store');
Route::post('/plans/{plan}/tasks/{task}/study-recall/items/{item}/review', [StudyRecallController::class, 'review'])
    ->name('plans.tasks.study_recall.items.review');
Route::post('/plans/{plan}/tasks/{task}/study-recall/complete', [StudyRecallController::class, 'complete'])
    ->name('plans.tasks.study_recall.complete');
Route::delete('/plans/{plan}/tasks/{task}/study-recall/items/{item}', [StudyRecallController::class, 'destroy'])
    ->name('plans.tasks.study_recall.items.destroy');
Route::post('/plans/{plan}/tasks/{task}/study-recall/candidates/extract', [StudyRecallCandidateController::class, 'extract'])
    ->name('plans.tasks.study_recall.candidates.extract');
Route::post('/plans/{plan}/tasks/{task}/study-recall/resources/{resource}/extract', [StudyRecallCandidateController::class, 'extractFromResource'])
    ->name('plans.tasks.study_recall.resources.extract');
Route::post('/plans/{plan}/tasks/{task}/study-recall/candidates/extract-batch', [StudyRecallCandidateController::class, 'extractBatch'])
    ->name('plans.tasks.study_recall.candidates.extract_batch');
Route::post('/plans/{plan}/tasks/{task}/study-recall/candidates/review', [StudyRecallCandidateController::class, 'reviewBatch'])
    ->name('plans.tasks.study_recall.candidates.review');
Route::post('/plans/{plan}/tasks/{task}/study-recall/sources/{source}/retry', [StudyRecallCandidateController::class, 'retry'])
    ->name('plans.tasks.study_recall.sources.retry');
Route::get('/plans/{plan}/tasks/{task}/study-recall/sources/{source}/file', [StudyRecallCandidateController::class, 'sourceFile'])
    ->name('plans.tasks.study_recall.sources.file');

// Canovia Tools: 資格学習向けAI演習。
// Freeは外部AIとのJSON handoffを維持し、Premium CoreはNative AIを同じ演習UIへ接続する。
Route::middleware('feature.access:'.FeatureKey::AiPractice->value)->group(function () {
    Route::get('/plans/{plan}/tasks/{task}/study-practice', [StudyPracticeController::class, 'show'])->name('plans.tasks.study_practice.show');
    // Adaptive Learning pilot: Bank-only single-choice A/B. Exam mode remains gated.
    Route::get('/plans/{plan}/tasks/{task}/learning', [AdaptiveLearningController::class, 'index'])->name('plans.tasks.learning.index');
    Route::post('/plans/{plan}/tasks/{task}/learning/answers/{answerEvent}/adjustment', [AdaptiveLearningEvaluationAdjustmentController::class, 'store'])->middleware('throttle:12,1')->name('plans.tasks.learning.evaluation_adjustments.store');
    Route::post('/plans/{plan}/tasks/{task}/learning/exam-start', [AdaptiveLearningExamController::class, 'start'])->middleware('throttle:12,1')->name('plans.tasks.learning.exam.start');
    Route::get('/plans/{plan}/tasks/{task}/learning/exam/{learningRun}', [AdaptiveLearningExamController::class, 'show'])->name('plans.tasks.learning.exam.show');
    Route::post('/plans/{plan}/tasks/{task}/learning/exam/{learningRun}/answer', [AdaptiveLearningExamController::class, 'answer'])->middleware('throttle:60,1')->name('plans.tasks.learning.exam.answer');
    Route::post('/plans/{plan}/tasks/{task}/learning/exam/{learningRun}/navigate', [AdaptiveLearningExamController::class, 'navigate'])->middleware('throttle:60,1')->name('plans.tasks.learning.exam.navigate');
    Route::post('/plans/{plan}/tasks/{task}/learning/exam/{learningRun}/finish', [AdaptiveLearningExamController::class, 'finish'])->middleware('throttle:12,1')->name('plans.tasks.learning.exam.finish');
    Route::post('/plans/{plan}/tasks/{task}/learning', [AdaptiveLearningController::class, 'start'])->middleware('throttle:12,1')->name('plans.tasks.learning.start');
    Route::get('/plans/{plan}/tasks/{task}/learning/{learningRun}', [AdaptiveLearningController::class, 'show'])->name('plans.tasks.learning.show');
    Route::post('/plans/{plan}/tasks/{task}/learning/{learningRun}/answer', [AdaptiveLearningController::class, 'answer'])->middleware('throttle:40,1')->name('plans.tasks.learning.answer');
    Route::post('/plans/{plan}/tasks/{task}/learning/{learningRun}/next', [AdaptiveLearningController::class, 'next'])->middleware('throttle:40,1')->name('plans.tasks.learning.next');
    Route::post('/plans/{plan}/tasks/{task}/learning/{learningRun}/finish', [AdaptiveLearningController::class, 'finish'])->middleware('throttle:12,1')->name('plans.tasks.learning.finish');
    Route::get('/plans/{plan}/tasks/{task}/study-practice/resume', [StudyPracticeController::class, 'resume'])->name('plans.tasks.study_practice.resume');
    Route::post('/plans/{plan}/tasks/{task}/study-practice/prepare', [StudyPracticeController::class, 'prepare'])->name('plans.tasks.study_practice.prepare');
    Route::post('/plans/{plan}/tasks/{task}/study-practice/native/prepare', [StudyPracticeController::class, 'prepareNative'])->name('plans.tasks.study_practice.native.prepare');
    Route::post('/plans/{plan}/tasks/{task}/study-practice/import', [StudyPracticeController::class, 'import'])->name('plans.tasks.study_practice.import');
    Route::post('/plans/{plan}/tasks/{task}/study-practice/draft', [StudyPracticeController::class, 'saveDraft'])->name('plans.tasks.study_practice.draft');
    Route::post('/plans/{plan}/tasks/{task}/study-practice/answers', [StudyPracticeController::class, 'submitAnswers'])->name('plans.tasks.study_practice.answers');
    Route::post('/plans/{plan}/tasks/{task}/study-practice/assessment', [StudyPracticeController::class, 'previewAssessment'])->name('plans.tasks.study_practice.assessment');
    Route::post('/plans/{plan}/tasks/{task}/study-practice/apply', [StudyPracticeController::class, 'applyAssessment'])->name('plans.tasks.study_practice.apply');
    Route::post('/plans/{plan}/tasks/{task}/study-practice/reset', [StudyPracticeController::class, 'reset'])->name('plans.tasks.study_practice.reset');
});

Route::get('/plans/{plan}/ai-task-assistant', [AiTaskAssistantController::class, 'show'])
    ->name('plans.ai_task_assistant.show');

Route::post('/plans/{plan}/ai-task-assistant/import', [AiTaskAssistantController::class, 'import'])
    ->name('plans.ai_task_assistant.import');


// 実績をもとに計画を見直すAI支援
Route::get('/plans/{plan}/review-assistant', [PlanReviewAssistantController::class, 'show'])
    ->name('plans.review_assistant.show');
Route::post('/plans/{plan}/review-assistant/prompt', [PlanReviewAssistantController::class, 'generatePrompt'])
    ->name('plans.review_assistant.prompt');
Route::post('/plans/{plan}/review-assistant/preview', [PlanReviewAssistantController::class, 'preview'])
    ->name('plans.review_assistant.preview');
Route::post('/plans/{plan}/review-assistant/apply', [PlanReviewAssistantController::class, 'apply'])
    ->name('plans.review_assistant.apply');
Route::post('/plans/{plan}/review-assistant/reset', [PlanReviewAssistantController::class, 'reset'])
    ->name('plans.review_assistant.reset');

// タスク
Route::get('/tasks/{task}/edit', [TaskController::class, 'edit'])->name('tasks.edit');
Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

// 作業ログ
Route::delete('/work-logs/{workLog}', [WorkLogController::class, 'destroy'])->name('work_logs.destroy');

// テンプレート
Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
Route::get('/templates/{planTemplate}', [TemplateController::class, 'show'])->name('templates.show');
Route::post('/templates/{planTemplate}/use', [TemplateController::class, 'use'])->name('templates.use');

// 管理者用テンプレート
Route::get('/admin/templates/login', [AdminTemplateController::class, 'login'])->name('admin.templates.login');
Route::post('/admin/templates/login', [AdminTemplateController::class, 'authenticate'])->name('admin.templates.authenticate');
Route::get('/admin/templates/create', [AdminTemplateController::class, 'create'])->name('admin.templates.create');
Route::post('/admin/templates', [AdminTemplateController::class, 'store'])->name('admin.templates.store');
