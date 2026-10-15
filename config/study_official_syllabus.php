<?php

/**
 * V6.20: source identity and a deliberately EMPTY, fail-closed official
 * taxonomy/Question Bank mapping registry.
 *
 * IPA's official syllabus index identifies AP Level 3 Ver.7.2 as published
 * 2026-01-08 (with terminology-only subject labels refreshed 2026-07-06).
 * The new proposed 2027 exam-system syllabus is NOT this 2026 AP source.
 *
 * This file contains NO transcribed official syllabus content, NO official
 * topic-to-question approval and NO expert content/rights attestation.
 * Do not populate scope_nodes/question_links from AI inference or tags.
 */
return [
    'ap_subject_a_2026' => [
        'exam_code' => 'AP',
        'subject' => '科目A',
        'source_name' => 'IPA 応用情報技術者試験（レベル3）シラバス',
        'source_version' => '7.2',
        'source_index_url' => 'https://www.ipa.go.jp/shiken/syllabus/gaiyou.html',
        'source_document_url' => 'https://www.ipa.go.jp/shiken/syllabus/omgdg50000005kq5-att/syllabus_ap_ver7_2.pdf',
        'published_date' => '2026-01-08',
        'subject_label_updated_date' => '2026-07-06',
        'catalog_state' => 'source_only',
        // Scope items need independent transcriber + reviewer + stable code
        // + traceable source anchor before any mapping becomes eligible.
        'scope_nodes' => [],
        // Explicitly reviewed source Question -> scope node associations only.
        // Do not treat learning_metadata tags as syllabus-mapped evidence.
        'question_links' => [],
    ],
];
