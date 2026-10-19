# AP-first dependency / schema evidence appendix — 2026-10-20

[判定・4案・STOPの正本](AP_FIRST_READ_RELEASE_FEASIBILITY_2026_10_20.md)。**docs-only / 未リリース / NO-GO**。
比較は旧Live source `15abdc9de6f24b5250fb255c579d1aa55facaf1a` と固定candidate `e858b9c855970fbe73f37a2d60c9db6525b132d6`。
`A/M/U` はこの2つのGit tree間の追加/変更/同一。実DB台帳やruntimeの設定値ではない。

## 1. 閉包の定義と監査の限界

1. Phase Aのbearer read、link/consent/revokeを手作業で辿ったcoreを`A`と記す。安全性の正の依存だけでなくdefault-off/拒否・staging分岐も保持する。
2. controller method DIがflagより前に構築する他phaseを`EAGER`と記す。constructor解決はSQL実行の証明ではないが、classを欠落させてよい証明にもならない。
3. Account/Study Workspace/controller、AppServiceProvider、全App middleware、literal Blade参照をrootに、`App\…`参照とsame-namespace class tokenを固定点まで展開した**保守的whole-class参照閉包**。lazy relation/method/comments由来の過大評価もあり、最小実行集合とは呼ばない。
4. local probeの`get_included_files()`でCLI bootstrap/route登録/Account描画等の追加loadも別記。実OAuth・Study全画面の動的到達網羅ではない。
5. containerの動的解決、conditional view、既存JS bundleと共通UIを小さな独立バックポートへ分離した実装はない。このmanifestをcherry-pick許可票にしない。**semanticな最小backportが閉じたとは認定しない**。巨大な共通境界と未解決frontier自体を案2非推奨の根拠とする。

集計：class-reference 272 files（{'UNCHANGED': 201, 'M': 35, 'A': 36}）、literal Blade 25、合成probe loaded App 87。probe-only 29を加えたApp manifestは301 files。これらを同じcoverageとして加算しない。

## 2. Composer / bootstrap / route / middleware / asset境界

PHP要求`^8.3`、Laravel lock `v13.18.0`・Tinker `v3.0.2`は旧Live sourceと同じ。新規は `firebase/php-jwt v7.2.1`（require `^7.2`）で、composer.lockは75行追加/1行変更。今回PHP8.4.26＋同じlockの既存vendorをコピーし、インストール/更新はしない。実RenderのPHP patch版・vendor/image identityは未再観測。

| ファイル | Git差分 | 到達/影響 |
| --- | --- | --- |
| `composer.json` | M | JWT追加、PHP/Laravel要件は維持 |
| `composer.lock` | M | JWT v7.2.1追加。dependency全比較はGit lockで再現 |
| `bootstrap/app.php` | M | global/web middlewareとrelease alias |
| `bootstrap/providers.php` | U | provider登録 |
| `app/Providers/AppServiceProvider.php` | M | binding/boot callbacks、read時のTask更新callbackは発火しない |
| `routes/api.php` | U | blob同一、同じcontroller名の実装が変更 |
| `routes/web.php` | M | auth/session/CSRF/throttleとlink/consent/revoke、Study入口L1 |
| `routes/console.php` | M | CLI起動時コマンド登録。不要コマンドを実機で実行しない |
| `app/Console/Commands/CheckReleaseSchemaLedger.php` | A | 114 sourceとledgerの比較、97拒否 |
| `docker/render-start.sh` | M | 旧auto migrateからcheckへ |
| `docker/startup-migration-policy.sh` | A | default check / exact-SHA apply1回 |
| `Dockerfile` | U | build/runtime境界。今回変更なし |
| `config/canovia_mcp.php` | M | 全MCP flagsとissuer/resource/client/identity key |
| `config/canovia_staging.php` | M | synthetic isolation。real Studyはstaging拒否 |
| `config/release_levels.php` | M | 一般L0と明示tester override |
| `config/canovia.php` | M | app共通設定 |
| `config/app.php` | U | env/URL/key |
| `config/auth.php` | U | web session guard |
| `config/database.php` | U | DB接続設定。実値未取得 |
| `config/cache.php` | U | JWKS等のcache境界 |
| `config/session.php` | U | login/PKCE/CSRF継続 |
| `config/services.php` | U | provider設定境界 |
| `config/intelligence.php` | M | Watch等の別flag |
| `config/performance.php` | U | 診断はoff、raw queryを公開しない |
| `package.json` | U | asset build要件 |
| `package-lock.json` | U | 既存lock維持 |
| `vite.config.js` | U | Vite build |
| `resources/js/app.js` | M | 共通UI entry、差分あり |
| `resources/css/app.css` | M | 共通UI style、差分あり |
| `phpunit.xml` | M | 既存development fixture用L4。公開設定ではなく、L0/L1は独立test |

APIはstateless bearerで、Canovia cookieだけでは認証できない。web link/consentはsession＋CSRF＋auth＋throttleを通る。shared shellの他機能をONにしない。Laravel/vendor内の到達はcomposer.lockに委任し、未宣言のpackageを追加しない。

## 3. Appファイル名を省略しないmanifest

`A`=Phase Aのtrust/data/consent core、`EAGER`=OFFでもcontrollerがDI解決、`SHARED`=保守的UI/whole-class境界、`BOOT_ONLY`=probeでloadしたが上の参照閉包外。
`probe=yes`はload観測のみで、全methodの実行ではない。

