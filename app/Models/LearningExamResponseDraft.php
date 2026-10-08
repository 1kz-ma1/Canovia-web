<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class LearningExamResponseDraft extends Model {
    protected $fillable = ['learning_run_item_id','last_request_id','answer_value','answered_at'];
    protected function casts(): array {return ['answered_at'=>'datetime'];}
    public function item() {return $this->belongsTo(LearningRunItem::class,'learning_run_item_id');}
}