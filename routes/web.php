<?php

use Illuminate\Support\Facades\Route;
use App\Enums\FeatureKey;
use App\Http\Controllers\WorkLogController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PlanController;
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
use App\Http\Controllers\AdminQuestionPackController;
use App\Http\Controllers\AdminPracticeDemandController;
use App\Http\Controllers\AdminGoalPatternDemandController;
use App\Http\Controllers\AdminEconomyController;
use App\Http\Controllers\AdminPreviewController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\RoadmapController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\PlanCollaborationController;
use App\Http\Controllers\PlanResourceController;
use App\Http\Controllers\PlanResourceAssistantController;
use App\Http\Controllers\PlanArtifactController;
use App\Http\Controllers\StudyPracticeController;
use App\Http\Controllers\StudyActivityController;
use App\Http\Controllers\StudyRecallController;
use App\Http\Controllers\StudyRecallCandidateController;
use App\Http\Controllers\FutureMemoController;
use App\Http\Controllers\CareerWorkspaceController;
use App\Http\Controllers\InterviewReviewController;
use App\Http\Controllers\GuidedExecutionController;
use App\Http\Controllers\CompanionController;

Route::get('/', [HomeController::class, 'index'])->name('home');

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
    Route::get('/admin/question-packs', [AdminQuestionPackController::class, 'index'])->name('admin.question_packs.index');
    Route::get('/admin/practice-demand', [AdminPracticeDemandController::class, 'index'])->name('admin.practice_demand.index');
    Route::get('/admin/goal-pattern-demand', [AdminGoalPatternDemandController::class, 'index'])->name('admin.goal_pattern_demand.index');
    Route::get('/admin/practice-demand/candidates/{candidate}', [AdminPracticeDemandController::class, 'showCandidate'])->name('admin.practice_demand.candidates.show');
    Route::post('/admin/practice-demand/candidates/{candidate}/promote', [AdminPracticeDemandController::class, 'promoteCandidate'])->name('admin.practice_demand.candidates.promote');
    Route::post('/admin/practice-demand/candidates/{candidate}/reject', [AdminPracticeDemandController::class, 'rejectCandidate'])->name('admin.practice_demand.candidates.reject');
    Route::post('/admin/practice-demand/candidates/{candidate}/reopen', [AdminPracticeDemandController::class, 'reopenCandidate'])->name('admin.practice_demand.candidates.reopen');
    Route::get('/admin/economy', [AdminEconomyController::class, 'index'])->name('admin.economy.index');
    Route::post('/admin/economy/grants', [AdminEconomyController::class, 'storeGrant'])->name('admin.economy.grants.store');
    Route::delete('/admin/economy/grants/{grant}', [AdminEconomyController::class, 'destroyGrant'])->name('admin.economy.grants.destroy');
    Route::post('/admin/economy/complimentary-premium', [AdminEconomyController::class, 'storeComplimentaryPremium'])->name('admin.economy.complimentary.store');
    Route::delete('/admin/economy/complimentary-premium/{user}', [AdminEconomyController::class, 'destroyComplimentaryPremium'])->name('admin.economy.complimentary.destroy');
    Route::post('/admin/preview', [AdminPreviewController::class, 'update'])->name('admin.preview.update');
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
Route::post('/goal-discovery', [GoalDiscoveryController::class, 'store'])->name('goal_discovery.store');
Route::get('/goal-discovery/{goalContext}', [GoalDiscoveryController::class, 'show'])->name('goal_discovery.show');
Route::post('/goal-discovery/{goalContext}/answer', [GoalDiscoveryController::class, 'answer'])->name('goal_discovery.answer');
Route::get('/plans/create/manual', [PlanController::class, 'create'])->name('plans.create.manual');
Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
Route::get('/plans/{plan}', [PlanController::class, 'show'])->name('plans.show');
Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
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
Route::get('/plans/{plan}/career', [CareerWorkspaceController::class, 'index'])->name('plans.career.index');
Route::post('/plans/{plan}/career/captures', [CareerWorkspaceController::class, 'storeCapture'])->name('plans.career.captures.store');
Route::get('/plans/{plan}/career/captures/{capture}/screenshot', [CareerWorkspaceController::class, 'screenshot'])->name('plans.career.captures.screenshot');
Route::delete('/plans/{plan}/career/captures/{capture}', [CareerWorkspaceController::class, 'destroyCapture'])->name('plans.career.captures.destroy');
Route::post('/plans/{plan}/career/captures/{capture}/link', [CareerWorkspaceController::class, 'linkCapture'])->name('plans.career.captures.link');
Route::post('/plans/{plan}/career/applications', [CareerWorkspaceController::class, 'storeApplication'])->name('plans.career.applications.store');
Route::patch('/plans/{plan}/career/applications/{application}', [CareerWorkspaceController::class, 'updateApplication'])->name('plans.career.applications.update');
Route::post('/plans/{plan}/career/applications/{application}/events', [CareerWorkspaceController::class, 'storeSelectionEvent'])->name('plans.career.events.store');
Route::patch('/plans/{plan}/career/events/{event}/result', [CareerWorkspaceController::class, 'updateSelectionEventResult'])->name('plans.career.events.result');
Route::patch('/plans/{plan}/career/events/{event}/cancel', [CareerWorkspaceController::class, 'cancelSelectionEvent'])->name('plans.career.events.cancel');
Route::get('/plans/{plan}/career/interviews/{event}/review', [InterviewReviewController::class, 'show'])->name('plans.career.interview_reviews.show');
Route::post('/plans/{plan}/career/interviews/{event}/review', [InterviewReviewController::class, 'store'])->name('plans.career.interview_reviews.store');
Route::middleware('feature.access:'.FeatureKey::ProjectArtifact->value)->group(function () {
    Route::get('/plans/{plan}/artifacts', [PlanArtifactController::class, 'index'])->name('plans.artifacts.index');
    Route::post('/plans/{plan}/artifacts', [PlanArtifactController::class, 'store'])->name('plans.artifacts.store');
    Route::put('/plans/{plan}/artifacts/{artifact}', [PlanArtifactController::class, 'update'])->name('plans.artifacts.update');
    Route::delete('/plans/{plan}/artifacts/{artifact}', [PlanArtifactController::class, 'destroy'])->name('plans.artifacts.destroy');
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

// 資格学習のActivity選択はAI演習より上位の共通入口として扱う。
Route::get('/plans/{plan}/tasks/{task}/study-activity', [StudyActivityController::class, 'show'])
    ->name('plans.tasks.study_activity.show');

Route::get('/plans/{plan}/tasks/{task}/study-recall', [StudyRecallController::class, 'show'])
    ->name('plans.tasks.study_recall.show');
Route::post('/plans/{plan}/tasks/{task}/study-recall/items', [StudyRecallController::class, 'store'])
    ->name('plans.tasks.study_recall.items.store');
Route::post('/plans/{plan}/tasks/{task}/study-recall/items/{item}/review', [StudyRecallController::class, 'review'])
    ->name('plans.tasks.study_recall.items.review');
Route::delete('/plans/{plan}/tasks/{task}/study-recall/items/{item}', [StudyRecallController::class, 'destroy'])
    ->name('plans.tasks.study_recall.items.destroy');
Route::post('/plans/{plan}/tasks/{task}/study-recall/candidates/extract', [StudyRecallCandidateController::class, 'extract'])
    ->name('plans.tasks.study_recall.candidates.extract');
Route::post('/plans/{plan}/tasks/{task}/study-recall/candidates/review', [StudyRecallCandidateController::class, 'reviewBatch'])
    ->name('plans.tasks.study_recall.candidates.review');
Route::get('/plans/{plan}/tasks/{task}/study-recall/sources/{source}/file', [StudyRecallCandidateController::class, 'sourceFile'])
    ->name('plans.tasks.study_recall.sources.file');

// Canovia Tools: 資格学習向けAI演習。
// Freeは外部AIとのJSON handoffを維持し、Premium CoreはNative AIを同じ演習UIへ接続する。
Route::middleware('feature.access:'.FeatureKey::AiPractice->value)->group(function () {
    Route::get('/plans/{plan}/tasks/{task}/study-practice', [StudyPracticeController::class, 'show'])->name('plans.tasks.study_practice.show');
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
