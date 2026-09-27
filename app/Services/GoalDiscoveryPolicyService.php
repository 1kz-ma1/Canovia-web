<?php

namespace App\Services;

use App\Models\GoalContext;
use App\Models\GoalContextFact;
use InvalidArgumentException;

class GoalDiscoveryPolicyService
{
    public function __construct(
        private readonly GoalContextService $goalContexts,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function nextQuestion(GoalContext $context): ?array
    {
        $context->loadMissing('facts');
        $active = $context->facts->where('state', '!=', 'superseded');

        $hasConfirmedType = fn (string $type) => $active->contains(
            fn (GoalContextFact $fact) => $fact->state === 'confirmed' && $fact->type === $type
        );
        $hasUnknownKey = fn (string $key) => $active->contains(
            fn (GoalContextFact $fact) => $fact->state === 'unknown' && $fact->key === $key
        );
        $hasConfirmedKey = fn (string $key) => $active->contains(
            fn (GoalContextFact $fact) => $fact->state === 'confirmed' && $fact->key === $key
        );

        if (! $hasConfirmedType('current_state') && ! $hasUnknownKey('current_state')) {
            return $this->question('current_state');
        }

        if (! $hasConfirmedType('signal') && ! $hasUnknownKey('success_signal')) {
            if ($hasConfirmedKey('success_signal_style')) {
                return $this->question('success_signal_detail');
            }

            return $this->question('success_signal_style');
        }

        if (! $hasConfirmedType('constraint') && ! $hasUnknownKey('constraints_general')) {
            return $this->question('constraints');
        }

        if (! $hasConfirmedType('driver') && ! $hasUnknownKey('measurement_method')) {
            return $this->question('measurement');
        }

        return null;
    }

    public function presentationMode(GoalContext $context): string
    {
        $profile = $this->profile($context);

        if (($profile['skips'] ?? 0) >= 2) {
            return 'compact';
        }

        if (($profile['text_answers'] ?? 0) >= 2
            && ($profile['text_answers'] ?? 0) > ($profile['quick_answers'] ?? 0)) {
            return 'conversational';
        }

        if (($profile['quick_answers'] ?? 0) >= 2
            && ($profile['quick_answers'] ?? 0) >= (($profile['text_answers'] ?? 0) + 1)) {
            return 'quick';
        }

        return 'balanced';
    }

    /**
     * @return array<string,int|string>
     */
    public function profile(GoalContext $context): array
    {
        return array_merge([
            'quick_answers' => 0,
            'text_answers' => 0,
            'skips' => 0,
            'answered' => 0,
            'preferred' => 'balanced',
            'last_mode' => 'none',
        ], is_array($context->interaction_profile) ? $context->interaction_profile : []);
    }

    public function answer(
        GoalContext $context,
        string $questionId,
        string $mode,
        ?string $choice = null,
        ?string $text = null,
    ): GoalContext {
        $question = $this->question($questionId);
        $expected = $this->nextQuestion($context);

        if (! $expected || $expected['id'] !== $question['id']) {
            throw new InvalidArgumentException('この質問はすでに更新されています。');
        }

        if ($mode === 'quick') {
            $this->applyQuickAnswer($context, $questionId, (string) $choice);
            $this->recordInteraction($context, 'quick');
        } elseif ($mode === 'text') {
            $this->applyTextAnswer($context, $questionId, (string) $text);
            $this->recordInteraction($context, 'text');
        } elseif ($mode === 'skip') {
            $this->applySkip($context, $questionId);
            $this->recordInteraction($context, 'skip');
        } else {
            throw new InvalidArgumentException('Unsupported discovery answer mode.');
        }

        return $this->goalContexts->recalculate($context->fresh());
    }

    /**
     * @return array<string,mixed>
     */
    private function question(string $id): array
    {
        return match ($id) {
            'current_state' => [
                'id' => 'current_state',
                'dimension' => 'current_state',
                'title' => '今はどんな状態に近い？',
                'copy' => '正確じゃなくて大丈夫。今の位置が分かると、最初に「進む」のか「測る」のかを選びやすくなります。',
                'placeholder' => '例：中学まで経験していて5年ブランク / 今月は4台販売した',
                'options' => [
                    ['value' => 'starting', 'label' => 'これから始める'],
                    ['value' => 'returning', 'label' => '前にやっていて、また始めたい'],
                    ['value' => 'active', 'label' => '今も取り組んでいる'],
                    ['value' => 'measurable', 'label' => '実績・数値で説明できる'],
                ],
            ],
            'success_signal_style' => [
                'id' => 'success_signal_style',
                'dimension' => 'success_signal',
                'title' => '「達成した」と判断できる基準はありそう？',
                'copy' => 'まだ曖昧でもOK。近い形だけ選べば、次の1問を絞れます。',
                'placeholder' => '例：月10台売れたら / 試合で90分安定してプレーできたら',
                'options' => [
                    ['value' => 'numeric', 'label' => '数値で決まっている'],
                    ['value' => 'capability', 'label' => 'できるようになりたいことがある'],
                    ['value' => 'result', 'label' => '結果・評価で決まる'],
                    ['value' => 'unknown', 'label' => 'まだ分からない'],
                ],
            ],
            'success_signal_detail' => [
                'id' => 'success_signal_detail',
                'dimension' => 'success_signal',
                'title' => 'その基準を、ざっくり一言で教えて',
                'copy' => '完璧なKPIでなくて大丈夫です。Canoviaはこの内容を成功Signalとして扱います。',
                'placeholder' => '例：月10台 / パス成功率80% / 試合でレギュラーになる',
                'options' => [],
            ],
            'constraints' => [
                'id' => 'constraints',
                'dimension' => 'constraints',
                'title' => '進めるうえで、先に知っておくべき制約はある？',
                'copy' => '細かい予定表は不要です。今のPlanを大きく変えそうな条件だけで十分です。',
                'placeholder' => '例：練習は週2回まで / 平日は1時間 / 12月までに達成したい',
                'options' => [
                    ['value' => 'none', 'label' => '今のところ特にない'],
                    ['value' => 'time', 'label' => '使える時間に制約がある'],
                    ['value' => 'deadline', 'label' => '期限が決まっている'],
                    ['value' => 'money', 'label' => 'お金・予算に制約がある'],
                    ['value' => 'environment', 'label' => '場所・環境に制約がある'],
                ],
            ],
            'measurement' => [
                'id' => 'measurement',
                'dimension' => 'measurement',
                'title' => '変化を見るなら、何を観測するのが近そう？',
                'copy' => '分からなければ飛ばして大丈夫。あとでEvidenceが集まればCanovia側から更新できます。',
                'placeholder' => '例：試合動画を見る / 商談→見積→成約数を記録する',
                'options' => [
                    ['value' => 'metric', 'label' => '回数・成功率などの数値'],
                    ['value' => 'artifact', 'label' => '写真・動画・成果物'],
                    ['value' => 'external', 'label' => 'コーチ・顧客など第三者の反応'],
                    ['value' => 'reflection', 'label' => '自分の振り返り'],
                    ['value' => 'unknown', 'label' => 'まだ分からない'],
                ],
            ],
            default => throw new InvalidArgumentException('Unknown Goal Discovery question.'),
        };
    }

    private function applyQuickAnswer(GoalContext $context, string $questionId, string $choice): void
    {
        $question = $this->question($questionId);
        $allowed = collect($question['options'])->pluck('value');

        if (! $allowed->contains($choice)) {
            throw new InvalidArgumentException('選択肢を確認してください。');
        }

        if ($questionId === 'current_state') {
            $labels = [
                'starting' => 'これから始める',
                'returning' => '前にやっていて、また始めたい',
                'active' => '今も取り組んでいる',
                'measurable' => '実績・数値で説明できる',
            ];
            $label = $labels[$choice];

            $context->update(['current_state_summary' => $label]);
            $this->goalContexts->recordFact(
                $context,
                type: 'current_state',
                label: '現在地',
                value: ['stage' => $choice, 'text' => $label],
                source: 'user_answer',
                state: 'confirmed',
                key: 'current_state_stage',
                confidence: 1,
                importance: 5,
                metadata: ['input_mode' => 'quick'],
            );

            return;
        }

        if ($questionId === 'success_signal_style') {
            if ($choice === 'unknown') {
                $this->recordUnknown($context, 'success_signal', '達成基準');
                return;
            }

            $labels = [
                'numeric' => '数値で決まっている',
                'capability' => 'できるようになりたいことがある',
                'result' => '結果・評価で決まる',
            ];

            $this->goalContexts->recordFact(
                $context,
                type: 'target',
                label: '達成基準の形',
                value: ['style' => $choice, 'text' => $labels[$choice]],
                source: 'user_answer',
                state: 'confirmed',
                key: 'success_signal_style',
                confidence: 1,
                importance: 3,
                metadata: ['input_mode' => 'quick'],
            );

            return;
        }

        if ($questionId === 'constraints') {
            $labels = [
                'none' => '今のところ特にない',
                'time' => '使える時間に制約がある',
                'deadline' => '期限が決まっている',
                'money' => 'お金・予算に制約がある',
                'environment' => '場所・環境に制約がある',
            ];

            $this->goalContexts->recordFact(
                $context,
                type: 'constraint',
                label: '主な制約',
                value: ['kind' => $choice, 'text' => $labels[$choice]],
                source: 'user_answer',
                state: 'confirmed',
                key: 'constraints_general',
                confidence: 1,
                importance: $choice === 'none' ? 2 : 4,
                metadata: ['input_mode' => 'quick'],
            );

            return;
        }

        if ($questionId === 'measurement') {
            if ($choice === 'unknown') {
                $this->recordUnknown($context, 'measurement_method', '現在地の観測方法');
                return;
            }

            $labels = [
                'metric' => '回数・成功率などの数値',
                'artifact' => '写真・動画・成果物',
                'external' => '第三者の反応',
                'reflection' => '自分の振り返り',
            ];

            $this->goalContexts->recordFact(
                $context,
                type: 'driver',
                label: '現在地の観測方法',
                value: ['mode' => $choice, 'text' => $labels[$choice]],
                source: 'user_answer',
                state: 'confirmed',
                key: 'measurement_method',
                confidence: 1,
                importance: 4,
                metadata: ['input_mode' => 'quick', 'measurement' => true],
            );

            return;
        }

        throw new InvalidArgumentException('この質問は選択回答に対応していません。');
    }

    private function applyTextAnswer(GoalContext $context, string $questionId, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('回答を入力してください。');
        }

        if (mb_strlen($text) > 2000) {
            throw new InvalidArgumentException('回答は2000文字以内にしてください。');
        }

        if ($questionId === 'current_state') {
            $context->update(['current_state_summary' => $text]);
            $this->goalContexts->recordFact(
                $context,
                type: 'current_state',
                label: '現在地',
                value: ['text' => $text],
                source: 'user_answer',
                state: 'confirmed',
                key: 'current_state_summary',
                confidence: 1,
                importance: 5,
                metadata: ['input_mode' => 'text'],
            );

            return;
        }

        if (in_array($questionId, ['success_signal_style', 'success_signal_detail'], true)) {
            $this->goalContexts->recordFact(
                $context,
                type: 'signal',
                label: '達成基準',
                value: ['text' => $text],
                source: 'user_answer',
                state: 'confirmed',
                key: 'success_signal',
                confidence: 1,
                importance: 5,
                metadata: ['input_mode' => 'text'],
            );

            return;
        }

        if ($questionId === 'constraints') {
            $this->goalContexts->recordFact(
                $context,
                type: 'constraint',
                label: '主な制約',
                value: ['text' => $text],
                source: 'user_answer',
                state: 'confirmed',
                key: 'constraints_general',
                confidence: 1,
                importance: 4,
                metadata: ['input_mode' => 'text'],
            );

            return;
        }

        if ($questionId === 'measurement') {
            $this->goalContexts->recordFact(
                $context,
                type: 'driver',
                label: '現在地の観測方法',
                value: ['text' => $text],
                source: 'user_answer',
                state: 'confirmed',
                key: 'measurement_method',
                confidence: 1,
                importance: 4,
                metadata: ['input_mode' => 'text', 'measurement' => true],
            );

            return;
        }

        throw new InvalidArgumentException('この質問の回答形式を確認してください。');
    }

