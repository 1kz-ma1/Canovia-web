<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class LearningRun extends Model {
    public const MODE_UNDERSTANDING = 'understanding';
    public const MODE_PRACTICE = 'practice';
    public const MODE_EXAM = 'exam';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    protected $fillable = ['plan_id','task_id','user_id','actor_token','start_request_id',
        'question_pack_id','pack_title_snapshot','pack_version_snapshot',
        'mode','status','current_ordinal','queue_policy_version','candidate_generation',
        'exam_profile_key','exam_profile_version','exam_profile_snapshot','exam_deadline_at',
        'started_at','completed_at'];
    protected function casts(): array {
        return ['current_ordinal'=>'integer','candidate_generation'=>'integer','exam_profile_snapshot'=>'array',
            'exam_deadline_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime'];
    }
    public function candidates() {return $this->hasMany(LearningRunCandidate::class)->orderBy('position');}
    public function items() { return $this->hasMany(LearningRunItem::class)->orderBy('ordinal'); }
    public function plan() { return $this->belongsTo(Plan::class); }
    public function task() { return $this->belongsTo(Task::class); }
    public function pack() { return $this->belongsTo(QuestionPack::class,'question_pack_id'); }
}