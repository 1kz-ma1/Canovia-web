<?php

namespace App\Services;

/**
 * A write-scoped, deliberately NON-autonomous MCP tool. The effect of the
 * tool call is a short-lived pending request, not a learning-result commit.
 * Browser-owner approval (CSRF-protected) is separately mandatory.
 */
final class McpProposeStudyResultTool
{
    public const NAME = 'canovia_propose_study_practice_result';

    public function __construct(private readonly McpStudyResultProposalService $proposals) {}

    /** @param list<string> $weaknesses
     *  @return array<string,mixed>|null
     */
    public function propose(
        McpVerifiedTokenPrincipal $principal,
        int $planId,
        int $taskId,
        string $key,
        int $questionCount,
        int $correctCount,
        array $weaknesses,
    ): ?array {
        $result = $this->proposals->propose(
            $principal, $planId, $taskId, $key,
            $questionCount, $correctCount, $weaknesses,
        );
        if ($result === null) {
            return null;
        }

        return [
            'schema' => 'canovia.mcp.study_result_proposal.v1',
            'delivery' => 'owner_confirmation_required',
            'proposal_id' => $result['proposal_id'],
            'status' => $result['status'],
            'owner_action' => 'Open your Canovia account page and approve or reject this proposal within 30 minutes. Without explicit approval, no result is recorded.',
            'recorded_in_learning_history' => $result['status'] === 'approved',
            'caveat' => 'Reported question counts and weaknesses are unverified user/AI-provided data; never treat them as externally validated examination marks.',
        ];
    }

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'name' => self::NAME,
            'title' => 'Propose a study result for explicit owner confirmation',
            'description' => 'Propose a bounded 1–20-question Study drill result. Requires an independently issued canovia.study.write OAuth permission AND a separately consented Study Plan progress grant. This only saves a pending 30-minute proposal; the owner must independently approve or reject in Canovia. Retry with the SAME 64-hex idempotency key, do not create a new key for retries. No raw questions, answers or model reasoning are accepted.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'plan_id' => ['type' => 'integer', 'minimum' => 1],
                    'task_id' => ['type' => 'integer', 'minimum' => 1],
                    'idempotency_key' => [
                        'type' => 'string', 'pattern' => '^[a-f0-9]{64}$',
                    ],
                    'question_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                    'correct_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
                    'weaknesses' => [
                        'type' => 'array', 'maxItems' => 5,
                        'items' => ['type' => 'string', 'maxLength' => 32],
                    ],
                ],
                'required' => [
                    'plan_id', 'task_id', 'idempotency_key',
                    'question_count', 'correct_count', 'weaknesses',
                ],
                'additionalProperties' => false,
            ],
            'securitySchemes' => [[
                'type' => 'oauth2',
                'scopes' => [
                    McpProtectedResourceConfiguration::READ_SCOPE,
                    McpProtectedResourceConfiguration::STUDY_WRITE_SCOPE,
                ],
            ]],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }
}
