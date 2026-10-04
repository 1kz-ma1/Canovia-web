<?php

namespace App\Enums;

enum FeatureKey: string
{
    case AiPractice = 'ai_practice';
    case AdvancedAnalytics = 'advanced_analytics';
    case QuestionPack = 'question_pack';
    case ProjectArtifact = 'project_artifact';
    case AutomaticAiExecution = 'automatic_ai_execution';
    case CanoviaCompanion = 'canovia_companion';
    case ConversationalOnboarding = 'conversational_onboarding';
    case StudyScopeCapture = 'study_scope_capture';
    case StudyLongTermWeaknessProfile = 'study_long_term_weakness_profile';
    case CareerNativeCaptureAnalysis = 'career_native_capture_analysis';
    case DeveloperGithubEvidence = 'developer_github_evidence';
    case DeveloperGithubWrite = 'developer_github_write';
}