    private function applySkip(GoalContext $context, string $questionId): void
    {
        [$key, $label] = match ($questionId) {
            'current_state' => ['current_state', '現在地'],
            'success_signal_style', 'success_signal_detail' => ['success_signal', '達成基準'],
            'constraints' => ['constraints_general', '主な制約'],
            'measurement' => ['measurement_method', '現在地の観測方法'],
            default => throw new InvalidArgumentException('この質問はスキップできません。'),
        };

        $this->recordUnknown($context, $key, $label);
    }

    private function recordUnknown(GoalContext $context, string $key, string $label): void
    {
        $this->goalContexts->recordFact(
            $context,
            type: 'unknown',
            label: $label,
            value: null,
            source: 'user_answer',
            state: 'unknown',
            key: $key,
            confidence: 1,
            importance: 4,
            metadata: ['origin' => 'goal_discovery'],
        );
    }

    private function recordInteraction(GoalContext $context, string $mode): void
    {
        $profile = $this->profile($context);

        if ($mode === 'quick') {
            $profile['quick_answers']++;
            $profile['answered']++;
        } elseif ($mode === 'text') {
            $profile['text_answers']++;
            $profile['answered']++;
        } elseif ($mode === 'skip') {
            $profile['skips']++;
        }

        $profile['last_mode'] = $mode;

        $profile['preferred'] = match (true) {
            $profile['skips'] >= 2 => 'compact',
            $profile['text_answers'] >= 2 && $profile['text_answers'] > $profile['quick_answers'] => 'conversational',
            $profile['quick_answers'] >= 2 && $profile['quick_answers'] >= ($profile['text_answers'] + 1) => 'quick',
            default => 'balanced',
        };

        $context->update(['interaction_profile' => $profile]);
    }
}
