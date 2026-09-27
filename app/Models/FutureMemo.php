<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FutureMemo extends Model
{
    public const KINDS = [
        'want_to_do' => 'やりたいこと',
        'future_self' => 'なりたい自分',
        'interest' => '気になっていること',
        'concern' => '今困っていること',
        'value' => '大事にしたいこと',
    ];

    public const CATEGORIES = [
        'career' => '就職・将来',
        'study' => '勉強・資格',
        'creation' => '制作・開発',
        'life' => '生活',
        'health' => '健康',
        'money' => 'お金',
        'hobby' => '趣味',
        'other' => 'その他',
    ];

    protected $fillable = [
        'user_id',
        'guest_token_hash',
        'kind',
        'category',
        'content',
        'use_for_ai',
        'sort_order',
        'source',
        'source_context_type',
        'source_context_id',
        'captured_at',
    ];

    protected $hidden = ['guest_token_hash'];

    protected function casts(): array
    {
        return [
            'use_for_ai' => 'boolean',
            'sort_order' => 'integer',
            'source_context_id' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? '未来メモ';
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? '未分類';
    }
}