| ファイル | Git差分 | 分類 | probe |
| --- | --- | --- | --- |
| `app/Console/Commands/CheckReleaseSchemaLedger.php` | A | BOOT_ONLY | yes |
| `app/Console/Commands/CompareApExamCandidateHumanRevisions.php` | A | BOOT_ONLY | yes |
| `app/Console/Commands/ExportStudyOfficialSyllabusReviewPacket.php` | A | BOOT_ONLY | yes |
| `app/Console/Commands/ExportStudyOfficialSyllabusReviewWorkbook.php` | A | BOOT_ONLY | yes |
| `app/Console/Commands/InspectApExamCandidateHumanReviewNotes.php` | A | BOOT_ONLY | yes |
| `app/Console/Commands/PreflightStudyOfficialSyllabusReviewSubmission.php` | A | BOOT_ONLY | yes |
| `app/Console/Commands/PrepareApExamCandidateNewDraft.php` | A | BOOT_ONLY | yes |
| `app/Console/Commands/ShowApExamCandidateOwnerReviewWave.php` | A | BOOT_ONLY | yes |
| `app/Contracts/EntitlementResolver.php` | U | SHARED | — |
| `app/Contracts/ExecutionActivityProjector.php` | U | SHARED | — |
| `app/Contracts/ExecutionProviderCatalog.php` | U | SHARED | — |
| `app/Data/ExecutionProviderDefinitionData.php` | U | SHARED | — |
| `app/Data/ExecutionResolutionData.php` | U | SHARED | — |
| `app/Data/ExecutionSetupData.php` | U | SHARED | — |
| `app/Data/FeatureAccessDecision.php` | U | SHARED | — |
| `app/Data/PlanCategoryProfileData.php` | U | A | yes |
| `app/Data/WorkspaceModeContextData.php` | U | SHARED | yes |
| `app/Data/WorkspaceModeDefinitionData.php` | U | SHARED | yes |
| `app/Enums/BehaviorEventType.php` | U | SHARED | — |
| `app/Enums/EntitlementSource.php` | U | SHARED | — |
| `app/Enums/EvidenceSource.php` | U | SHARED | — |
| `app/Enums/ExecutionProviderKind.php` | U | SHARED | — |
| `app/Enums/FeatureKey.php` | U | SHARED | yes |
| `app/Enums/ProductKey.php` | U | SHARED | yes |
| `app/Enums/ReleaseLevel.php` | U | SHARED | yes |
| `app/Enums/WorkspaceMode.php` | U | SHARED | yes |
| `app/Enums/WorkspaceModeSource.php` | U | SHARED | yes |
| `app/Execution/ExecutionCapability.php` | U | SHARED | — |
| `app/Http/Controllers/AdaptiveLearningNextActionController.php` | A | BOOT_ONLY | yes |
| `app/Http/Controllers/AuthController.php` | M | SHARED | yes |
| `app/Http/Controllers/CareerModeWorkspaceController.php` | M | BOOT_ONLY | yes |
| `app/Http/Controllers/ClientPerformanceController.php` | U | BOOT_ONLY | yes |
| `app/Http/Controllers/Controller.php` | U | A | yes |
| `app/Http/Controllers/CoreFragmentBundleController.php` | U | BOOT_ONLY | yes |
| `app/Http/Controllers/DevelopmentCodingAgentHandoffController.php` | U | BOOT_ONLY | yes |
| `app/Http/Controllers/DevelopmentOperationalProfileController.php` | A | BOOT_ONLY | yes |
| `app/Http/Controllers/DevelopmentOperationalTargetController.php` | A | BOOT_ONLY | yes |
| `app/Http/Controllers/DevelopmentPrivateAiContextPreviewController.php` | U | BOOT_ONLY | yes |
| `app/Http/Controllers/DevelopmentProviderTriageController.php` | U | BOOT_ONLY | yes |
| `app/Http/Controllers/DevelopmentRoadmapContextController.php` | U | BOOT_ONLY | yes |
| `app/Http/Controllers/DevelopmentWorkspaceController.php` | M | BOOT_ONLY | yes |
| `app/Http/Controllers/DevelopmentWorkspaceTopController.php` | U | BOOT_ONLY | yes |
| `app/Http/Controllers/GitHubWebhookController.php` | U | BOOT_ONLY | yes |
| `app/Http/Controllers/McpAuthorizationProbeController.php` | U | A | — |
| `app/Http/Controllers/McpDelegatedRevocationController.php` | M | A | — |
| `app/Http/Controllers/McpOAuthAccountLinkController.php` | M | A | — |
| `app/Http/Controllers/McpOAuthPlanConsentController.php` | M | A | yes |
| `app/Http/Controllers/McpProtectedResourceMetadataController.php` | U | A | yes |
| `app/Http/Controllers/McpReadOnlyResourceController.php` | M | A | yes |
| `app/Http/Controllers/McpStudyResultDecisionController.php` | A | BOOT_ONLY | yes |
| `app/Http/Controllers/OverviewWorkspaceController.php` | M | BOOT_ONLY | yes |
| `app/Http/Controllers/PlanDashboardController.php` | U | BOOT_ONLY | yes |
| `app/Http/Controllers/ProductPreviewController.php` | U | BOOT_ONLY | yes |
| `app/Http/Controllers/ProviderActivityIntakeController.php` | M | BOOT_ONLY | yes |
| `app/Http/Controllers/StudyWorkspaceController.php` | M | SHARED | yes |
| `app/Http/Controllers/StudyWorkspaceTopController.php` | U | BOOT_ONLY | yes |
| `app/Http/Middleware/CloseUnopenedMcpStaging.php` | M | SHARED | — |
| `app/Http/Middleware/EnsureAdminAccess.php` | U | SHARED | — |
| `app/Http/Middleware/EnsureFeatureAccess.php` | U | SHARED | — |
| `app/Http/Middleware/EnsureFirstRunStarted.php` | U | SHARED | — |
| `app/Http/Middleware/EnsureMcpStagingSyntheticPilotActor.php` | A | SHARED | — |
| `app/Http/Middleware/EnsureReleaseLevel.php` | U | SHARED | — |
| `app/Http/Middleware/MeasurePagePerformance.php` | U | SHARED | — |
| `app/Http/Middleware/NormalizeAiJsonInput.php` | U | SHARED | — |
| `app/Http/Middleware/RedirectLegacyCanoviaHost.php` | U | SHARED | — |
| `app/Http/Middleware/RememberWorkspaceScreen.php` | U | SHARED | — |
| `app/Http/Middleware/TrackAiPlanFunnel.php` | U | SHARED | — |
| `app/Http/Middleware/TrackEarlyAccessVisit.php` | U | SHARED | — |
| `app/Intelligence/Adapters/TaskEvidenceAdapter.php` | U | SHARED | — |
| `app/Intelligence/Career/CareerEvidenceCollector.php` | U | SHARED | — |
| `app/Intelligence/Career/CareerPlanIntelligenceService.php` | U | SHARED | — |
| `app/Intelligence/Career/CareerProcessReadinessEvaluator.php` | U | SHARED | — |
| `app/Intelligence/Career/CareerStateBuilder.php` | U | SHARED | — |
| `app/Intelligence/Contracts/ActionGenerator.php` | U | SHARED | — |
| `app/Intelligence/Contracts/CandidateDecisionEngine.php` | U | SHARED | — |
| `app/Intelligence/Contracts/DecisionEngine.php` | U | SHARED | — |
| `app/Intelligence/Contracts/ReadinessEvaluator.php` | U | SHARED | — |
| `app/Intelligence/Contracts/StateBuilder.php` | U | SHARED | — |
| `app/Intelligence/Data/ActionProposal.php` | U | SHARED | — |
| `app/Intelligence/Data/CareerIntelligenceResult.php` | U | SHARED | — |
| `app/Intelligence/Data/Confidence.php` | U | SHARED | — |
| `app/Intelligence/Data/Decision.php` | U | SHARED | — |
| `app/Intelligence/Data/DecisionCandidate.php` | U | SHARED | — |
| `app/Intelligence/Data/DevelopmentIntelligenceResult.php` | U | SHARED | — |
| `app/Intelligence/Data/EvidenceObservation.php` | U | SHARED | — |
| `app/Intelligence/Data/ReadinessAssessment.php` | U | SHARED | — |
| `app/Intelligence/Data/StateSnapshot.php` | U | SHARED | — |
| `app/Intelligence/Data/StudyAdaptiveActionResult.php` | U | SHARED | — |
| `app/Intelligence/Data/StudyIntelligenceResult.php` | U | SHARED | — |
| `app/Intelligence/Development/DevelopmentEvidenceCollector.php` | M | SHARED | — |
| `app/Intelligence/Development/DevelopmentPlanIntelligenceService.php` | U | SHARED | — |
| `app/Intelligence/Development/DevelopmentReleaseReadinessEvaluator.php` | U | SHARED | — |
| `app/Intelligence/Development/DevelopmentStateBuilder.php` | U | SHARED | — |
| `app/Intelligence/Enums/IntelligenceDomain.php` | U | SHARED | — |
| `app/Intelligence/Enums/ReadinessLevel.php` | U | SHARED | — |
| `app/Intelligence/Presentation/IntelligencePresentationHistoryService.php` | M | SHARED | — |
| `app/Intelligence/Presentation/IntelligenceStateChangeFeedbackService.php` | M | SHARED | — |
| `app/Intelligence/Presentation/PlanIntelligencePresentation.php` | U | SHARED | — |
| `app/Intelligence/Presentation/StudyIntelligencePresentationAdapter.php` | U | SHARED | — |
| `app/Intelligence/Services/ActionProjectionStore.php` | M | SHARED | — |
| `app/Intelligence/Services/DecisionTraceStore.php` | U | SHARED | — |
| `app/Intelligence/Services/IntelligenceDomainSourceFreshnessGuard.php` | A | SHARED | — |
| `app/Intelligence/Services/IntelligenceEvidenceFreshnessGuard.php` | A | SHARED | — |
| `app/Intelligence/Services/StateSnapshotStore.php` | M | SHARED | — |
| `app/Intelligence/Study/ApSubjectACoverageDashboardService.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyActivityOutcomeObservationService.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyAdaptiveActionGenerator.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyAdaptiveActionService.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyAdaptiveDecisionEngine.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyExamReadinessEvaluator.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyIntelligenceStateBuilder.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyLearningTypeRouter.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyMethodOutcomeCalibrationService.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyMethodRecommendationService.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyPlanIntelligenceService.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyRemainingWorkEstimator.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyScopeEvidenceMatcher.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyStateBuilder.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyWeaknessInterventionOutcomeService.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyWorkspaceRecommendationService.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyWorkspaceStateResolver.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyWorkspaceSurfacePolicy.php` | U | SHARED | — |
| `app/Intelligence/Study/StudyWorkspaceSurfaceRegistry.php` | U | SHARED | — |
| `app/Intelligence/Support/CanonicalJson.php` | U | SHARED | — |
| `app/Intelligence/Support/IntelligenceFingerprint.php` | U | SHARED | — |
| `app/Models/BehaviorEvent.php` | U | SHARED | — |
| `app/Models/CareerApplication.php` | U | SHARED | — |
| `app/Models/CareerCapture.php` | U | SHARED | — |
| `app/Models/CareerCapturePayload.php` | U | SHARED | — |
| `app/Models/CareerSelectionEvent.php` | U | SHARED | — |
| `app/Models/DevelopmentActivityObservation.php` | M | SHARED | — |
| `app/Models/DevelopmentAiSharingPreference.php` | U | SHARED | yes |
| `app/Models/ExecutionActivity.php` | U | SHARED | — |
| `app/Models/Feedback.php` | U | SHARED | — |
| `app/Models/FutureMemo.php` | U | SHARED | — |
| `app/Models/GoalContext.php` | U | SHARED | — |
| `app/Models/GoalContextFact.php` | U | SHARED | — |
| `app/Models/GoalDiscoveryMessage.php` | U | SHARED | — |
| `app/Models/GuidedExecution.php` | U | SHARED | — |
| `app/Models/InboxItem.php` | U | SHARED | — |
| `app/Models/IntelligenceActionProjection.php` | U | SHARED | — |
| `app/Models/IntelligenceDecisionTrace.php` | U | SHARED | — |
| `app/Models/IntelligenceStateSnapshot.php` | U | SHARED | — |
| `app/Models/InterviewReview.php` | U | SHARED | — |
| `app/Models/InterviewReviewAnswer.php` | U | SHARED | — |
| `app/Models/LearningAnswerEvaluationAdjustment.php` | U | SHARED | — |
| `app/Models/LearningAnswerEvent.php` | U | SHARED | — |
| `app/Models/LearningExamResponseDraft.php` | U | SHARED | — |
| `app/Models/LearningRun.php` | U | SHARED | — |
| `app/Models/LearningRunCandidate.php` | U | SHARED | — |
| `app/Models/LearningRunItem.php` | U | SHARED | — |
| `app/Models/McpDelegatedGrant.php` | U | A | yes |
| `app/Models/McpLinkedSubject.php` | M | A | yes |
| `app/Models/McpStudyResultProposal.php` | A | SHARED | yes |
| `app/Models/NativeAiRun.php` | U | SHARED | — |
| `app/Models/Plan.php` | M | A | yes |
| `app/Models/PlanActivityLog.php` | U | SHARED | — |
| `app/Models/PlanAdjustment.php` | U | SHARED | — |
| `app/Models/PlanArtifact.php` | U | SHARED | — |
| `app/Models/PlanAvailabilityOverride.php` | U | SHARED | — |
| `app/Models/PlanAvailabilityRule.php` | U | SHARED | — |
| `app/Models/PlanExecutionPreference.php` | U | SHARED | — |
| `app/Models/PlanMember.php` | M | SHARED | — |
| `app/Models/PlanResource.php` | U | SHARED | — |
| `app/Models/PwaHandoff.php` | U | SHARED | — |
| `app/Models/Question.php` | U | SHARED | — |
| `app/Models/QuestionPack.php` | U | SHARED | — |
| `app/Models/ReleaseNote.php` | U | SHARED | yes |
| `app/Models/StudyPracticeAttempt.php` | U | SHARED | — |
| `app/Models/StudyPracticeSession.php` | U | SHARED | — |
| `app/Models/StudyRecallCandidate.php` | M | SHARED | — |
| `app/Models/StudyRecallItem.php` | U | SHARED | — |
| `app/Models/StudyRecallReview.php` | U | SHARED | — |
| `app/Models/StudyRecallSource.php` | U | SHARED | — |
| `app/Models/StudyScopeCapture.php` | U | SHARED | — |
| `app/Models/StudyScopeItem.php` | U | SHARED | — |
| `app/Models/StudyScoreObservation.php` | U | SHARED | — |
| `app/Models/Task.php` | U | A | yes |
| `app/Models/TaskEvidence.php` | U | SHARED | — |
| `app/Models/TaskMilestone.php` | U | SHARED | — |
| `app/Models/User.php` | M | A | yes |
| `app/Models/UserPersonalizationContext.php` | U | SHARED | yes |
| `app/Models/UserProductGrant.php` | U | SHARED | yes |
| `app/Models/WorkLog.php` | U | SHARED | — |
| `app/Models/WorkSession.php` | U | SHARED | — |
| `app/Providers/AppServiceProvider.php` | M | SHARED | yes |
| `app/Services/AccountDeletionService.php` | M | SHARED | — |
| `app/Services/AdaptiveExamProfileRegistry.php` | U | SHARED | — |
| `app/Services/AdaptiveLearningBankQueueService.php` | M | SHARED | — |
| `app/Services/AdaptiveLearningDistinctReviewService.php` | A | SHARED | — |
| `app/Services/AdaptiveLearningHistoryService.php` | A | SHARED | — |
| `app/Services/AdaptiveLearningReviewEntryService.php` | A | SHARED | — |
| `app/Services/AdaptiveStartingPointPolicyService.php` | U | SHARED | — |
| `app/Services/AdminAccessService.php` | U | SHARED | yes |
| `app/Services/AdminPreviewContext.php` | U | SHARED | — |
| `app/Services/AiJsonInputNormalizer.php` | U | SHARED | — |
| `app/Services/BehaviorEventLogger.php` | U | SHARED | yes |
| `app/Services/BehaviorIdentityService.php` | U | SHARED | yes |
| `app/Services/BookkeepingJournalEntryGrader.php` | U | SHARED | — |
| `app/Services/BookkeepingJournalPracticeService.php` | U | SHARED | — |
| `app/Services/BookkeepingPlacementDiagnosticService.php` | U | SHARED | — |
| `app/Services/CoreContextService.php` | U | SHARED | — |
| `app/Services/DevelopmentDerivedContextFreshnessGuard.php` | A | SHARED | yes |
| `app/Services/DevelopmentPersonalizationSignalService.php` | M | SHARED | yes |
| `app/Services/DevelopmentPrivateAiContextPreviewService.php` | U | SHARED | — |
| `app/Services/EarlyAccessService.php` | U | SHARED | yes |
| `app/Services/EarlyAccessTelemetryService.php` | U | SHARED | — |
| `app/Services/EconomyCatalogService.php` | U | SHARED | yes |
| `app/Services/Entitlements/FreeEntitlementResolver.php` | U | SHARED | — |
| `app/Services/Entitlements/GiftProductGrantEntitlementResolver.php` | U | SHARED | — |
| `app/Services/Entitlements/ProductGrantEntitlementResolver.php` | U | SHARED | — |
| `app/Services/Entitlements/SponsorProductGrantEntitlementResolver.php` | U | SHARED | — |
| `app/Services/EvidenceProgressService.php` | U | SHARED | — |
| `app/Services/ExecutionCapabilityResolver.php` | U | SHARED | — |
| `app/Services/ExecutionProviderRegistry.php` | U | SHARED | — |
| `app/Services/ExecutionResolver.php` | U | SHARED | — |
| `app/Services/ExecutionSetupService.php` | U | SHARED | — |
| `app/Services/FeatureAccessService.php` | M | SHARED | — |
| `app/Services/FirstRunService.php` | U | SHARED | — |
| `app/Services/FutureMemoService.php` | U | SHARED | — |
| `app/Services/GitHubSourceEpochService.php` | A | SHARED | yes |
| `app/Services/GitHubTaskEvidenceCommitGuard.php` | A | SHARED | — |
| `app/Services/GitHubTaskEvidenceReuseGuard.php` | A | SHARED | — |
| `app/Services/GitHubWorkflowService.php` | U | SHARED | — |
| `app/Services/GuestPlanClaimService.php` | M | SHARED | — |
| `app/Services/HomeSurfacePreference.php` | U | BOOT_ONLY | yes |
| `app/Services/LearningContextSnapshotService.php` | A | EAGER | — |
| `app/Services/LearningContextSourceEpochService.php` | A | SHARED | — |
| `app/Services/LearningDelayedRecheckService.php` | A | SHARED | — |
| `app/Services/LearningReviewComparisonService.php` | A | SHARED | — |
| `app/Services/LearningReviewSourceSpacingService.php` | A | SHARED | — |
| `app/Services/McpAccessTokenIntrospector.php` | U | A | yes |
| `app/Services/McpAuth0JwtTokenVerifier.php` | A | A | yes |
| `app/Services/McpDelegatedIdentityFingerprintService.php` | U | A | yes |
| `app/Services/McpDelegatedPlanAccessPolicy.php` | M | A | yes |
| `app/Services/McpDelegatedRevocationService.php` | M | A | yes |
| `app/Services/McpExplicitPlanConsentService.php` | M | A | yes |
| `app/Services/McpExternalTokenVerifier.php` | A | A | yes |
| `app/Services/McpJwtClaimConstraints.php` | A | A | yes |
| `app/Services/McpOAuthAccountLinkCommitter.php` | M | A | — |
| `app/Services/McpOAuthAccountLinkConfiguration.php` | M | A | yes |
| `app/Services/McpOAuthAccountLinkProvider.php` | M | A | — |
| `app/Services/McpProposeStudyResultTool.php` | A | EAGER | — |
| `app/Services/McpProtectedResourceConfiguration.php` | M | A | yes |
| `app/Services/McpReadOnlyContextTool.php` | M | EAGER | — |
| `app/Services/McpReadOnlyLearningContextSnapshotTool.php` | A | EAGER | — |
| `app/Services/McpReadOnlyStudyContextTool.php` | A | A | yes |
| `app/Services/McpReadOnlyStudyPracticeSummaryTool.php` | A | EAGER | — |
| `app/Services/McpStagingLoginPilotPolicy.php` | A | SHARED | — |
| `app/Services/McpStagingOAuthFailureDiagnostic.php` | A | SHARED | — |
| `app/Services/McpStagingSyntheticActorBootstrap.php` | U | SHARED | — |
| `app/Services/McpStagingSyntheticPlanFixtureBootstrap.php` | U | SHARED | — |
| `app/Services/McpStagingSyntheticReadPilotPolicy.php` | A | SHARED | — |
| `app/Services/McpStudyPilotAccessPolicy.php` | A | A | yes |
| `app/Services/McpStudyResultProposalService.php` | A | EAGER | — |
| `app/Services/McpVerifiedTokenPrincipal.php` | U | A | yes |
| `app/Services/PersonalizationConfidenceCalibrationService.php` | U | SHARED | yes |
| `app/Services/PersonalizationContextService.php` | M | SHARED | yes |
| `app/Services/PersonalizationLivingProfileService.php` | M | SHARED | yes |
| `app/Services/PlanAvailabilityService.php` | U | SHARED | — |
| `app/Services/PlanCategoryProfileService.php` | U | A | yes |
| `app/Services/PlanCompletionFingerprintService.php` | U | SHARED | — |
| `app/Services/PlanLifecyclePersonalizationAdapter.php` | U | SHARED | — |
| `app/Services/PlanOwnershipService.php` | U | SHARED | — |
| `app/Services/PlanPriorityService.php` | U | SHARED | — |
| `app/Services/PlanProgressService.php` | U | SHARED | — |
| `app/Services/PrivateFileLifecycleService.php` | A | SHARED | — |
| `app/Services/PrivateFilePathFenceService.php` | A | SHARED | — |
| `app/Services/PrivateFileStorageBoundaryService.php` | A | SHARED | — |
| `app/Services/ProductGrantService.php` | U | SHARED | yes |
| `app/Services/PwaHandoffService.php` | U | SHARED | — |
| `app/Services/QuestionPackPublicationReadinessService.php` | U | SHARED | — |
| `app/Services/ReleaseLevelService.php` | M | SHARED | yes |
| `app/Services/RequestBehaviorHistory.php` | U | SHARED | — |
| `app/Services/ReturnAfterAbsencePersonalizationAdapter.php` | U | SHARED | — |
| `app/Services/StudyActivityPolicyService.php` | U | SHARED | — |
| `app/Services/StudyDerivedContextFreshnessGuard.php` | A | SHARED | yes |
| `app/Services/StudyExamConvergencePolicyService.php` | U | SHARED | — |
| `app/Services/StudyExamDateService.php` | U | SHARED | — |
| `app/Services/StudyLearningBankTopicCoverageService.php` | A | SHARED | — |
| `app/Services/StudyLearningNextActionQueueService.php` | A | SHARED | — |
| `app/Services/StudyLearningPlanPriorityService.php` | A | SHARED | — |
| `app/Services/StudyOfficialExamReferenceService.php` | U | SHARED | — |
| `app/Services/StudyOfficialSyllabusBridgeService.php` | A | SHARED | — |
| `app/Services/StudyPracticeExamProfileService.php` | U | SHARED | — |
| `app/Services/StudyPracticeRoutingPolicyService.php` | U | SHARED | — |
| `app/Services/StudyPracticeStrategyService.php` | U | SHARED | — |
| `app/Services/StudyTaskProgressionService.php` | U | SHARED | — |
| `app/Services/StudyTopicTaxonomyService.php` | U | SHARED | — |
| `app/Services/StudyWeaknessPrioritizationService.php` | U | SHARED | — |
| `app/Services/StudyWorkspaceViewService.php` | U | SHARED | — |
| `app/Services/TaskEvidenceExecutionActivityProjector.php` | U | SHARED | — |
| `app/Services/TaskEvidenceService.php` | U | SHARED | — |
| `app/Services/WorkspaceModeOnboardingService.php` | U | SHARED | — |
| `app/Services/WorkspaceModePreference.php` | U | SHARED | yes |
| `app/Services/WorkspaceModeRegistry.php` | U | SHARED | yes |
| `app/Services/WorkspaceModeResolver.php` | U | SHARED | yes |
| `app/Services/WorkspaceNavigationService.php` | U | SHARED | yes |
| `app/Support/Release20IdentityMigrationGuard.php` | A | BOOT_ONLY | yes |
| `app/Support/ReleaseNotes.php` | U | SHARED | yes |
| `app/Support/RequestPerformance.php` | U | SHARED | — |

