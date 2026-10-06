<?php

namespace App\Models;

use App\Enums\EvidenceSource;
use Illuminate\Database\Eloquent\Model;

class TaskEvidence extends Model
{
    protected $table = 'task_evidences';

    protected $fillable = [
        'plan_id',
        'task_id',
        'user_id',
        'actor_token',
        'source',
        'type',
        'external_key',
        'confidence',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source' => EvidenceSource::class,
            'confidence' => 'float',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            EvidenceSource::Native => 'Canovia',
            EvidenceSource::GitHub => 'GitHub',
            EvidenceSource::File => 'ファイル',
            EvidenceSource::Image => '写真',
            EvidenceSource::Calendar => 'カレンダー',
            EvidenceSource::External => '外部',
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'study_practice_assessed' => 'AI演習',
            'study_recall_reviewed' => 'Recall学習',
            'study_language_activity_completed' => '語学Activity',
            'artifact_state_observed' => '制作ファイル',
            'focus_session_completed' => '集中作業',
            'focus_session_interrupted' => '集中作業を中断',
            'guided_execution_reflected' => '実行振り返り',
            'interview_review_completed' => '面接振り返り',
            'interview_result_recorded' => '選考結果',
            'pull_request_observed' => 'GitHub PR',
            'pull_request_review_submitted' => 'GitHubレビュー',
            'pull_request_merged' => 'GitHubマージ',
            'pull_request_ci_observed' => 'GitHub CI',
            'github_issue_observed' => 'GitHub Issue',
            'github_branch_observed' => 'GitHub Branch',
            'github_commit_observed' => 'GitHub Commit',
            'github_deployment_observed' => 'GitHub Deploy',
            'development_quality_gate_confirmed' => '開発品質確認',
            default => '活動',
        };
    }

    public function summary(): string
    {
        return match ($this->type) {
            'study_practice_assessed' => sprintf(
                'AI演習 %d%% · %s',
                (int) data_get($this->metadata, 'score_percent', 0),
                trim((string) data_get($this->metadata, 'evidence_summary')) ?: '評価結果を保存しました。',
            ),
            'study_language_activity_completed' => sprintf(
                '%sを%d周 / セット実施 · %s',
                match ((string) data_get($this->metadata, 'activity_type')) {
                    'dictation' => 'Dictation',
                    'shadowing' => 'Shadowing',
                    default => 'Listening',
                },
                (int) data_get($this->metadata, 'rounds', 0),
                match ((string) data_get($this->metadata, 'outcome_rating')) {
                    'struggled' => 'かなり難しかった',
                    'comfortable' => '安定してできた',
                    default => '一部できた',
                },
            ),
            'study_recall_reviewed' => sprintf(
                'Recall「%s」· %s · 次回 %s',
                (string) data_get($this->metadata, 'prompt', 'カード'),
                match (data_get($this->metadata, 'rating')) {
                    'again' => 'もう一度',
                    'hard' => '難しい',
                    'good' => '思い出せた',
                    'easy' => '余裕',
                    default => '確認',
                },
                data_get($this->metadata, 'interval_days', 0) > 0
                    ? ((int) data_get($this->metadata, 'interval_days')).'日後'
                    : '短時間後',
            ),
            'artifact_state_observed' => sprintf(
                '「%s」を%sしました。',
                (string) data_get($this->metadata, 'title', '制作ファイル'),
                data_get($this->metadata, 'action') === 'created' ? '登録' : '更新',
            ),
            'focus_session_completed' => sprintf(
                '集中タイマーで%d分取り組みました。時間は進捗ではなく補助情報です。',
                (int) data_get($this->metadata, 'actual_minutes', 0),
            ),
            'focus_session_interrupted' => sprintf(
                '集中タイマーを%d分で中断しました。取り組んだ事実だけを記録しています。',
                (int) data_get($this->metadata, 'actual_minutes', 0),
            ),
            'guided_execution_reflected' => sprintf(
                '「%s」を実行。%s%s',
                trim((string) data_get($this->metadata, 'intent')) ?: '今回のAction',
                trim((string) data_get($this->metadata, 'actual_outcome')) ?: '振り返りを記録しました。',
                filled(data_get($this->metadata, 'next_adjustment'))
                    ? ' 次: '.trim((string) data_get($this->metadata, 'next_adjustment'))
                    : '',
            ),
            'interview_review_completed' => sprintf(
                '%sの面接振り返りを完了。次に意識すること: %s',
                (string) data_get($this->metadata, 'company_name', '応募先'),
                trim((string) data_get($this->metadata, 'next_focus')) ?: '未設定',
            ),
            'interview_result_recorded' => sprintf(
                '%sの選考結果を「%s」として記録しました。',
                (string) data_get($this->metadata, 'company_name', '応募先'),
                match (data_get($this->metadata, 'result')) {
                    'passed' => '通過',
                    'rejected' => '不通過',
                    'offer' => '内定・オファー',
                    'withdrawn' => '辞退',
                    default => '結果確認',
                },
            ),
            'pull_request_observed' => sprintf(
                'PR #%dを「%s」として確認しました。%s',
                (int) (data_get($this->metadata, 'pull_request_number') ?? data_get($this->metadata, 'pull_request', 0)),
                match ((string) data_get($this->metadata, 'state')) {
                    'open' => 'open',
                    'closed' => 'closed',
                    default => '確認済み',
                },
                (bool) data_get($this->metadata, 'draft', false)
                    ? 'Draftです。'
                    : '',
            ),
            'github_issue_observed' => sprintf(
                'Issue #%dを「%s」として確認しました。',
                (int) data_get($this->metadata, 'issue_number', 0),
                (string) data_get($this->metadata, 'issue_state', 'unknown'),
            ),
            'github_branch_observed' => sprintf(
                'Branch %s のhead %sを確認しました。',
                (string) data_get($this->metadata, 'branch', 'unknown'),
                mb_substr((string) data_get($this->metadata, 'head_sha', ''), 0, 8),
            ),
            'github_commit_observed' => sprintf(
                'Commit %sをGitHubで確認しました。%s',
                mb_substr((string) data_get($this->metadata, 'commit_sha', ''), 0, 8),
                filled(data_get($this->metadata, 'branch'))
                    ? 'Branch '.(string) data_get($this->metadata, 'branch')
                    : '',
            ),
            'github_deployment_observed' => sprintf(
                '%sへのDeployを「%s」として確認しました。',
                trim((string) data_get($this->metadata, 'environment')) ?: '対象環境',
                (string) data_get($this->metadata, 'deployment_status', 'created'),
            ),
            'development_quality_gate_confirmed' => sprintf(
                '%sを「%s」として明示確認しました。',
                match ((string) data_get($this->metadata, 'quality_gate')) {
                    'verification' => '実機・本番確認',
                    'spec_sync' => '仕様同期',
                    default => '品質Gate',
                },
                match ((string) data_get($this->metadata, 'gate_status')) {
                    'passed' => '確認済み',
                    'failed' => '問題あり',
                    'not_required' => '対象外',
                    default => '未確認',
                },
            ),
            'pull_request_review_submitted' => sprintf(
                'PR #%dで%sが「%s」を送信しました。',
                (int) (data_get($this->metadata, 'pull_request_number') ?? data_get($this->metadata, 'pull_request', 0)),
                trim((string) data_get($this->metadata, 'reviewer')) ?: 'reviewer',
                match (strtoupper((string) data_get($this->metadata, 'review_state'))) {
                    'APPROVED' => '承認',
                    'CHANGES_REQUESTED' => '修正依頼',
                    'DISMISSED' => 'レビュー取消',
                    'COMMENTED' => 'コメント',
                    default => 'レビュー',
                },
            ),
            'pull_request_merged' => sprintf(
                'PR #%dがGitHubでmergeされました。%s',
                (int) (data_get($this->metadata, 'pull_request_number') ?? data_get($this->metadata, 'pull_request', 0)),
                filled(data_get($this->metadata, 'merge_commit_sha'))
                    ? 'Merge commit '.mb_substr((string) data_get($this->metadata, 'merge_commit_sha'), 0, 8)
                    : '',
            ),
            'pull_request_ci_observed' => sprintf(
                'PR #%dのCI結果を「%s」として確認しました。',
                (int) (data_get($this->metadata, 'pull_request_number') ?? data_get($this->metadata, 'pull_request', 0)),
                match ((string) data_get($this->metadata, 'ci_state')) {
                    'success' => '成功',
                    'failure' => '失敗',
                    'pending' => '実行中',
                    default => '未確認',
                },
            ),
            default => 'Taskに関する活動を確認しました。',
        };
    }

}
