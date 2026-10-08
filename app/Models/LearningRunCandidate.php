<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class LearningRunCandidate extends Model {
    protected $fillable = ['learning_run_id','question_id','generation','position','reason'];
    protected function casts(): array {return ['generation'=>'integer','position'=>'integer'];}
    public function run() { return $this->belongsTo(LearningRun::class,'learning_run_id'); }
}