## 4. literal Bladeの参照閉包

ownerの設定とStudy Workspace同意UIから辿った全literal include/extends/component。conditional branchも含む。動的component/JS内の全振舞いを実証した一覧ではない。

| ファイル | Git差分 |
| --- | --- |
| `resources/views/auth/account.blade.php` | M |
| `resources/views/auth/partials/mcp-account-link.blade.php` | M |
| `resources/views/auth/partials/mcp-connection-guide.blade.php` | A |
| `resources/views/auth/partials/mcp-delegated-access-revocation.blade.php` | M |
| `resources/views/auth/partials/mcp-plan-consent-confirmation.blade.php` | M |
| `resources/views/auth/partials/mcp-study-result-proposals.blade.php` | A |
| `resources/views/intelligence/partials/state-change.blade.php` | U |
| `resources/views/layouts/app.blade.php` | M |
| `resources/views/layouts/partials/companion-palette.blade.php` | U |
| `resources/views/layouts/partials/guide.blade.php` | U |
| `resources/views/layouts/partials/mobile-nav.blade.php` | U |
| `resources/views/layouts/partials/onboarding.blade.php` | U |
| `resources/views/layouts/partials/primary-navigation-desktop.blade.php` | U |
| `resources/views/workspace/partials/execution-setup.blade.php` | U |
| `resources/views/workspace/partials/mode-onboarding.blade.php` | U |
| `resources/views/workspace/study/index.blade.php` | M |
| `resources/views/workspace/study/partials/learning-bank-coverage.blade.php` | A |
| `resources/views/workspace/study/partials/learning-plan-priority.blade.php` | A |
| `resources/views/workspace/study/partials/learning-review-queue.blade.php` | A |
| `resources/views/workspace/study/partials/mcp-plan-consent.blade.php` | A |
| `resources/views/workspace/study/partials/official-syllabus-bridge.blade.php` | A |
| `resources/views/workspace/study/views/analysis.blade.php` | M |
| `resources/views/workspace/study/views/history.blade.php` | M |
| `resources/views/workspace/study/views/preparation.blade.php` | M |
| `resources/views/workspace/study/views/work.blade.php` | M |

