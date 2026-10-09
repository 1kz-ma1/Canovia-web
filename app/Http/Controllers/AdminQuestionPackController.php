<?php

namespace App\Http\Controllers;

use App\Models\QuestionPack;
use App\Services\AdminAccessService;
use App\Services\AdaptiveExamPackReadinessService;
use App\Services\AdaptiveExamProfileRegistry;
use App\Services\AiJsonInputNormalizer;
use App\Services\QuestionPackCatalogService;
use App\Services\QuestionPackImportService;
use App\Services\QuestionPackPublicationReadinessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AdminQuestionPackController extends Controller
{
    public function __construct(private readonly AdminAccessService $access) {}

    public function index(
        Request $request,
        QuestionPackCatalogService $catalog,
        QuestionPackPublicationReadinessService $readiness,
        AdaptiveExamProfileRegistry $examProfiles,
        AdaptiveExamPackReadinessService $examReadiness,
    )
    {
        $this->ensureAuthorized($request);

        $packs = QuestionPack::query()
            ->with(['questions' => fn ($query) => $query->where('is_active', true)])
            ->withCount('questions')
            ->withCount([
                'questions as active_questions_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->latest('updated_at')
            ->paginate(30);

        $publicationReadiness = $packs->getCollection()->mapWithKeys(
            fn (QuestionPack $pack) => [$pack->id => $readiness->inspect($pack)]
        );

        $verifiedProfiles = $examProfiles->available();
        $examPublicationReadiness = collect();
        foreach ($packs->getCollection() as $pack) {
            foreach ($verifiedProfiles as $profile) {
                if ($pack->exam_code === $profile['exam_code']
                    && $pack->subject === $profile['subject']) {
                    $examPublicationReadiness->put($pack->id,
                        ['profile' => $profile,
                            'inspection' => $examReadiness->inspect($pack, $profile, $pack->questions)]);
                    break;
                }
            }
        }

        $template = [
            'schema_version' => '1.0',
            'pack' => [
                'slug' => 'ap-a-v1',
                'title' => '応用情報技術者試験 科目A',
                'exam_code' => 'AP',
                'subject' => '科目A',
                'version' => '1',
                'downloadable' => true,
                'metadata' => [
                    'match_terms' => ['AP', '応用情報', '応用情報技術者試験'],
                    'locale' => 'ja-JP',
                ],
            ],
            'questions' => [[
                'external_key' => 'sample-001',
                'source_type' => 'official',
                'source_reference' => '出典を年度・区分・問番号などで記載',
                'prompt' => '問題文',
                'response_schema' => [[
                    'id' => 'answer',
                    'type' => 'single_choice',
                    'label' => '回答',
                    'required' => true,
                    'choices' => [
                        ['id' => 'A', 'label' => '選択肢A'],
                        ['id' => 'B', 'label' => '選択肢B'],
                    ],
                ]],
                'grading_rule' => [
                    'type' => 'exact_choice',
                    'field_id' => 'answer',
                    'answer' => 'A',
                ],
                'learning_metadata' => [
                    'concepts' => ['ネットワーク'],
                    'weakness_targets' => ['DNS'],
                    'tags' => ['科目A'],
                    'keywords' => ['名前解決'],
                ],
                'explanation' => '正答の理由・復習用解説',
                'difficulty' => 3,
                'sort_order' => 1,
                'is_active' => true,
            ]],
        ];

        // A JSON file in the repository does not imply that this Pack has
        // been installed in the current database or published to learners.
        $bundledPacks = $catalog->all();
        $bundledSlugs = $bundledPacks->pluck('slug')
            ->filter(fn ($slug) => is_string($slug) && $slug !== '')
            ->unique()->values()->all();
        $bundledInstallations = QuestionPack::query()
            ->whereIn('slug', $bundledSlugs)
            ->get(['id', 'slug', 'status', 'version'])
            ->keyBy('slug');

        return view('admin.question_packs.index', [
            'packs' => $packs,
            'bundledInstallations' => $bundledInstallations,
            'publicationReadiness' => $publicationReadiness,
            'examPublicationReadiness' => $examPublicationReadiness,
            'bundledPacks' => $bundledPacks,
            'importTemplate' => json_encode(
                $template,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ),
        ]);
    }

    public function import(
        Request $request,
        AiJsonInputNormalizer $normalizer,
        QuestionPackImportService $importer,
    ) {
        $this->ensureAuthorized($request);

        $validated = $request->validate([
            'pack_json' => ['required', 'string', 'max:2500000'],
        ]);

        try {
            $json = $normalizer->normalize($validated['pack_json']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'pack_json' => $exception->getMessage(),
            ]);
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'pack_json' => 'Question Pack JSONを読み取れませんでした。',
            ]);
        }

        $result = $importer->import($decoded);

        return redirect()
            ->route('admin.question_packs.index')
            ->with(
                'status',
                "{$result['pack']->title} を取り込みました。新規 {$result['created']}問 / 更新 {$result['updated']}問 / 無効化 {$result['deactivated']}問です。"
            );
    }

    public function importBundled(
        Request $request,
        QuestionPackCatalogService $catalog,
        QuestionPackImportService $importer,
    ) {
        $this->ensureAuthorized($request);

        $validated = $request->validate([
            'catalog_key' => ['required', 'string', 'max:240'],
        ]);

        $payload = $catalog->payload((string) $validated['catalog_key']);
        $result = $importer->import($payload);

        return redirect()
            ->route('admin.question_packs.index')
            ->with(
                'status',
                "{$result['pack']->title} をBundled PackからDraftへ取り込みました。新規 {$result['created']}問 / 更新 {$result['updated']}問 / 無効化 {$result['deactivated']}問です。"
            );
    }

    public function updateStatus(
        Request $request,
        QuestionPack $questionPack,
        QuestionPackPublicationReadinessService $readiness,
    )
    {
        $this->ensureAuthorized($request);

        $validated = $request->validate([
            'status' => ['required', Rule::in(QuestionPack::STATUSES)],
        ]);

        $status = (string) $validated['status'];

        if ($questionPack->status === 'published' && ! in_array($status, ['published', 'retired'], true)) {
            throw ValidationException::withMessages([
                'status' => '公開済みPackはDraft/Reviewへ戻せません。修正版は新しいslug/versionで作成してください。',
            ]);
        }

        if ($questionPack->status === 'retired' && $status !== 'retired') {
            throw ValidationException::withMessages([
                'status' => 'retired Packは再公開できません。新しいversionを作成してください。',
            ]);
        }

        if ($status === 'published') {
            $inspection = $readiness->inspect($questionPack);
            if (! $inspection['publishable']) {
                throw ValidationException::withMessages([
                    'status' => implode(' ', $inspection['blocking']),
                ]);
            }
        }

        $questionPack->update(['status' => $status]);

        return back()->with(
            'status',
            "{$questionPack->title} の状態を {$status} へ変更しました。"
        );
    }

    private function ensureAuthorized(Request $request): void
    {
        if (! $this->access->authorized($request)) {
            abort(403);
        }
    }
}
