<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class LearningAnswerEvaluationAdjustment extends Model {
    public $timestamps = false;
    protected $fillable = ['learning_answer_event_id','user_id','actor_token','reason','effect','created_at'];
    protected function casts(): array {return ['created_at'=>'datetime'];}
    public function answerEvent() {return $this->belongsTo(LearningAnswerEvent::class,'learning_answer_event_id');}
}