<?php
/**
 * API 문서 명세(OpenAPI JSON). .json 파일로 두면 nginx 가 로그인 없이 내주므로 PHP 안에 둔다.
 */
return <<<'JSON'
{
  "openapi": "3.0.3",
  "info": {
    "title": "iWorks API",
    "version": "1.0.0",
    "description": "마이페이지 → API 토큰의 개인 토큰을 `Authorization: Bearer` 로 보냅니다(HTTPS 전용).\n\n```bash\ncurl -H \"Authorization: Bearer $IWORKS_API_TOKEN\" https://iworks.bizblue.co.kr/api/me.php\n```\n\n이 화면의 Try it out 은 GET 만 보냅니다."
  },
  "servers": [{"url": ".."}],
  "tags": [
    {"name": "계정"},
    {"name": "EnvHub", "description": "응답에 비밀번호가 평문으로 들어 있습니다."},
    {"name": "TaskSheet"},
    {"name": "블루 클러스터", "description": "사무실 PC(노드)용. 모든 오류 응답 본문은 `{\"reason\": \"...\"}` 이고, `ok: false` 는 오류가 아니라 거절이라 200 입니다. 필수 본문 필드가 없으면 `400 {\"reason\": \"필수 값 없음: <필드>\"}` 입니다. 규약 원문은 blue-cluster 저장소의 docs/iworks-api.md."}
  ],
  "paths": {
    "/api/me.php": {
      "get": {
        "tags": ["계정"],
        "summary": "내 계정",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/Me"}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      },
      "put": {
        "tags": ["계정"],
        "summary": "내 계정 수정",
        "description": "브라우저 로그인 전용. 비밀번호를 바꾸면 API 토큰이 폐기됩니다.",
        "security": [{"sessionCookie": []}],
        "requestBody": {"content": {"application/json": {"schema": {"$ref": "#/components/schemas/MeInput"}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/Me"}}}},
          "403": {"$ref": "#/components/responses/SessionOnly"}
        }
      }
    },
    "/api/token.php": {
      "get": {
        "tags": ["계정"],
        "summary": "내 API 토큰",
        "description": "브라우저 로그인 전용.",
        "security": [{"sessionCookie": []}],
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/TokenInfo"}}}},
          "403": {"$ref": "#/components/responses/SessionOnly"}
        }
      },
      "post": {
        "tags": ["계정"],
        "summary": "API 토큰 발급(ensure)·갱신(rotate)",
        "description": "브라우저 로그인 전용.",
        "security": [{"sessionCookie": []}],
        "requestBody": {"content": {"application/json": {"schema": {"type": "object", "required": ["action"], "properties": {"action": {"type": "string", "enum": ["ensure", "rotate"]}}}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"type": "object", "properties": {"ok": {"type": "boolean"}, "token": {"type": "string"}}}}}},
          "403": {"$ref": "#/components/responses/SessionOnly"}
        }
      },
      "delete": {
        "tags": ["계정"],
        "summary": "API 토큰 폐기",
        "description": "브라우저 로그인 전용.",
        "security": [{"sessionCookie": []}],
        "responses": {
          "200": {"description": "OK"},
          "403": {"$ref": "#/components/responses/SessionOnly"}
        }
      }
    },
    "/access/access_api.php": {
      "get": {
        "tags": ["EnvHub"],
        "summary": "대학별 접속 정보 전체",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"$ref": "#/components/parameters/All"}],
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"type": "object", "properties": {"ok": {"type": "boolean"}, "rows": {"type": "array", "items": {"$ref": "#/components/schemas/AccessRow"}}}}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      }
    },
    "/access/api_schools.php": {
      "get": {
        "tags": ["EnvHub"],
        "summary": "대학 이름·버전·저장소·테스트 서버",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [
          {"$ref": "#/components/parameters/All"},
          {"$ref": "#/components/parameters/Ver"},
          {"name": "q", "in": "query", "description": "대학명 일부", "schema": {"type": "string"}},
          {"name": "school_id", "in": "query", "schema": {"type": "integer"}}
        ],
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"type": "object", "properties": {"ok": {"type": "boolean"}, "count": {"type": "integer"}, "rows": {"type": "array", "items": {"$ref": "#/components/schemas/SchoolApiRow"}}}}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      }
    },
    "/schooltodo/api.php?p=/sheets": {
      "get": {
        "tags": ["TaskSheet"],
        "summary": "시트 목록",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "all", "in": "query", "description": "1 이면 보관한 시트 포함", "schema": {"type": "string"}}],
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"type": "array", "items": {"$ref": "#/components/schemas/Sheet"}}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      },
      "post": {
        "tags": ["TaskSheet"],
        "summary": "시트 만들기",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "requestBody": {"content": {"application/json": {"schema": {"type": "object", "required": ["title"], "properties": {"title": {"type": "string"}, "description": {"type": "string"}, "stages": {"$ref": "#/components/schemas/Stages"}}}}}},
        "responses": {
          "201": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/SheetDetail"}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      }
    },
    "/schooltodo/api.php?p=/sheets/:id": {
      "get": {
        "tags": ["TaskSheet"],
        "summary": "시트 상세",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [
          {"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/sheets/1"}},
          {"name": "status", "in": "query", "schema": {"$ref": "#/components/schemas/StatusKo"}},
          {"name": "sort", "in": "query", "description": "열 이름, 앞에 `-` 면 내림차순", "schema": {"type": "string", "example": "-updated_at"}}
        ],
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/SheetDetail"}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      },
      "put": {
        "tags": ["TaskSheet"],
        "summary": "시트 수정·보관",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/sheets/1"}}],
        "requestBody": {"content": {"application/json": {"schema": {"type": "object", "properties": {
          "title": {"type": "string"},
          "description": {"type": "string"},
          "archived": {"type": "boolean"},
          "stages": {"$ref": "#/components/schemas/Stages"},
          "renames": {"type": "object", "additionalProperties": {"type": "string"}, "description": "이전 단계 이름 → 새 이름", "example": {"검수": "검수완료"}}
        }}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/SheetDetail"}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      }
    },
    "/schooltodo/api.php?p=/sheets/:id/cells": {
      "put": {
        "tags": ["TaskSheet"],
        "summary": "대학별 상태·메모 저장",
        "description": "한 건이라도 틀리면 전부 되돌립니다.",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/sheets/1/cells"}}],
        "requestBody": {"content": {"application/json": {"schema": {"$ref": "#/components/schemas/CellItems"}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"type": "object", "properties": {"updated": {"type": "integer"}, "rows": {"type": "array", "items": {"$ref": "#/components/schemas/SheetRow"}}, "sheet": {"$ref": "#/components/schemas/Sheet"}}}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      }
    },
    "/schooltodo/api.php?p=/sheets/:id/cells/:school_id/history": {
      "get": {
        "tags": ["TaskSheet"],
        "summary": "셀 이력(최근 10건)",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/sheets/1/cells/12/history"}}],
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"type": "object", "properties": {"school_id": {"type": "integer"}, "history": {"type": "array", "items": {"$ref": "#/components/schemas/Cell"}}}}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      }
    },
    "/schooltodo/api.php?p=/sheets/:id/versions": {
      "get": {
        "tags": ["TaskSheet"],
        "summary": "버전별 대학 상태",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [
          {"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/sheets/1/versions"}},
          {"$ref": "#/components/parameters/Ver"},
          {"name": "status", "in": "query", "schema": {"$ref": "#/components/schemas/Status"}}
        ],
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/VersionsResult"}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      }
    },
    "/schooltodo/api.php?p=/sheets/:id/status": {
      "put": {
        "tags": ["TaskSheet"],
        "summary": "대학별 상태 변경(도구용)",
        "description": "`cells` 와 같지만 `status` 가 필수이고 영문 코드도 받습니다. 추가한 단계는 이름 그대로 씁니다.",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/sheets/1/status"}}],
        "requestBody": {"content": {"application/json": {"schema": {"$ref": "#/components/schemas/StatusItems"}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"type": "object", "properties": {"updated": {"type": "integer"}, "schools": {"type": "array", "items": {"$ref": "#/components/schemas/SchoolStatus"}}}}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      }
    },
    "/bluecluster/api.php?p=/jobs": {
      "post": {
        "tags": ["블루 클러스터"],
        "summary": "작업 등록",
        "description": "등록자(submitter)는 본문으로 받지 않고 인증된 사용자(토큰 주인)로 정해집니다.",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/jobs"}}],
        "requestBody": {"content": {"application/json": {"schema": {"type": "object", "required": ["title", "repos", "instructions"], "properties": {"title": {"type": "string"}, "repos": {"type": "array", "items": {"type": "string"}}, "instructions": {"type": "string"}}}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"type": "object", "properties": {"job_id": {"type": "string"}}}}}},
          "400": {"$ref": "#/components/responses/BclError"},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      }
    },
    "/bluecluster/api.php?p=/jobs/open": {
      "get": {
        "tags": ["블루 클러스터"],
        "summary": "리더가 필요한 작업",
        "description": "끝나지 않았고 리더가 없거나 리스가 끝난 작업입니다.",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/jobs/open"}}],
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"type": "object", "properties": {"jobs": {"type": "array", "items": {"$ref": "#/components/schemas/BclJob"}}}}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      }
    },
    "/bluecluster/api.php?p=/jobs/:id": {
      "get": {
        "tags": ["블루 클러스터"],
        "summary": "작업 하나",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/jobs/job-1"}}],
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/BclJob"}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"},
          "404": {"$ref": "#/components/responses/BclError"}
        }
      }
    },
    "/bluecluster/api.php?p=/jobs/:id/claim": {
      "post": {
        "tags": ["블루 클러스터"],
        "summary": "리더 차지",
        "description": "리더가 없거나 리스가 끝났을 때만 `ok: true` 이고 `epoch` 가 1 늘며 리스가 3초 늘어납니다. 아니면 `ok: false` 와 현재 `leader`, `epoch` 를 돌려줍니다.",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/jobs/job-1/claim"}}],
        "requestBody": {"content": {"application/json": {"schema": {"type": "object", "required": ["node_id"], "properties": {"node_id": {"type": "string"}}}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"type": "object", "properties": {"ok": {"type": "boolean"}, "leader": {"type": "string", "nullable": true}, "epoch": {"type": "integer"}}}}}},
          "400": {"$ref": "#/components/responses/BclError"},
          "401": {"$ref": "#/components/responses/Unauthorized"},
          "404": {"$ref": "#/components/responses/BclError"}
        }
      }
    },
    "/bluecluster/api.php?p=/jobs/:id/lease": {
      "post": {
        "tags": ["블루 클러스터"],
        "summary": "리스 연장",
        "description": "현재 리더이고 `epoch` 가 같을 때만 `ok: true` 입니다.",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/jobs/job-1/lease"}}],
        "requestBody": {"content": {"application/json": {"schema": {"type": "object", "required": ["node_id", "epoch"], "properties": {"node_id": {"type": "string"}, "epoch": {"type": "integer"}}}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/BclOk"}}}},
          "400": {"$ref": "#/components/responses/BclError"},
          "401": {"$ref": "#/components/responses/Unauthorized"},
          "404": {"$ref": "#/components/responses/BclError"}
        }
      }
    },
    "/bluecluster/api.php?p=/jobs/:id/pieces": {
      "post": {
        "tags": ["블루 클러스터"],
        "summary": "조각 등록",
        "description": "현재 리더·epoch 가 아니면 `ok: false`. 이미 조각이 있으면 바꾸지 않고 `ok: true` 입니다.",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/jobs/job-1/pieces"}}],
        "requestBody": {"content": {"application/json": {"schema": {"type": "object", "required": ["node_id", "epoch", "pieces"], "properties": {"node_id": {"type": "string"}, "epoch": {"type": "integer"}, "pieces": {"type": "array", "items": {"type": "object", "required": ["piece_id", "repo", "desc"], "properties": {"piece_id": {"type": "string"}, "repo": {"type": "string"}, "desc": {"type": "string"}}}}}}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/BclOk"}}}},
          "400": {"$ref": "#/components/responses/BclError"},
          "401": {"$ref": "#/components/responses/Unauthorized"},
          "404": {"$ref": "#/components/responses/BclError"}
        }
      }
    },
    "/bluecluster/api.php?p=/jobs/:id/pieces/:pid/start": {
      "post": {
        "tags": ["블루 클러스터"],
        "summary": "조각 시도 시작",
        "description": "조각이 `pending` 이 아니거나 작업이 `done` 이면 `ok: false`, `attempt_no: null` 이고 시도를 만들지 않습니다.",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/jobs/job-1/pieces/p1/start"}}],
        "requestBody": {"content": {"application/json": {"schema": {"type": "object", "required": ["node_id", "account"], "properties": {"node_id": {"type": "string"}, "account": {"type": "string"}}}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"type": "object", "properties": {"ok": {"type": "boolean"}, "attempt_no": {"type": "integer", "nullable": true, "description": "조각별 1부터"}}}}}},
          "400": {"$ref": "#/components/responses/BclError"},
          "401": {"$ref": "#/components/responses/Unauthorized"},
          "404": {"$ref": "#/components/responses/BclError"}
        }
      }
    },
    "/bluecluster/api.php?p=/jobs/:id/pieces/:pid/finish": {
      "post": {
        "tags": ["블루 클러스터"],
        "summary": "조각 시도 끝",
        "description": "`result`, `patch`, `log` 는 서버 `files_dir` 에 원본 그대로 저장되어 수 MB 일 수 있습니다. 이미 끝난 시도이거나 `node_id` 가 시도의 노드가 아니면 `ok: false` 입니다. `status` 는 `done`, `stopped_by_owner`, `lost` 만 받습니다(그 밖은 400).",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/jobs/job-1/pieces/p1/finish"}}],
        "requestBody": {"content": {"application/json": {"schema": {"type": "object", "required": ["node_id", "attempt_no", "status", "result", "patch", "log"], "properties": {
          "node_id": {"type": "string"},
          "attempt_no": {"type": "integer"},
          "status": {"type": "string", "enum": ["done", "stopped_by_owner", "lost"]},
          "result": {"type": "string"},
          "patch": {"type": "string"},
          "log": {"type": "array", "items": {}},
          "redactions": {"type": "object", "additionalProperties": {"type": "integer"}, "description": "노드가 올리기 전에 가린 횟수(종류별)", "example": {"secret_assign": 2}}
        }}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/BclOk"}}}},
          "400": {"$ref": "#/components/responses/BclError"},
          "401": {"$ref": "#/components/responses/Unauthorized"},
          "404": {"$ref": "#/components/responses/BclError"}
        }
      }
    },
    "/bluecluster/api.php?p=/jobs/:id/pieces/:pid/close": {
      "post": {
        "tags": ["블루 클러스터"],
        "summary": "리더가 조각 시도 닫기",
        "description": "조각은 다시 `pending`, 시도는 `lost` 가 됩니다. 현재 리더·epoch 가 아니거나 이미 끝난 시도면 `ok: false` 입니다.",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/jobs/job-1/pieces/p1/close"}}],
        "requestBody": {"content": {"application/json": {"schema": {"type": "object", "required": ["node_id", "epoch", "attempt_no", "status"], "properties": {"node_id": {"type": "string"}, "epoch": {"type": "integer"}, "attempt_no": {"type": "integer"}, "status": {"type": "string", "enum": ["lost"]}}}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/BclOk"}}}},
          "400": {"$ref": "#/components/responses/BclError"},
          "401": {"$ref": "#/components/responses/Unauthorized"},
          "404": {"$ref": "#/components/responses/BclError"}
        }
      }
    },
    "/bluecluster/api.php?p=/jobs/:id/finish": {
      "post": {
        "tags": ["블루 클러스터"],
        "summary": "작업 끝",
        "description": "현재 리더·epoch 가 아니면 `ok: false`. 성공하면 `state` 가 `done` 이 됩니다.",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/jobs/job-1/finish"}}],
        "requestBody": {"content": {"application/json": {"schema": {"type": "object", "required": ["node_id", "epoch", "summary"], "properties": {"node_id": {"type": "string"}, "epoch": {"type": "integer"}, "summary": {"type": "string"}}}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/BclOk"}}}},
          "400": {"$ref": "#/components/responses/BclError"},
          "401": {"$ref": "#/components/responses/Unauthorized"},
          "404": {"$ref": "#/components/responses/BclError"}
        }
      }
    },
    "/bluecluster/api.php?p=/nodes/heartbeat": {
      "post": {
        "tags": ["블루 클러스터"],
        "summary": "노드 하트비트",
        "description": "`attempts` 는 이 노드가 실행 중인 시도이고 각 항목은 `<job_id>/<piece_id>/<attempt_no>` 입니다.",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/nodes/heartbeat"}}],
        "requestBody": {"content": {"application/json": {"schema": {"type": "object", "required": ["node_id", "account", "addr", "free_cpu", "free_mem", "running", "attempts"], "properties": {
          "node_id": {"type": "string"},
          "account": {"type": "string"},
          "addr": {"type": "string"},
          "free_cpu": {"type": "number"},
          "free_mem": {"type": "integer", "description": "바이트"},
          "running": {"type": "integer"},
          "attempts": {"type": "array", "items": {"type": "string", "example": "job-1/p1/1"}}
        }}}}},
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/BclOk"}}}},
          "400": {"$ref": "#/components/responses/BclError"},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      }
    },
    "/bluecluster/api.php?p=/nodes/live": {
      "get": {
        "tags": ["블루 클러스터"],
        "summary": "살아 있는 노드",
        "description": "마지막 하트비트가 3초 안인 노드입니다. 각 항목은 하트비트 본문 필드와 `last_seen` 입니다.",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [{"name": "p", "in": "query", "required": true, "schema": {"type": "string", "example": "/nodes/live"}}],
        "responses": {
          "200": {"description": "OK", "content": {"application/json": {"schema": {"type": "object", "properties": {"nodes": {"type": "array", "items": {"type": "object", "properties": {"node_id": {"type": "string"}, "account": {"type": "string"}, "addr": {"type": "string"}, "free_cpu": {"type": "number"}, "free_mem": {"type": "integer"}, "running": {"type": "integer"}, "attempts": {"type": "array", "items": {"type": "string"}}, "last_seen": {}}}}}}}}},
          "401": {"$ref": "#/components/responses/Unauthorized"}
        }
      }
    }
  },
  "components": {
    "securitySchemes": {
      "bearerAuth": {"type": "http", "scheme": "bearer", "bearerFormat": "iwk_…"},
      "sessionCookie": {"type": "apiKey", "in": "cookie", "name": "BLUEIWORK_SESSID"}
    },
    "parameters": {
      "All": {"name": "all", "in": "query", "description": "있으면 사용하지 않는 대학 포함", "schema": {"type": "string"}},
      "Ver": {"name": "ver", "in": "query", "schema": {"type": "string", "example": "4.5"}}
    },
    "responses": {
      "Unauthorized": {"description": "토큰 없음·틀림"},
      "SessionOnly": {"description": "토큰으로는 불가"},
      "BclError": {"description": "`{\"reason\": \"...\"}` (필수 값 없음·JSON 형식 오류·등록되지 않은 작업·없는 조각·없는 시도 등)", "content": {"application/json": {"schema": {"type": "object", "properties": {"reason": {"type": "string"}}}}}}
    },
    "schemas": {
      "BclOk": {"type": "object", "properties": {"ok": {"type": "boolean", "description": "false 는 오류가 아니라 거절(상태 200)"}}},
      "BclAttempt": {"type": "object", "properties": {"attempt_no": {"type": "integer"}, "node_id": {"type": "string"}, "account": {"type": "string"}, "status": {"type": "string", "enum": ["running", "done", "stopped_by_owner", "lost"]}, "started": {}, "ended": {"nullable": true}, "result_path": {"type": "string", "nullable": true}, "patch_path": {"type": "string", "nullable": true}, "log_path": {"type": "string", "nullable": true}, "redactions": {"type": "object", "nullable": true, "additionalProperties": {"type": "integer"}}, "closed_by": {"type": "string", "nullable": true}}},
      "BclPiece": {"type": "object", "properties": {"piece_id": {"type": "string"}, "repo": {"type": "string"}, "desc": {"type": "string"}, "state": {"type": "string", "enum": ["pending", "running", "done"]}, "attempts": {"type": "array", "items": {"$ref": "#/components/schemas/BclAttempt"}}}},
      "BclJob": {"type": "object", "properties": {"job_id": {"type": "string"}, "title": {"type": "string"}, "submitter": {"type": "string"}, "repos": {"type": "array", "items": {"type": "string"}}, "instructions": {"type": "string"}, "state": {"type": "string", "enum": ["open", "done"]}, "leader": {"type": "string", "nullable": true}, "epoch": {"type": "integer"}, "lease_until": {}, "pieces": {"type": "array", "items": {"$ref": "#/components/schemas/BclPiece"}}}},
      "Me": {
        "type": "object",
        "properties": {
          "name": {"type": "string"},
          "email": {"type": "string"},
          "slack_email": {"type": "string", "nullable": true},
          "gmail_user": {"type": "string", "nullable": true},
          "color": {"type": "string"},
          "bg_pref": {"type": "string"},
          "has_api_token": {"type": "boolean"}
        }
      },
      "MeInput": {
        "type": "object",
        "description": "보낸 칸만 고칩니다.",
        "properties": {
          "name": {"type": "string"},
          "email": {"type": "string"},
          "password": {"type": "string"},
          "slack_token": {"type": "string"},
          "slack_email": {"type": "string"},
          "gmail_user": {"type": "string"},
          "gmail_pass": {"type": "string"},
          "bg_pref": {"type": "string", "enum": ["weather", "plain"]},
          "color": {"type": "string", "example": "#3b82f6"}
        }
      },
      "TokenInfo": {
        "type": "object",
        "properties": {
          "has": {"type": "boolean"},
          "token": {"type": "string"},
          "created_at": {"type": "string", "nullable": true},
          "last_used_at": {"type": "string", "nullable": true},
          "last_used_ip": {"type": "string", "nullable": true}
        }
      },
      "AccessRow": {
        "type": "object",
        "properties": {
          "school_id": {"type": "integer"},
          "name": {"type": "string"},
          "ver": {"type": "string"},
          "dev": {"type": "string"},
          "ops": {"type": "string"},
          "repo": {"type": "string"},
          "vpn": {"type": "string"},
          "test_srv": {"type": "string", "description": "`아이피:포트`, `아이디 / 비밀번호`, 프로젝트 폴더"},
          "login_dev_id": {"type": "string"},
          "login_dev": {"type": "string"},
          "login_ops_id": {"type": "string"},
          "login_ops": {"type": "string"},
          "dev_db": {"type": "string"},
          "ops_db": {"type": "string"},
          "haksa_db": {"type": "string"}
        }
      },
      "SchoolApiRow": {
        "type": "object",
        "properties": {
          "name": {"type": "string"},
          "ver": {"type": "string"},
          "repo": {"type": "string"},
          "test_server": {
            "type": "object",
            "properties": {
              "ip": {"type": "string"},
              "port": {"type": "integer", "nullable": true},
              "username": {"type": "string"},
              "password": {"type": "string"},
              "project_root": {"type": "string"}
            }
          }
        }
      },
      "Stages": {
        "type": "array",
        "items": {"type": "string", "maxLength": 20},
        "description": "미조치·해당없음 사이 단계. 진행중·개발반영·운영반영은 이 순서로 꼭 들어가고, 나머지는 어디든 넣는다.",
        "example": ["진행중", "개발반영", "운영반영", "검수완료"]
      },
      "StatusKo": {"type": "string", "enum": ["미조치", "진행중", "개발반영", "운영반영", "해당없음"]},
      "StatusCode": {"type": "string", "enum": ["todo", "in_progress", "dev_applied", "prod_applied", "not_applicable"]},
      "Status": {"oneOf": [{"$ref": "#/components/schemas/StatusCode"}, {"$ref": "#/components/schemas/StatusKo"}]},
      "SchoolRef": {
        "type": "object",
        "description": "`school_id` 또는 `school`(대학명). 대학명이 여러 버전이면 `ver` 도 보냅니다.",
        "properties": {
          "school_id": {"type": "integer"},
          "school": {"type": "string"},
          "ver": {"type": "string"},
          "note": {"type": "string"}
        }
      },
      "CellItems": {
        "type": "object",
        "properties": {
          "items": {"type": "array", "items": {"allOf": [{"$ref": "#/components/schemas/SchoolRef"}, {"properties": {"status": {"$ref": "#/components/schemas/StatusKo"}}}]}}
        }
      },
      "StatusItems": {
        "type": "object",
        "properties": {
          "items": {"type": "array", "items": {"allOf": [{"$ref": "#/components/schemas/SchoolRef"}, {"required": ["status"], "properties": {"status": {"$ref": "#/components/schemas/Status"}}}]}}
        }
      },
      "Sheet": {
        "type": "object",
        "properties": {
          "id": {"type": "integer"},
          "title": {"type": "string"},
          "description": {"type": "string"},
          "archived_at": {"type": "string"},
          "stages": {"type": "array", "items": {"type": "string"}},
          "total": {"type": "integer"},
          "counts": {"type": "object", "additionalProperties": {"type": "integer"}},
          "done": {"type": "integer"},
          "target": {"type": "integer", "description": "해당없음을 뺀 대학 수"}
        }
      },
      "School": {
        "type": "object",
        "properties": {
          "school_id": {"type": "integer"},
          "name": {"type": "string"},
          "ver": {"type": "string"}
        }
      },
      "Cell": {
        "type": "object",
        "properties": {
          "status": {"$ref": "#/components/schemas/StatusKo"},
          "note": {"type": "string"},
          "updated_by": {"type": "string"},
          "updated_at": {"type": "string"}
        }
      },
      "SheetRow": {
        "allOf": [
          {"$ref": "#/components/schemas/School"},
          {"$ref": "#/components/schemas/Cell"},
          {"properties": {"stages": {"type": "array", "items": {"type": "string"}, "description": "이 대학이 고를 수 있는 단계"}, "done": {"type": "boolean"}}}
        ]
      },
      "SchoolStatus": {
        "allOf": [
          {"$ref": "#/components/schemas/School"},
          {"properties": {"status": {"$ref": "#/components/schemas/StatusCode"}, "status_label": {"$ref": "#/components/schemas/StatusKo"}, "updated_by": {"type": "string"}, "updated_at": {"type": "string"}}}
        ]
      },
      "SheetDetail": {
        "allOf": [
          {"$ref": "#/components/schemas/Sheet"},
          {"properties": {"rows": {"type": "array", "items": {"$ref": "#/components/schemas/SheetRow"}}}}
        ]
      },
      "VersionsResult": {
        "allOf": [
          {"$ref": "#/components/schemas/Sheet"},
          {
            "properties": {
              "versions": {
                "type": "array",
                "items": {
                  "type": "object",
                  "properties": {
                    "ver": {"type": "string"},
                    "total": {"type": "integer"},
                    "counts": {"type": "object", "additionalProperties": {"type": "integer"}},
                    "schools": {"type": "array", "items": {"$ref": "#/components/schemas/SchoolStatus"}}
                  }
                }
              }
            }
          }
        ]
      }
    }
  }
}
JSON;