## 5. Schema compatibility matrix — 追加20件すべて

旧94のMCP基盤は `2026_10_09_000200_create_mcp_identity_links_and_delegated_grants.php` と `2026_10_09_000300_create_mcp_delegated_access_events_table.php`。Plan/Task/users/StudyPracticeAttempt等も旧94に存在する。source上の存在と本番物理schema一致を分ける。

| ID | migration（すべてdatabase/migrations/） | Phase A到達性 / 制約 |
| --- | --- | --- |
| M01 | `2026_10_12_010000_add_source_revision_to_development_activity_observations.php` | 直接Read依存なし。Development source revision。標準起動では必要 |
| M02 | `2026_10_13_021000_add_source_epoch_to_development_activity_observations.php` | 直接Read依存なし。Development source epoch。標準起動では必要 |
| M03 | `2026_10_13_110000_add_authorization_epochs_to_collaborative_plans.php` | 直接Readはauthorization_epochを条件にしない。協調認可/旧writer全体へ影響 |
| M04 | `2026_10_13_120000_enforce_authorization_epochs_in_database.php` | M03に依存するDB epoch trigger。AP flag OFFでもmigrationの影響あり |
| M05 | `2026_10_13_130000_immutable_plan_member_binding.php` | M04 trigger前提のimmutable member binding。旧writer/権限は実ゲート |
| M06 | `2026_10_13_200000_add_recall_candidate_review_provenance.php` | 直接Read依存なし。recall review provenance |
| M07 | `2026_10_14_000100_allow_nonexpiring_mcp_grants.php` | 現行until_revoked同意には必要。有限のみの限定実験では不要、現行UIのまま省けない |
| M08 | `2026_10_14_000200_preserve_historical_mcp_identity_links.php` | 必須。active fingerprint column/UNIQUEと履歴保持、旧UNIQUE名＋定義に依存。aliasをDROPしない |
| M09 | `2026_10_14_100000_create_private_file_cleanup_entries.php` | 直接Read依存なし。private cleanup |
| M10 | `2026_10_14_120000_create_private_file_path_fences.php` | 直接Read依存なし。private path fence |
| M11 | `2026_10_14_130000_create_private_file_upload_intents.php` | 直接Read依存なし。private upload intent |
| M12 | `2026_10_14_140000_create_private_file_generation_evidence.php` | 直接Read依存なし。generation evidence |
| M13 | `2026_10_14_150000_create_private_file_write_attempts.php` | 直接Read依存なし。private write attempts |
| M14 | `2026_10_14_154500_create_development_operational_profiles_table.php` | 直接Read依存なし。Development operational profile |
| M15 | `2026_10_14_164000_create_development_operational_targets_table.php` | 直接Read依存なし。Development operational target |
| M16 | `2026_10_14_185000_add_manual_service_check_to_development_operational_targets_table.php` | 直接Read依存なし。manual service check |
| M17 | `2026_10_14_201000_add_storage_revision_fence_to_development_operational_records.php` | 直接Read依存なし。operational storage revision |
| M18 | `2026_10_15_070000_create_mcp_study_result_proposals_table.php` | 必須。AccountのSELECTと同意のpending失効UPDATEが無条件。B/Dでも使用。write flagとは独立 |
| M19 | `2026_10_15_110000_create_learning_context_watch_persistence.php` | 直接Read/B/C固有依存なし。Watch persistenceは別機能・OFF維持 |
| M20 | `2026_10_15_120000_create_learning_context_source_epochs_table.php` | 直接Read/B/C固有依存なし。source epoch/triggerは別機能。D採用時の条件付きrecord依存は別審査 |

