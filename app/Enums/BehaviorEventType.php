<?php

namespace App\Enums;

enum BehaviorEventType: string
{
    case DashboardViewed = 'dashboard_viewed';
    case DashboardIdle = 'dashboard_idle';
    case PlanTabViewed = 'plan_tab_viewed';
    case TaskViewed = 'task_viewed';
    case TaskStarted = 'task_started';
    case WorkStarted = 'work_started';
    case WorkCompleted = 'work_completed';
    case WorkInterrupted = 'work_interrupted';
    case RecommendationShown = 'recommendation_shown';
    case RecommendationAccepted = 'recommendation_accepted';
    case RecommendationRejected = 'recommendation_rejected';
    case AlternativeRequested = 'alternative_requested';
    case NavigationStarted = 'navigation_started';
    case NavigationCompleted = 'navigation_completed';

    // V42.4 Living Map validation telemetry.
    case MapViewed = 'map_viewed';
    case MapNodeFocused = 'map_node_focused';
    case MapBackUsed = 'map_back_used';
    case MapClassicActionOpened = 'map_classic_action_opened';
    case MapCompanionOpened = 'map_companion_opened';
    case MapClassicHomeOpened = 'map_classic_home_opened';
    case MapReprojected = 'map_reprojected';
    case MapExecutionStarted = 'map_execution_started';

    // AI-assisted plan funnel. These events intentionally contain no prompt or
    // pasted JSON body; they exist to diagnose where users stop or fail.
    case PlanGenerationOpened = 'plan_generation_opened';
    case PlanGenerationPromptCopyClicked = 'plan_generation_prompt_copy_clicked';
    case PlanGenerationImportAttempted = 'plan_generation_import_attempted';
    case PlanGenerationImportFailed = 'plan_generation_import_failed';
    case PlanGenerationImportSucceeded = 'plan_generation_import_succeeded';
    case PlanUpdateOpened = 'plan_update_opened';
    case PlanUpdatePromptGenerated = 'plan_update_prompt_generated';
    case PlanUpdatePromptFailed = 'plan_update_prompt_failed';
    case PlanUpdatePromptCopyClicked = 'plan_update_prompt_copy_clicked';
    case PlanUpdatePreviewAttempted = 'plan_update_preview_attempted';
    case PlanUpdatePreviewFailed = 'plan_update_preview_failed';
    case PlanUpdatePreviewSucceeded = 'plan_update_preview_succeeded';
    case PlanUpdateApplyAttempted = 'plan_update_apply_attempted';
    case PlanUpdateApplyFailed = 'plan_update_apply_failed';
    case PlanUpdateApplied = 'plan_update_applied';

    public static function clientRecordable(): array
    {
        return [
            self::DashboardIdle->value,
            self::PlanTabViewed->value,
            self::TaskViewed->value,
            self::MapViewed->value,
            self::MapNodeFocused->value,
            self::MapBackUsed->value,
            self::MapClassicActionOpened->value,
            self::MapCompanionOpened->value,
            self::MapClassicHomeOpened->value,
            self::MapReprojected->value,
            self::PlanGenerationPromptCopyClicked->value,
            self::PlanUpdatePromptCopyClicked->value,
        ];
    }
}
