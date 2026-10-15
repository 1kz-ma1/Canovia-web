<?php

namespace App\Console\Commands;

use App\Services\ApExamCandidateDraftPreparationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Offline only. --write creates an exclusive private local JSON file.
 * It never mutates a Bundled source, Git working copy or database record.
 */
final class PrepareApExamCandidateNewDraft extends Command
{
    protected $signature = 'study:ap80-prepare-new-draft
        {--notes= : Current V6.24 local review notes JSON}
        {--proposal= : Current V6.25 unapproved corrections JSON}
        {--write : Explicitly create a new private unapproved Draft JSON file}';

    protected $description = 'Prepare an unpublished, independently unreviewed AP A 80-question v0.5 Draft';

    public function handle(ApExamCandidateDraftPreparationService $preparation): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('New AP80 Draft preparation requires local/testing.');
            return self::FAILURE;
        }

        try {
            $notes = $this->readJson($this->option('notes'));
            $proposal = $this->readJson($this->option('proposal'));
            $result = $preparation->prepare($notes, $proposal);
            $summary = $result['summary'];

            if ($this->option('write')) {
                $payload = json_encode($result['candidate'], JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR);
                // Do not write into resources/, public/, a user-provided path,
                // or a shared Git checkout. No overwrites or links.
                $directory = storage_path('app/private/learning-ap80-draft-previews');
                if (is_link($directory)) {
                    throw new \DomainException('Private draft directory must not be a symlink.');
                }
                if (! is_dir($directory)
                    && ! mkdir($directory, 0700, true)
                    && ! is_dir($directory)) {
                    throw new \DomainException('Private draft directory unavailable.');
                }
                if (is_link($directory) || ! is_dir($directory)) {
                    throw new \DomainException('Invalid private draft directory.');
                }
                $filename = $summary['slug'].'-'
                    .substr($summary['new_draft_content_sha256'], 0, 16).'.json';
                $destination = $directory.DIRECTORY_SEPARATOR.$filename;

                $oldUmask = umask(0077);
                $handle = false;
                $created = false;
                try {
                    $handle = @fopen($destination, 'xb');
                    if ($handle === false) {
                        throw new \DomainException('Draft file already exists or cannot be created.');
                    }
                    $created = true;
                    $written = 0;
                    $length = strlen($payload);
                    while ($written < $length) {
                        $count = fwrite($handle, substr($payload, $written));
                        if ($count === false || $count === 0) {
                            throw new \DomainException('Private draft write failed.');
                        }
                        $written += $count;
                    }
                    if (! fflush($handle)) {
                        throw new \DomainException('Private draft flush failed.');
                    }
                } catch (Throwable $e) {
                    if ($handle !== false) {
                        fclose($handle);
                        $handle = false;
                    }
                    if ($created) {
                        @unlink($destination);
                    }
                    throw $e;
                } finally {
                    if ($handle !== false) {
                        fclose($handle);
                    }
                    umask($oldUmask);
                }

                $summary['new_draft_file_saved'] = true;
                $summary['private_file_name'] = $filename;
            }

            // Only the bounded, no-approval summary. Never the question
            // text, explanations, keys, paths or reviewer identifiers.
            $this->line((string) json_encode($summary, JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'schema' => 'canovia.learning.ap_a_80_new_draft_preparation.v1',
                'status' => 'blocked',
                'error_codes' => ['ineligible_revision_or_private_output_failure'],
                'new_draft_file_saved' => false,
                'database_imported' => false,
                'publication_authorized' => false,
            ], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }

    /** @return array<string,mixed> */
    private function readJson(mixed $path): array
    {
        if (! is_string($path) || $path === '' || ! is_file($path)
            || ! is_readable($path) || filesize($path) > 1048576) {
            throw new \DomainException('Missing or overlong local JSON.');
        }
        $raw = file_get_contents($path);
        if (! is_string($raw) || strlen($raw) > 1048576) {
            throw new \DomainException('Invalid local JSON.');
        }
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new \DomainException('Expected a JSON object.');
        }
        return $decoded;
    }
}