**標準e858は全114 sourceを持つため、Aに直接不要でも全20の事前適用・ledger整合が必要。** 97件実験は別source候補ではなく、制約の分離実験。M08の旧名実測は過去の実測証拠として保持するが、e858本番移行の新設名衝突/完全TRIGGER/principal/binlog/restore等をPASSにしない。

## 6. 再現コマンドと結果の範囲

Linuxの既存 `/workspace/Canovia-ap-read-feasibility` checkout、Git full history、既存PHP wrapper/vendorを使用。以下は**ローカルsynthetic専用**で、オーナーの実DB操作手順ではない。`.env`/本番接続・credentialを作らない。

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' DATABASE_URL='' \
CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
/workspace/.onboarding/bin/php vendor/bin/phpunit --do-not-cache-result \
  tests/Feature/McpReadOnlyResourceTest.php \
  tests/Feature/McpAuth0JwtProviderRoutingTest.php \
  tests/Feature/McpExplicitPlanConsentTest.php \
  tests/Feature/McpOAuthAccountLinkTest.php \
  tests/Feature/McpDelegatedRevocationTest.php \
  tests/Feature/McpProtectedResourceGateTest.php \
  tests/Feature/ReleaseSchemaLedgerFenceTest.php \
  tests/Feature/RenderStartupMigrationRetryV569Test.php

APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' DATABASE_URL='' \
CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
/workspace/.onboarding/bin/php vendor/bin/phpunit --do-not-cache-result \
  tests/Feature/InitialReleaseAudienceTest.php
```

結果は87/1240 assertions＋3/41、fail/error/skip0。PHP8.4.26、PHPUnit12.5.30。CIで同じsuiteを再実行しても別coverageとして数えない。

read-only source確認：

```bash
git diff --name-status 15abdc9de6f24b5250fb255c579d1aa55facaf1a e858b9c855970fbe73f37a2d60c9db6525b132d6
git diff --name-status 15abdc9de6f24b5250fb255c579d1aa55facaf1a e858b9c855970fbe73f37a2d60c9db6525b132d6 -- database/migrations
python3 -B - <<'SOURCE_SEAL'
import pathlib,sys
sys.path.insert(0,'scripts/ci')
from integrated_release20_audit import source
facts=source(pathlib.Path.cwd(),'e858b9c855970fbe73f37a2d60c9db6525b132d6')
assert len(facts['baseline'])==94 and len(facts['candidate'])==114
assert facts['migration_seal']=='a3494c1d1cf60546a86312f249f7d44b2ac52e44669752a65e1c70011ec9f91b'
print('SOURCE_PIN_AND_SEAL_VERIFIED_NOT_RELEASE_GO')
SOURCE_SEAL
```

### 限定schema probeの再現

以下PHP fenceを私有 `/workspace/.onboarding/ap-read-feasibility/schema-probe.php` に抽出する。旧94はGitから**bytesを変更せず** private directoryへ復元する。初期harnessのrequired fixture項目/Blade error bagとSQLiteのunknown quoted identifier挙動を修正してから、最終exact-byte fixtureで全3engineを再実行した結果だけを採用。アプリの修正や拒否条件の緩和ではない。

```bash
python3 -B - <<'BASELINE_SOURCE'
from pathlib import Path
import subprocess
root=Path('/workspace/.onboarding/ap-read-feasibility/baseline94-migrations')
root.mkdir(mode=0o700,parents=True,exist_ok=True)
base='15abdc9de6f24b5250fb255c579d1aa55facaf1a'
names=subprocess.check_output(['git','ls-tree','-r','--name-only',base,'database/migrations'],text=True).splitlines()
assert len(names)==94
for name in names:
    target=root/Path(name).name
    target.write_bytes(subprocess.check_output(['git','show',base+':'+name]))
    target.chmod(0o600)
