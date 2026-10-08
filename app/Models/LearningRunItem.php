<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class LearningRunItem extends Model {
    protected $fillable = ['learning_run_id','question_id','ordinal','question_snapshot',
        'grading_rule_snapshot','explanation_snapshot','presented_at'];
    protected function casts(): array {
        return ['ordinal'=>'integer','question_snapshot'=>'array',
            'grading_rule_snapshot'=>'array','presented_at'=>'datetime'];
    }
    public function run() { return $this->belongsTo(LearningRun::class,'learning_run_id'); }
    public function answer() { return $this->hasOne(LearningAnswerEvent::class,'learning_run_item_id'); }
}