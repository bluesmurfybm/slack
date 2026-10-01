<?php
/**
 * LLM 백엔드 설정 표본.
 *
 *   cp studio/inc/llm.config.sample.php studio/inc/llm.config.php
 *
 * 이 파일은 LlmClient 를 하나 return 해야 합니다.
 * llm.config.php 는 .gitignore 로 막혀 있습니다(키가 들어갈 자리).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 먼저 정해야 할 것                                                 │
 * │                                                                  │
 * │ 업무 문서를 사외 API 로 내보내도 되는지가 아직 정해지지 않았습니다 │
 * │ (명세서 §11-5). 고객사 요구사항·일정·담당자 이름이 프롬프트에     │
 * │ 그대로 실립니다. 그 결정 전에는 아래 FixtureLlmClient 로 화면만   │
 * │ 돌려 보십시오 — 아무것도 밖으로 나가지 않습니다.                  │
 * │                                                                  │
 * │ 2026-09-30: 실제 백엔드는 **아직 붙이지 않기로** 확정했습니다.    │
 * │ 반출 승인이 나기 전에는 llm.config.php 를 만들지 마십시오.        │
 * └──────────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);

require_once __DIR__ . '/service/LlmClient.php';

// ---------------------------------------------------------------------
// (1) 아무것도 내보내지 않고 화면만 확인할 때
// ---------------------------------------------------------------------
return new FixtureLlmClient([[
    'tasks' => [
        ['title' => '출석 통합', 'depth' => 1, 'source_ref' => '요구사항!A1:D6'],
        ['title' => '통합 출석부 화면', 'depth' => 2, 'est_md' => 9, 'difficulty' => 4,
         'domain_codes' => ['attendance'], 'source_ref' => '요구사항!B2'],
        ['title' => '출석 결과 성적부 반영', 'depth' => 2, 'est_md' => 6, 'difficulty' => 4,
         'domain_codes' => ['gradebook'], 'source_ref' => '요구사항!B4'],
    ],
]]);

// ---------------------------------------------------------------------
// (2) 실제 백엔드를 붙일 때 — 아직 구현체가 없습니다
//
// LlmClient 를 구현한 클래스를 하나 만들고 여기서 돌려주면 됩니다.
// WbsExtractor 는 인터페이스만 알고 있어서 다른 곳은 고칠 것이 없습니다.
//
//   require_once __DIR__ . '/service/AnthropicLlmClient.php';
//   return new AnthropicLlmClient(
//       apiKey: getenv('ANTHROPIC_API_KEY') ?: '',
//       model:  'claude-sonnet-5',
//   );
//
// 구현할 때 지킬 것 (slackai/worker/llm/anthropic_llm.py 와 같게):
//   · 일시적 오류(429, 5xx, 연결 실패, 형식 깨짐)는 LlmError(retryable: true)
//   · 거부·설정 누락·입력 초과는 LlmError(retryable: false)
//   · 구조화 출력을 지원하면 $schema 를 그대로 넘기고, 아니면 프롬프트에 싣는다
//   · 어느 쪽이든 돌아온 값은 WbsExtractor 가 다시 검증한다
// ---------------------------------------------------------------------
