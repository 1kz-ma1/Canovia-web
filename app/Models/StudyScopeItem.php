<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyScopeItem extends Model
{
    protected $fillable = [
        'study_scope_capture_id',
        'plan_id',
        'subject',
        'unit',
        'range_text',
        'page_start',
        'page_end',
        'source_excerpt',
        'confidence',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'page_start' => 'integer',
            'page_end' => 'integer',
            'confidence' => 'float',
            'sort_order' => 'integer',
        ];
    }

    public function capture()
    {
        return $this->belongsTo(
            StudyScopeCapture::class,
            'study_scope_capture_id',
        );
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