BASELINE_SOURCE
APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' DATABASE_URL='' \
CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
/workspace/.onboarding/bin/php /workspace/.onboarding/ap-read-feasibility/schema-probe.php
```

MySQL実行は同じprobeを、専用の使い捨て`canovia-ap-feasibility-80/84`だけへ接続した。port13480/13484・host127.0.0.1・DB/user固定、ID/image/`canovia.audit=ap-first-feasibility-20261020` labelを先に検査、既存同名containerなら停止。既存ローカルimage `mysql:8.0`/`mysql:8.4`をpullせず使用し、合成passwordのみ・メモリ/CPU制限、`--rm`で終了後削除。実targetへ値を差し替える手順ではない。既存DBを試すために再利用しない。

最終21 checksはSQLite3.40.1、MySQL8.0.46、MySQL8.4.11で同じ集合。engineごとの反復を63種類の独立coverageとしない。namespace/署名の認証はこのprobeでは直接合成principal、外部署名検証は上記PHP suiteの別証拠。

| 固定check | 意味 |
| --- | --- |
| `BASELINE_LEDGER_94` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `BASELINE94_READ_BLOCKS_MISSING_M08` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `M08_ONLY_EXISTING_FINITE_OVERVIEW` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `OVERVIEW_GRANT_DENIES_TASKS` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `M08_ONLY_EXISTING_FINITE_TASKS` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `FINITE_CONSENT_BLOCKS_WITHOUT_M18` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `FAILED_CONSENT_ROLLS_BACK_GRANT` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `ACCOUNT_QUERY_BLOCKS_WITHOUT_M18` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `FINITE_CONSENT_WITH_M08_M18` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `ACCOUNT_DATA_WITH_M08_M18` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `ACCOUNT_RENDER_WITH_M08_M18` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `INDEFINITE_CONSENT_BLOCKS_WITHOUT_M07` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `INDEFINITE_CONSENT_WITH_M07_M08_M18` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `WRONG_SUBJECT_DENIED` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `WRONG_PILOT_OWNER_DENIED` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `REVOKE_AND_OLD_PRINCIPAL_DENIED` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `DOMAIN_ROWS_UNCHANGED` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `NO_ATTEMPTS_OR_PROPOSALS_CREATED` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `PARTIAL_LEDGER_97` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `UNCHANGED_CANDIDATE_STARTUP_STILL_BLOCKS` | 合成fixtureのassertion、失敗なら固定BLOCK |
| `NO_HTTP_TRANSPORT_USED` | 合成fixtureのassertion、失敗なら固定BLOCK |

probe SHA256 `70ae9a074246ad6fce4e666d0dd9ce23e001e52b868b4ccae92b235d817e8e28`。fence内のbytes（末尾LF含む）を維持。エラーの詳細はローカルprivate logだけに留め、共有は固定codeのみ。

```php
<?php
// Disposable synthetic diagnostic only; not an operator/production client.
declare(strict_types=1);
$root = '/workspace/Canovia-ap-read-feasibility';
$dir = '/workspace/.onboarding/ap-read-feasibility';
$driver = getenv('DB_CONNECTION');
if (getenv('DB_URL') || getenv('DATABASE_URL') || ! (
    ($driver === 'sqlite' && getenv('DB_DATABASE') === ':memory:') ||
    ($driver === 'mysql' && getenv('DB_HOST') === '127.0.0.1'
        && in_array(getenv('DB_PORT'), ['13480','13484'], true)
        && getenv('DB_DATABASE') === 'canovia_p0_ci' && getenv('DB_USERNAME') === 'canovia_ci')
)) { echo '{"result":"BLOCK","code":"DISPOSABLE_TARGET_REQUIRED"}'; exit(2); }
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use App\Models\{Plan,Task,User};
use App\Services\{McpReadOnlyStudyContextTool,McpVerifiedTokenPrincipal,McpDelegatedIdentityFingerprintService,McpExplicitPlanConsentService,McpDelegatedRevocationService};
$cases = []; $current = 'BOOT';
function verify(string $name, bool $ok): void {
    global $cases,$current; $current=$name;
    if (!$ok) throw new RuntimeException('SYNTHETIC_CHECK_FAILED');
    $cases[]=$name;
}
function dbBlocked(callable $operation): bool {
    try { $operation(); return false; } catch (Illuminate\Database\QueryException $error) { return true; }
}
function readBlocked(callable $operation): bool {
    try { return $operation() === null; } catch (Illuminate\Database\QueryException $error) { return true; }
}
function applyMigration(string $name): void {
    global $app,$root;
    $app->make('migrator')->run([$root.'/database/migrations/'.$name.'.php'], ['force'=>true]);
}
try {
    config(['app.env'=>'testing','app.debug'=>false,'app.url'=>'https://canovia.synthetic.invalid',
        'cache.default'=>'array','session.driver'=>'array','queue.default'=>'sync',
        'native_ai.driver'=>'disabled','performance.enabled'=>false,
        'release_levels.public_level'=>0,
        'canovia_mcp.discovery_enabled'=>true,'canovia_mcp.tools_enabled'=>true,
        'canovia_mcp.study_read_enabled'=>true,'canovia_mcp.study_summary_read_enabled'=>false,
        'canovia_mcp.learning_context_snapshot_enabled'=>false,'canovia_mcp.study_result_proposals_enabled'=>false,
        'canovia_mcp.resource_url'=>'https://canovia.synthetic.invalid/api/mcp',
        'canovia_mcp.oauth_issuer'=>'https://issuer.synthetic.invalid/',
        'canovia_mcp.allowed_client_id'=>'synthetic-chatgpt-client',
        'canovia_mcp.token_verification_driver'=>'auth0_jwt',
        'canovia_mcp.auth0_jwt_verification_enabled'=>true,'canovia_mcp.token_introspection_enabled'=>false,
        'canovia_mcp.delegated_policy_enabled'=>true,'canovia_mcp.plan_consent_enabled'=>true,
        'canovia_mcp.account_link_enabled'=>true,'canovia_mcp.account_link_github_only_enabled'=>true,
        'canovia_mcp.identity_fingerprint_key'=>str_repeat('s',48),
        'canovia_mcp.account_link_client_id'=>'synthetic-confidential-link-client',
        'canovia_mcp.account_link_client_secret'=>str_repeat('t',32),
        'canovia_mcp.account_link_metadata_url'=>'https://issuer.synthetic.invalid/.well-known/oauth-authorization-server',
        'canovia_mcp.account_link_authorization_endpoint'=>'https://issuer.synthetic.invalid/authorize',
        'canovia_mcp.account_link_token_endpoint'=>'https://issuer.synthetic.invalid/oauth/token',
        'canovia_mcp.account_link_redirect_uri'=>'https://canovia.synthetic.invalid/account/mcp/link/callback',
    ]);
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response([], 503));
    $current='BASELINE_BUILD';
    $migrator=$app->make('migrator');$migrator->getRepository()->createRepository();
    $migrator->run([$dir.'/baseline94-migrations'],['force'=>true]);
    verify('BASELINE_LEDGER_94',DB::table('migrations')->count()===94);
    $owner=User::factory()->create();
    $plan=Plan::query()->create(['user_id'=>$owner->id,'owner_token'=>str_repeat('s',64),'public_slug'=>'synthetic-ap-probe','title'=>'Synthetic AP Plan','category'=>'資格学習',
        'priority'=>1,'priority_mode'=>'manual','start_date'=>today(),
        'is_public'=>false,'is_collaborative'=>false,'deadline'=>'2026-11-10']);
    $task=Task::query()->create(['plan_id'=>$plan->id,'title'=>'Synthetic Task','status'=>'todo',
        'progress_percent'=>30,'priority'=>1,'sort_order'=>1]);
    config(['canovia_mcp.ap_pilot_owner_id'=>(string)$owner->id]);
    $fingerprints=app(McpDelegatedIdentityFingerprintService::class);
    $fingerprint=$fingerprints->subject('https://issuer.synthetic.invalid/','github|12345');
    $link=DB::table('mcp_linked_subjects')->insertGetId(['user_id'=>$owner->id,'provider_key'=>'chatgpt',
        'identity_fingerprint'=>$fingerprint,'status'=>'linked','linked_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    $grant=DB::table('mcp_delegated_grants')->insertGetId(['subject_link_id'=>$link,'user_id'=>$owner->id,
        'plan_id'=>$plan->id,'client_resource_fingerprint'=>$fingerprints->clientResource('synthetic-chatgpt-client','https://canovia.synthetic.invalid/api/mcp'),
        'scope'=>'overview','status'=>'active','consented_at'=>now(),'expires_at'=>now()->addDay(),
        'created_at'=>now(),'updated_at'=>now()]);
    $principal=new McpVerifiedTokenPrincipal('https://issuer.synthetic.invalid/','github|12345',
        'synthetic-chatgpt-client','https://canovia.synthetic.invalid/api/mcp',['canovia.development.read'],time()+900);
    $tool=app(McpReadOnlyStudyContextTool::class);
    $domainDigest=fn()=>hash('sha256',json_encode([DB::table('plans')->orderBy('id')->get()->all(),DB::table('tasks')->orderBy('id')->get()->all()]));
    $before=$domainDigest();
    verify('BASELINE94_READ_BLOCKS_MISSING_M08',!Schema::hasColumn('mcp_linked_subjects','active_identity_fingerprint')
        && readBlocked(fn()=>$tool->read($principal,$plan->id,'overview',5)));
    applyMigration('2026_10_14_000200_preserve_historical_mcp_identity_links');
    $overview=$tool->read($principal,$plan->id,'overview',5);
    verify('M08_ONLY_EXISTING_FINITE_OVERVIEW',($overview['plan']['title']??null)==='Synthetic AP Plan' && $overview['tasks']===[]);
    verify('OVERVIEW_GRANT_DENIES_TASKS',$tool->read($principal,$plan->id,'tasks',5)===null);
    DB::table('mcp_delegated_grants')->where('id',$grant)->update(['scope'=>'tasks']);
    $tasks=$tool->read($principal,$plan->id,'tasks',1);
    verify('M08_ONLY_EXISTING_FINITE_TASKS',count($tasks['tasks']??[])===1 && $tasks['tasks'][0]['recorded_progress_percent']===30);
    $consent=app(McpExplicitPlanConsentService::class);
    $grantBefore=json_encode(DB::table('mcp_delegated_grants')->where('id',$grant)->first());
    verify('FINITE_CONSENT_BLOCKS_WITHOUT_M18',dbBlocked(fn()=>$consent->grant($owner,$plan->id,$fingerprint,'overview',1)));
    verify('FAILED_CONSENT_ROLLS_BACK_GRANT',$grantBefore===json_encode(DB::table('mcp_delegated_grants')->where('id',$grant)->first()));
    $request=Illuminate\Http\Request::create('/account');$request->setUserResolver(fn()=>$owner);
    $request->setLaravelSession(app('session')->driver());$app->instance('request',$request);
    Illuminate\Support\Facades\Auth::guard('web')->setUser($owner);
    $account=fn()=>app(App\Http\Controllers\AuthController::class)->account($request,
        app(App\Services\ReleaseLevelService::class),app(App\Services\PersonalizationLivingProfileService::class));
    verify('ACCOUNT_QUERY_BLOCKS_WITHOUT_M18',dbBlocked($account));
    applyMigration('2026_10_15_070000_create_mcp_study_result_proposals_table');
    verify('FINITE_CONSENT_WITH_M08_M18',$consent->grant($owner,$plan->id,$fingerprint,'tasks',1));
    $current='ACCOUNT_VIEW_WITH_M08_M18';$view=$account();
    verify('ACCOUNT_DATA_WITH_M08_M18',$view instanceof Illuminate\Contracts\View\View);
    Illuminate\Support\Facades\View::share('errors',new Illuminate\Support\ViewErrorBag());
    $current='ACCOUNT_RENDER_WITH_M08_M18';$html=$view->render();
    verify('ACCOUNT_RENDER_WITH_M08_M18',str_contains($html,'mcp'));
    verify('INDEFINITE_CONSENT_BLOCKS_WITHOUT_M07',dbBlocked(fn()=>$consent->grant($owner,$plan->id,$fingerprint,'tasks','until_revoked')));
    applyMigration('2026_10_14_000100_allow_nonexpiring_mcp_grants');
    verify('INDEFINITE_CONSENT_WITH_M07_M08_M18',$consent->grant($owner,$plan->id,$fingerprint,'tasks','until_revoked')
        && DB::table('mcp_delegated_grants')->where('id',$grant)->value('expires_at')===null);
    $other=clone $principal;
    $wrong=new McpVerifiedTokenPrincipal($principal->issuer,'github|98765',$principal->clientId,$principal->audience,$principal->scopes,$principal->expiresAt);
    verify('WRONG_SUBJECT_DENIED',$tool->read($wrong,$plan->id,'overview',5)===null);
    config(['canovia_mcp.ap_pilot_owner_id'=>(string)($owner->id+100)]);
    verify('WRONG_PILOT_OWNER_DENIED',$tool->read($principal,$plan->id,'overview',5)===null);
    config(['canovia_mcp.ap_pilot_owner_id'=>(string)$owner->id]);
    verify('REVOKE_AND_OLD_PRINCIPAL_DENIED',app(McpDelegatedRevocationService::class)->revokeGrant($owner,$grant)
        && $tool->read($principal,$plan->id,'overview',5)===null);
    verify('DOMAIN_ROWS_UNCHANGED',$before===$domainDigest());
    verify('NO_ATTEMPTS_OR_PROPOSALS_CREATED',DB::table('study_practice_attempts')->count()===0 && DB::table('mcp_study_result_proposals')->count()===0);
    verify('PARTIAL_LEDGER_97',DB::table('migrations')->count()===97);
    verify('UNCHANGED_CANDIDATE_STARTUP_STILL_BLOCKS',Artisan::call('canovia:release-schema-ready')!==0);
    Http::assertNothingSent();
    verify('NO_HTTP_TRANSPORT_USED',true);
    $files=array_values(array_filter(array_map(fn($f)=>str_starts_with($f,$root.'/')?substr($f,strlen($root)+1):'',get_included_files()),fn($f)=>str_starts_with($f,'app/')));
    sort($files);
    echo json_encode(['result'=>'SYNTHETIC_SCHEMA_DEPENDENCY_PROBE_VERIFIED','driver'=>$driver,
        'engine_version'=>$driver==='mysql'?DB::selectOne('SELECT VERSION() AS v')->v:SQLite3::version()['versionString'],
        'case_count'=>count($cases),'cases'=>$cases,'included_app_files'=>$files,
        'authentication'=>'DIRECT_SYNTHETIC_PRINCIPAL_NOT_OAUTH_E2E','real_aiven_connected'=>false,
        'production_modified'=>false,'release_authorized'=>false],JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    file_put_contents($dir.'/probe-debug-'.$driver.'.log',(string)$error);
    echo json_encode(['result'=>'BLOCK','code'=>'SYNTHETIC_PROBE_FAILED','case'=>$current,'passed'=>count($cases),
        'real_aiven_connected'=>false,'production_modified'=>false,'release_authorized'=>false])."\n";exit(2);
}
```

既存All20移行・alias/partial-DDL・restore CIは固定#795/#796の依存証拠として再利用しただけで、今回の21 checksへ混ぜない。実Aiven・実ユーザー行・実backup・actual writer・実Auth0・Renderを観測したと主張しない。
