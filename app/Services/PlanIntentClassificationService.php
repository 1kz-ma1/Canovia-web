<?php

namespace App\Services;

final class PlanIntentClassificationService
{
    /**
     * Suggest three independent aspects of a goal. The category is only a
     * compatibility hint; collaboration is never enabled without consent.
     *
     * @return array{domain:string,category:?string,specialization:?string,team_hint:bool}
     */
    public function suggest(string $title, ?string $description = null): array
    {
        $text = mb_strtolower(trim($title.' '.($description ?? '')));
        $teamHint = $this->has($text, ['チームで', '共同で', 'グループで', 'チーム開発', '共同開発']);

        if ($this->has($text, ['就職活動', '就活', '転職活動', '企業研究', '面接対策', '職種探し'])) {
            return $this->result('career', '就活・キャリア', 'job_search', $teamHint);
        }

        if ($this->has($text, ['簿記', '応用情報技術者', '基本情報技術者', '宅建', '英検', 'toeic', '資格試験', '資格取得', '定期テスト', 'テスト勉強'])) {
            return $this->result(
                'study', '資格学習',
                $this->has($text, ['簿記']) ? 'bookkeeping' : 'certification',
                $teamHint,
            );
        }

        if ($this->has($text, [
            'ソフトウェア開発', 'システム開発', 'アプリ開発', 'ゲーム開発',
            'webアプリ', 'webサービス', 'アプリを制作', 'システムを制作',
            'プログラミング', 'github', 'リポジトリ',
        ]) || preg_match('/(?:システム|アプリ|ソフトウェア|ゲーム|web).{0,24}(?:開発|実装)/u', $text) === 1) {
            return $this->result(
                'development', 'ソフトウェア開発',
                $this->has($text, ['ゲーム']) ? 'game_development' : 'software_development',
                $teamHint,
            );
        }

        if ($this->has($text, ['ポスター制作', 'イラスト制作', '映像制作', '作品制作'])) {
            return $this->result('creative', '制作活動', 'creative_work', $teamHint);
        }

        return $this->result('unknown', null, null, $teamHint);
    }

    /** @return array{domain:string,category:?string,specialization:?string,team_hint:bool} */
    private function result(string $domain, ?string $category, ?string $specialization, bool $teamHint): array
    {
        return compact('domain', 'category', 'specialization') + ['team_hint' => $teamHint];
    }

    /** @param array<int,string> $words */
    private function has(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }
}
