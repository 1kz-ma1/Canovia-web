<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class LearningAnswerEvent extends Model {
    protected $fillable = ['learning_run_item_id','request_id','answer_value',
        'was_correct','grading_method','answered_at','elapsed_ms',
        'explanation_seen_at','evaluation_contribution','evaluation_confidence'];
    protected function casts(): array {
        return ['was_correct'=>'boolean','answered_at'=>'datetime',
            'explanation_seen_at'=>'datetime','elapsed_ms'=>'integer'];
    }
    public function item() { return $this->belongsTo(LearningRunItem::class,'learning_run_item_id'); }
}