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
    "description": "스크립트·봇·Claude 스킬에서 부를 수 있는 iWorks API 목록입니다. 화면(HTML) 페이지와 화면만 쓰는 쓰기 API 는 뺐습니다.\n\n## 인증\n- 마이페이지 → **API 토큰**에서 받은 개인 토큰을 `Authorization: Bearer iwk_…` 로 보냅니다. 토큰을 붙인 요청은 토큰 주인이 로그인한 것과 똑같이 처리됩니다.\n- **HTTPS 로만** 받습니다. http 로 보내면 토큰이 맞아도 401 입니다(`localhost`·`127.0.0.1`·`*.localhost` 는 예외).\n- Bearer 헤더가 있으면 토큰으로만 판단합니다. 세션 쿠키가 같이 와도 보지 않습니다.\n- **브라우저 로그인 전용** API(계정 변경, 토큰 발급·갱신·폐기)는 토큰으로 부르면 403 입니다.\n\n```bash\ncurl -H \"Authorization: Bearer $IWORKS_API_TOKEN\" https://iworks.bizblue.co.kr/api/me.php\n```\n\n## 이 페이지에서 Try it out\n운영 데이터라 이 페이지에서는 **조회(GET)만** 보내 볼 수 있습니다. 쓰기 API 는 curl·스크립트로 부르세요.\n\n이 페이지는 포털과 같은 주소라 브라우저의 로그인 쿠키가 함께 갑니다. **Authorize** 에 토큰을 넣지 않아도 내 계정으로 호출됩니다. 토큰을 넣으면 토큰으로만 판단하므로 브라우저 로그인 전용 API 는 403 이 됩니다.\n\n## 오류 응답 모양\n모듈마다 다릅니다.\n- `api/*.php`: `{\"error\": \"…\"}`\n- EnvHub(`access/*.php`): `{\"ok\": false, \"error\": \"…\"}`\n- TaskSheet(`schooltodo/api.php`): `{\"detail\": \"…\"}`"
  },
  "servers": [
    {
      "url": "..",
      "description": "이 포털 (문서 페이지 기준 상대 경로)"
    }
  ],
  "tags": [
    {
      "name": "계정",
      "description": "내 계정 정보와 개인 API 토큰"
    },
    {
      "name": "EnvHub",
      "description": "Coursemos 대학별 서버·접속 정보. **응답에 비밀번호 등 접속 정보가 평문으로 들어 있습니다.** 로그에 남기거나 다른 곳에 붙여 넣지 마세요."
    },
    {
      "name": "TaskSheet",
      "description": "대학별 일괄 할일 시트. 모든 경로가 `schooltodo/api.php` 한 파일이고, 경로는 `p` 쿼리로 넘깁니다.\n\n```\nschooltodo/api.php?p=/sheets/1/versions&ver=4.5\n```\n\n경로에 시트 번호 등이 들어가는 API 는 `p` 칸에 실제 경로를 그대로 적어 호출합니다(예: `/sheets/1`).\n\n상태는 한글 `미조치`·`진행중`·`개발반영`·`운영반영`·`해당없음` 다섯 가지입니다. `versions` 와 `PUT status` 만 영문 코드(`todo`·`in_progress`·`dev_applied`·`prod_applied`·`not_applicable`)를 받고 돌려줍니다."
    }
  ],
  "paths": {
    "/api/me.php": {
      "get": {
        "tags": ["계정"],
        "summary": "내 계정 조회",
        "description": "이 토큰(또는 로그인 세션)이 누구 것인지 확인합니다.",
        "operationId": "getMe",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "responses": {
          "200": {
            "description": "내 계정",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/Me"}
              }
            }
          },
          "401": {"$ref": "#/components/responses/PortalUnauthorized"}
        }
      },
      "put": {
        "tags": ["계정"],
        "summary": "내 계정 수정 (브라우저 로그인 전용)",
        "description": "**브라우저 로그인 전용, 토큰으로 부르면 403.** 토큰이 새도 비밀번호 등 계정을 바꿀 수 없게 막아 두었습니다.\n\n보낸 칸만 고칩니다. 비밀번호를 바꾸면 내 API 토큰이 폐기됩니다.",
        "operationId": "putMe",
        "security": [{"sessionCookie": []}],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "properties": {
                  "name": {"type": "string", "description": "이름. 빈 값이면 그대로 둡니다."},
                  "email": {"type": "string", "description": "로그인 이메일. 빈 값이면 그대로 둡니다."},
                  "password": {"type": "string", "description": "새 비밀번호. 바꾸면 API 토큰이 폐기됩니다."},
                  "slack_token": {"type": "string", "description": "슬랙 연동 토큰. 빈 값이면 그대로 둡니다."},
                  "slack_email": {"type": "string", "description": "슬랙 계정 이메일. 빈 값이면 지웁니다. 로그인 이메일과 같으면 비워 둡니다."},
                  "gmail_user": {"type": "string", "description": "Gmail 주소. 빈 값이면 연동을 끊고 앱 비밀번호도 지웁니다."},
                  "gmail_pass": {"type": "string", "description": "Gmail 앱 비밀번호. 빈 값이면 그대로 둡니다."},
                  "bg_pref": {"type": "string", "enum": ["weather", "plain"], "description": "대시보드 배경"},
                  "tile_order": {"type": "array", "items": {"type": "string"}, "description": "업무 시스템 카드 key 순서. 빈 배열이면 기본 순서"},
                  "tile_collapsed": {"type": "array", "items": {"type": "integer"}, "description": "접어 둔 카드 묶음 id"},
                  "color": {"type": "string", "pattern": "^#[0-9a-fA-F]{6}$", "description": "고유색"}
                }
              },
              "example": {"name": "홍길동"}
            }
          }
        },
        "responses": {
          "200": {
            "description": "고친 뒤의 내 계정. GET 과 달리 `has_api_token` 은 없습니다.",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/MeUpdated"}
              }
            }
          },
          "400": {
            "description": "형식 오류(슬랙 이메일, Gmail 주소, bg_pref, color)",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/PortalError"},
                "example": {"error": "색상 형식이 올바르지 않습니다 (#rrggbb)"}
              }
            }
          },
          "401": {"$ref": "#/components/responses/PortalUnauthorized"},
          "403": {"$ref": "#/components/responses/SessionOnly"},
          "409": {
            "description": "이미 쓰는 이메일",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/PortalError"},
                "example": {"error": "이미 사용 중인 이메일입니다"}
              }
            }
          }
        }
      }
    },
    "/api/token.php": {
      "get": {
        "tags": ["계정"],
        "summary": "내 API 토큰 보기 (브라우저 로그인 전용)",
        "description": "**브라우저 로그인 전용, 토큰으로 부르면 403.** 마이페이지 API 토큰 칸이 씁니다.",
        "operationId": "getToken",
        "security": [{"sessionCookie": []}],
        "responses": {
          "200": {
            "description": "토큰이 없으면 `has` 가 false 이고 `token` 은 빈 문자열입니다.",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/TokenInfo"}
              }
            }
          },
          "401": {"$ref": "#/components/responses/PortalUnauthorized"},
          "403": {"$ref": "#/components/responses/SessionOnly"}
        }
      },
      "post": {
        "tags": ["계정"],
        "summary": "API 토큰 발급·갱신 (브라우저 로그인 전용)",
        "description": "**브라우저 로그인 전용, 토큰으로 부르면 403.**\n\n- `ensure`: 내 토큰을 돌려줍니다. 없으면 새로 발급합니다.\n- `rotate`: 새 값으로 바꿉니다. 이전 값은 곧바로 무효입니다.",
        "operationId": "postToken",
        "security": [{"sessionCookie": []}],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "required": ["action"],
                "properties": {
                  "action": {"type": "string", "enum": ["ensure", "rotate"]}
                }
              },
              "example": {"action": "ensure"}
            }
          }
        },
        "responses": {
          "200": {
            "description": "내 토큰",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "ok": {"type": "boolean"},
                    "token": {"type": "string"}
                  }
                },
                "example": {"ok": true, "token": "iwk_0000000000000000000000000000000000000000000000000000000000000000"}
              }
            }
          },
          "400": {
            "description": "action 이 ensure·rotate 가 아님",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/OkError"},
                "example": {"ok": false, "error": "알 수 없는 action"}
              }
            }
          },
          "401": {"$ref": "#/components/responses/PortalUnauthorized"},
          "403": {"$ref": "#/components/responses/SessionOnly"}
        }
      },
      "delete": {
        "tags": ["계정"],
        "summary": "API 토큰 폐기 (브라우저 로그인 전용)",
        "description": "**브라우저 로그인 전용, 토큰으로 부르면 403.** 폐기하면 그 토큰을 쓰던 도구가 모두 401 을 받습니다.",
        "operationId": "deleteToken",
        "security": [{"sessionCookie": []}],
        "responses": {
          "200": {
            "description": "폐기함",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "ok": {"type": "boolean"}
                  }
                },
                "example": {"ok": true}
              }
            }
          },
          "401": {"$ref": "#/components/responses/PortalUnauthorized"},
          "403": {"$ref": "#/components/responses/SessionOnly"}
        }
      }
    },
    "/access/access_api.php": {
      "get": {
        "tags": ["EnvHub"],
        "summary": "대학별 접속 정보 전체",
        "description": "EnvHub 화면이 쓰는 목록입니다. 대학(schools)마다 접속 정보(school_access)를 붙여 내려줍니다. 접속 정보가 없는 대학도 나오며 그때는 `access_id` 가 0, 접속 정보 칸은 빈 문자열입니다. 접속 정보가 두 벌인 대학은 두 행으로 나옵니다.\n\n**응답에 서버·DB 비밀번호가 복호화된 평문으로 들어 있습니다.** 이름·버전·저장소·테스트 서버만 필요하면 `access/api_schools.php` 를 쓰세요.\n\n`VPN 접속 방법`·`배포 방법`·`비고`·`메모`·`DB` 칸은 Editor.js 블록 JSON 이거나 평문입니다.\n\n조회 중 오류가 나면 HTTP 200 에 `{\"ok\": false, \"error\": \"…\"}` 로 옵니다.",
        "operationId": "listAccess",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [
          {
            "name": "all",
            "in": "query",
            "description": "있으면 사용하지 않는 대학(schools.active=0)도 넣습니다. 값은 보지 않습니다(`all=0` 도 포함).",
            "schema": {"type": "string"},
            "example": "1"
          }
        ],
        "responses": {
          "200": {
            "description": "버전, 이름 순",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "ok": {"type": "boolean"},
                    "rows": {"type": "array", "items": {"$ref": "#/components/schemas/AccessRow"}},
                    "error": {"type": "string", "description": "ok 가 false 일 때만"}
                  }
                }
              }
            }
          },
          "401": {"$ref": "#/components/responses/EnvhubUnauthorized"}
        }
      }
    },
    "/access/api_schools.php": {
      "get": {
        "tags": ["EnvHub"],
        "summary": "대학 이름·버전·저장소·테스트 서버",
        "description": "도구용 조회 API 입니다. 대학마다 이름·버전·저장소 주소와 테스트 서버 접속 정보만 내려줍니다.\n\n**응답에 테스트 서버 비밀번호가 평문으로 들어 있습니다.** 그래서 세션으로 불러도 HTTPS 가 아니면 403 입니다(localhost 예외).\n\n필터는 함께 쓰면 모두 만족하는 대학만 나옵니다.",
        "operationId": "listSchools",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [
          {
            "name": "all",
            "in": "query",
            "description": "있으면 사용하지 않는 대학(schools.active=0)도 넣습니다. 값은 보지 않습니다(`all=0` 도 포함).",
            "schema": {"type": "string"},
            "example": "1"
          },
          {
            "name": "ver",
            "in": "query",
            "description": "버전이 정확히 같은 대학만",
            "schema": {"type": "string"},
            "example": "4.5"
          },
          {
            "name": "q",
            "in": "query",
            "description": "대학명에 이 글자가 들어간 대학만",
            "schema": {"type": "string"},
            "example": "예시"
          },
          {
            "name": "school_id",
            "in": "query",
            "description": "이 대학(schools.id)만",
            "schema": {"type": "integer"},
            "example": 12
          }
        ],
        "responses": {
          "200": {
            "description": "버전, 이름 순",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "ok": {"type": "boolean"},
                    "count": {"type": "integer"},
                    "rows": {"type": "array", "items": {"$ref": "#/components/schemas/SchoolApiRow"}}
                  }
                },
                "example": {
                  "ok": true,
                  "count": 1,
                  "rows": [
                    {
                      "name": "예시대학교",
                      "ver": "4.5",
                      "repo": "https://git.example.com/example-lms.git",
                      "test_server": {
                        "ip": "192.0.2.10",
                        "port": 2222,
                        "username": "example-user",
                        "password": "example-password",
                        "project_root": "/var/www/example"
                      }
                    }
                  ]
                }
              }
            }
          },
          "401": {
            "description": "로그인·토큰 없음 또는 틀린 토큰. `WWW-Authenticate: Bearer` 헤더가 붙습니다.",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/OkError"},
                "example": {"ok": false, "error": "로그인하거나 개인 API 토큰을 보내 주세요"}
              }
            }
          },
          "403": {
            "description": "HTTPS 가 아님",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/OkError"},
                "example": {"ok": false, "error": "HTTPS 로만 호출할 수 있습니다"}
              }
            }
          },
          "405": {
            "description": "GET 이 아님",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/OkError"},
                "example": {"ok": false, "error": "GET 만 지원합니다"}
              }
            }
          },
          "500": {
            "description": "조회 실패",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/OkError"},
                "example": {"ok": false, "error": "조회 중 오류가 났습니다"}
              }
            }
          }
        }
      }
    },
    "/schooltodo/api.php?p=/whoami": {
      "get": {
        "tags": ["TaskSheet"],
        "summary": "로그인 사용자·상태 목록",
        "description": "로그인하지 않아도 200 입니다. 그때는 `email`·`name` 이 null 입니다. 토큰이 맞는지 확인하려면 `api/me.php` 를 쓰세요.",
        "operationId": "schooltodoWhoami",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}, {}],
        "responses": {
          "200": {
            "description": "로그인 사용자와 쓸 수 있는 상태",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "email": {"type": "string", "nullable": true},
                    "name": {"type": "string", "nullable": true},
                    "statuses": {"type": "array", "items": {"$ref": "#/components/schemas/StatusKo"}},
                    "portal_url": {"type": "string"}
                  }
                },
                "example": {"email": "user@example.com", "name": "홍길동", "statuses": ["미조치", "진행중", "개발반영", "운영반영", "해당없음"], "portal_url": ".."}
              }
            }
          }
        }
      }
    },
    "/schooltodo/api.php?p=/sheets": {
      "get": {
        "tags": ["TaskSheet"],
        "summary": "시트 목록",
        "description": "시트마다 상태별 대학 수를 함께 줍니다. 대학 수는 사용 중인 대학(schools.active=1) 기준입니다. 보관한 시트는 `all=1` 일 때만 나옵니다.",
        "operationId": "schooltodoSheets",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [
          {
            "name": "all",
            "in": "query",
            "description": "1 이면 보관한 시트도 넣습니다. 0 이나 빈 값이면 뺍니다.",
            "schema": {"type": "string"},
            "example": "1"
          }
        ],
        "responses": {
          "200": {
            "description": "id 순",
            "content": {
              "application/json": {
                "schema": {"type": "array", "items": {"$ref": "#/components/schemas/Sheet"}}
              }
            }
          },
          "401": {"$ref": "#/components/responses/SchooltodoUnauthorized"}
        }
      },
      "post": {
        "tags": ["TaskSheet"],
        "summary": "시트 만들기",
        "description": "만든 사람(`created_by`)은 호출한 계정 이름입니다.",
        "operationId": "schooltodoSheetCreate",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "required": ["title"],
                "properties": {
                  "title": {"type": "string", "maxLength": 200, "description": "시트 이름. 같은 이름이 있으면 409"},
                  "description": {"type": "string", "maxLength": 2000}
                }
              },
              "example": {"title": "예시 보안 패치", "description": "대학별 적용 현황"}
            }
          }
        },
        "responses": {
          "201": {
            "description": "만든 시트",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/SheetDetail"}
              }
            }
          },
          "401": {"$ref": "#/components/responses/SchooltodoUnauthorized"},
          "409": {"$ref": "#/components/responses/SchooltodoConflict"},
          "422": {"$ref": "#/components/responses/SchooltodoInvalid"}
        }
      }
    },
    "/schooltodo/api.php?p=/sheets/:id": {
      "get": {
        "tags": ["TaskSheet"],
        "summary": "시트 상세 (대학별 행)",
        "description": "사용 중인 대학마다 한 행씩 줍니다. 상태를 바꾼 적이 없는 대학은 `미조치` 입니다.\n\n`status` 로 거르면 `rows` 만 줄고 `total`·`counts` 는 시트 전체 기준 그대로입니다.",
        "operationId": "schooltodoSheet",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [
          {
            "name": "p",
            "in": "query",
            "required": true,
            "description": "`/sheets/{시트 id}`",
            "schema": {"type": "string", "pattern": "^/sheets/[0-9]+$"},
            "example": "/sheets/1"
          },
          {
            "name": "status",
            "in": "query",
            "description": "이 상태인 대학만. 한글 상태만 받습니다.",
            "schema": {"$ref": "#/components/schemas/StatusKo"}
          }
        ],
        "responses": {
          "200": {
            "description": "시트와 대학별 행. 대학 이름, 버전 순",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/SheetDetail"}
              }
            }
          },
          "401": {"$ref": "#/components/responses/SchooltodoUnauthorized"},
          "404": {"$ref": "#/components/responses/SchooltodoNotFound"},
          "422": {"$ref": "#/components/responses/SchooltodoInvalid"}
        }
      },
      "put": {
        "tags": ["TaskSheet"],
        "summary": "시트 이름·설명 수정, 보관",
        "description": "보낸 칸만 고칩니다. `archived: true` 면 보관(보관 시각 기록, 이미 보관했으면 시각 유지), `false` 면 꺼냅니다.",
        "operationId": "schooltodoSheetUpdate",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [
          {
            "name": "p",
            "in": "query",
            "required": true,
            "description": "`/sheets/{시트 id}`",
            "schema": {"type": "string", "pattern": "^/sheets/[0-9]+$"},
            "example": "/sheets/1"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "properties": {
                  "title": {"type": "string", "maxLength": 200},
                  "description": {"type": "string", "maxLength": 2000},
                  "archived": {"type": "boolean"}
                }
              },
              "example": {"archived": true}
            }
          }
        },
        "responses": {
          "200": {
            "description": "고친 시트",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/SheetDetail"}
              }
            }
          },
          "401": {"$ref": "#/components/responses/SchooltodoUnauthorized"},
          "404": {"$ref": "#/components/responses/SchooltodoNotFound"},
          "409": {"$ref": "#/components/responses/SchooltodoConflict"},
          "422": {"$ref": "#/components/responses/SchooltodoInvalid"}
        }
      }
    },
    "/schooltodo/api.php?p=/sheets/:id/cells": {
      "put": {
        "tags": ["TaskSheet"],
        "summary": "대학별 상태·메모 채우기",
        "description": "화면이 쓰는 셀 저장 API 입니다. 상태는 **한글만** 받습니다(영문 코드는 `PUT status` 를 쓰세요).\n\n- 대학은 `school_id`, 또는 `school`(대학명)으로 지정합니다. 같은 이름이 여러 버전이면 `ver` 를 함께 보냅니다(없으면 409).\n- 항목마다 `status`·`note` 중 하나는 있어야 합니다. 보내지 않은 칸은 그대로 둡니다.\n- 한 건이라도 틀리면 전부 되돌립니다. 오류 메시지 앞에 `items[번호]:` 가 붙습니다.\n- 값이 바뀌면 이전 값을 이력에 남깁니다(셀마다 최근 10건).",
        "operationId": "schooltodoCellsFill",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [
          {
            "name": "p",
            "in": "query",
            "required": true,
            "description": "`/sheets/{시트 id}/cells`",
            "schema": {"type": "string", "pattern": "^/sheets/[0-9]+/cells$"},
            "example": "/sheets/1/cells"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "required": ["items"],
                "properties": {
                  "items": {
                    "type": "array",
                    "minItems": 1,
                    "items": {
                      "type": "object",
                      "properties": {
                        "school_id": {"type": "integer"},
                        "school": {"type": "string", "description": "대학명. school_id 가 없을 때"},
                        "ver": {"type": "string", "description": "school 과 함께 버전을 좁힐 때"},
                        "status": {"$ref": "#/components/schemas/StatusKo"},
                        "note": {"type": "string", "maxLength": 2000}
                      }
                    }
                  }
                }
              },
              "example": {"items": [{"school_id": 12, "status": "진행중", "note": "개발서버 확인 중"}]}
            }
          }
        },
        "responses": {
          "200": {
            "description": "보낸 대학들의 지금 행. `updated` 는 보낸 대학 수이고, 값이 같아 바뀌지 않은 대학도 셉니다.",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "updated": {"type": "integer"},
                    "rows": {"type": "array", "items": {"$ref": "#/components/schemas/SheetRow"}}
                  }
                }
              }
            }
          },
          "401": {"$ref": "#/components/responses/SchooltodoUnauthorized"},
          "404": {"$ref": "#/components/responses/SchooltodoNotFound"},
          "409": {"$ref": "#/components/responses/SchooltodoConflict"},
          "422": {"$ref": "#/components/responses/SchooltodoInvalid"}
        }
      }
    },
    "/schooltodo/api.php?p=/sheets/:id/cells/:school_id/history": {
      "get": {
        "tags": ["TaskSheet"],
        "summary": "셀 이력",
        "description": "그 대학 셀이 바뀌기 전 값들입니다. 최근 것부터, 최대 10건입니다.",
        "operationId": "schooltodoCellHistory",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [
          {
            "name": "p",
            "in": "query",
            "required": true,
            "description": "`/sheets/{시트 id}/cells/{schools.id}/history`",
            "schema": {"type": "string", "pattern": "^/sheets/[0-9]+/cells/[0-9]+/history$"},
            "example": "/sheets/1/cells/12/history"
          }
        ],
        "responses": {
          "200": {
            "description": "이력",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "school_id": {"type": "integer"},
                    "history": {
                      "type": "array",
                      "items": {
                        "type": "object",
                        "properties": {
                          "status": {"$ref": "#/components/schemas/StatusKo"},
                          "note": {"type": "string"},
                          "updated_by": {"type": "string"},
                          "updated_at": {"type": "string", "example": "2026-11-25 09:00:00"}
                        }
                      }
                    }
                  }
                }
              }
            }
          },
          "401": {"$ref": "#/components/responses/SchooltodoUnauthorized"},
          "404": {"$ref": "#/components/responses/SchooltodoNotFound"}
        }
      }
    },
    "/schooltodo/api.php?p=/sheets/:id/versions": {
      "get": {
        "tags": ["TaskSheet"],
        "summary": "버전별 대학 상태",
        "description": "한 시트의 대학별 상태·갱신 날짜를 버전별로 묶어 줍니다. 상태와 개수 키는 **영문 코드**이고 한글 이름은 `status_label` 에 있습니다.\n\n- 버전은 숫자 순(2.9 → 3.2 → … → 4.5)이고 버전이 빈 대학은 맨 뒤입니다.\n- `status` 로 걸러도 버전 묶음은 남고 `schools` 만 비며, 각 버전의 `total`·`counts` 는 거르기 전 기준입니다.\n- 맨 위 `total`·`counts` 는 `ver`·`status` 와 상관없이 시트 전체 기준입니다.",
        "operationId": "schooltodoVersions",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [
          {
            "name": "p",
            "in": "query",
            "required": true,
            "description": "`/sheets/{시트 id}/versions`",
            "schema": {"type": "string", "pattern": "^/sheets/[0-9]+/versions$"},
            "example": "/sheets/1/versions"
          },
          {
            "name": "ver",
            "in": "query",
            "description": "이 버전만",
            "schema": {"type": "string", "maxLength": 20},
            "example": "4.5"
          },
          {
            "name": "status",
            "in": "query",
            "description": "이 상태인 대학만. 영문 코드·한글 둘 다 받습니다.",
            "schema": {"type": "string", "enum": ["todo", "in_progress", "dev_applied", "prod_applied", "not_applicable", "미조치", "진행중", "개발반영", "운영반영", "해당없음"]}
          }
        ],
        "responses": {
          "200": {
            "description": "버전별 묶음",
            "content": {
              "application/json": {
                "schema": {"$ref": "#/components/schemas/VersionsResult"},
                "example": {
                  "id": 1,
                  "title": "예시 보안 패치",
                  "description": "",
                  "archived_at": "",
                  "total": 2,
                  "counts": {"todo": 1, "in_progress": 0, "dev_applied": 0, "prod_applied": 1, "not_applicable": 0},
                  "versions": [
                    {
                      "ver": "4.5",
                      "total": 2,
                      "counts": {"todo": 1, "in_progress": 0, "dev_applied": 0, "prod_applied": 1, "not_applicable": 0},
                      "schools": [
                        {"school_id": 12, "name": "예시대학교", "code": "example", "status": "prod_applied", "status_label": "운영반영", "updated_at": "2026-11-25 09:00:00", "updated_by": "홍길동"},
                        {"school_id": 13, "name": "샘플대학교", "code": "sample", "status": "todo", "status_label": "미조치", "updated_at": "", "updated_by": ""}
                      ]
                    }
                  ]
                }
              }
            }
          },
          "401": {"$ref": "#/components/responses/SchooltodoUnauthorized"},
          "404": {"$ref": "#/components/responses/SchooltodoNotFound"},
          "422": {"$ref": "#/components/responses/SchooltodoInvalid"}
        }
      }
    },
    "/schooltodo/api.php?p=/sheets/:id/status": {
      "put": {
        "tags": ["TaskSheet"],
        "summary": "대학별 상태 변경 (도구용)",
        "description": "배포 스크립트·스킬이 조치 상태를 바꿀 때 씁니다. `PUT cells` 와 같지만 `status` 가 꼭 있어야 하고 영문 코드·한글 둘 다 받으며, 응답 상태는 영문 코드입니다.\n\n- 대학은 `school_id`, 또는 `school`(대학명)으로 지정합니다. 같은 이름이 여러 버전이면 `ver` 를 함께 보냅니다(없으면 409).\n- 한 건이라도 틀리면 전부 되돌립니다. 오류 메시지 앞에 `items[번호]:` 가 붙습니다.\n- 갱신한 사람(`updated_by`)은 호출한 계정 이름입니다.",
        "operationId": "schooltodoStatusChange",
        "security": [{"bearerAuth": []}, {"sessionCookie": []}],
        "parameters": [
          {
            "name": "p",
            "in": "query",
            "required": true,
            "description": "`/sheets/{시트 id}/status`",
            "schema": {"type": "string", "pattern": "^/sheets/[0-9]+/status$"},
            "example": "/sheets/1/status"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "required": ["items"],
                "properties": {
                  "items": {
                    "type": "array",
                    "minItems": 1,
                    "items": {
                      "type": "object",
                      "required": ["status"],
                      "properties": {
                        "school_id": {"type": "integer"},
                        "school": {"type": "string", "description": "대학명. school_id 가 없을 때"},
                        "ver": {"type": "string", "description": "school 과 함께 버전을 좁힐 때"},
                        "status": {"type": "string", "enum": ["todo", "in_progress", "dev_applied", "prod_applied", "not_applicable", "미조치", "진행중", "개발반영", "운영반영", "해당없음"]},
                        "note": {"type": "string", "maxLength": 2000, "description": "보내지 않으면 메모는 그대로"}
                      }
                    }
                  }
                }
              },
              "example": {"items": [{"school": "예시대학교", "ver": "4.5", "status": "dev_applied", "note": "r1234"}]}
            }
          }
        },
        "responses": {
          "200": {
            "description": "보낸 대학들의 지금 상태. `updated` 는 보낸 대학 수이고, 값이 같아 바뀌지 않은 대학도 셉니다.",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "updated": {"type": "integer"},
                    "schools": {
                      "type": "array",
                      "items": {
                        "type": "object",
                        "properties": {
                          "school_id": {"type": "integer"},
                          "name": {"type": "string"},
                          "ver": {"type": "string"},
                          "code": {"type": "string"},
                          "status": {"$ref": "#/components/schemas/StatusCode"},
                          "status_label": {"$ref": "#/components/schemas/StatusKo"},
                          "note": {"type": "string"},
                          "updated_at": {"type": "string"},
                          "updated_by": {"type": "string"}
                        }
                      }
                    }
                  }
                },
                "example": {
                  "updated": 1,
                  "schools": [
                    {"school_id": 12, "name": "예시대학교", "ver": "4.5", "code": "example", "status": "dev_applied", "status_label": "개발반영", "note": "r1234", "updated_at": "2026-11-25 09:00:00", "updated_by": "홍길동"}
                  ]
                }
              }
            }
          },
          "401": {"$ref": "#/components/responses/SchooltodoUnauthorized"},
          "404": {"$ref": "#/components/responses/SchooltodoNotFound"},
          "409": {"$ref": "#/components/responses/SchooltodoConflict"},
          "422": {"$ref": "#/components/responses/SchooltodoInvalid"}
        }
      }
    }
  },
  "components": {
    "securitySchemes": {
      "bearerAuth": {
        "type": "http",
        "scheme": "bearer",
        "bearerFormat": "iwk_<64자리 16진수>",
        "description": "마이페이지 → API 토큰에서 받은 개인 토큰. HTTPS 로만 받습니다."
      },
      "sessionCookie": {
        "type": "apiKey",
        "in": "cookie",
        "name": "BLUEIWORK_SESSID",
        "description": "포털 로그인 세션. 이 페이지에서는 브라우저가 알아서 보냅니다."
      }
    },
    "responses": {
      "PortalUnauthorized": {
        "description": "로그인·토큰 없음, 틀린 토큰, http 로 보낸 토큰",
        "content": {
          "application/json": {
            "schema": {"$ref": "#/components/schemas/PortalError"},
            "example": {"error": "로그인이 필요합니다"}
          }
        }
      },
      "SessionOnly": {
        "description": "토큰으로 부름. 브라우저 로그인으로만 할 수 있습니다.",
        "content": {
          "application/json": {
            "schema": {"$ref": "#/components/schemas/PortalError"},
            "example": {"error": "이 작업은 브라우저 로그인으로만 할 수 있습니다"}
          }
        }
      },
      "EnvhubUnauthorized": {
        "description": "로그인·토큰 없음, 틀린 토큰, http 로 보낸 토큰",
        "content": {
          "application/json": {
            "schema": {"$ref": "#/components/schemas/OkError"},
            "example": {"ok": false, "error": "로그인이 필요합니다"}
          }
        }
      },
      "SchooltodoUnauthorized": {
        "description": "로그인·토큰 없음, 틀린 토큰, http 로 보낸 토큰",
        "content": {
          "application/json": {
            "schema": {"$ref": "#/components/schemas/SchooltodoError"},
            "example": {"detail": "로그인이 필요합니다"}
          }
        }
      },
      "SchooltodoNotFound": {
        "description": "없는 시트·대학 또는 없는 경로",
        "content": {
          "application/json": {
            "schema": {"$ref": "#/components/schemas/SchooltodoError"},
            "example": {"detail": "없는 시트입니다"}
          }
        }
      },
      "SchooltodoConflict": {
        "description": "같은 이름의 시트가 있음, 또는 대학명이 여러 버전이라 ver 가 필요함",
        "content": {
          "application/json": {
            "schema": {"$ref": "#/components/schemas/SchooltodoError"},
            "example": {"detail": "items[0]: 예시대학교 이(가) 여러 개입니다. ver 를 지정해 주세요 (3.2, 4.5)"}
          }
        }
      },
      "SchooltodoInvalid": {
        "description": "본문을 JSON 으로 읽을 수 없거나 값이 틀림",
        "content": {
          "application/json": {
            "schema": {"$ref": "#/components/schemas/SchooltodoError"},
            "example": {"detail": "status 는 todo, in_progress, dev_applied, prod_applied, not_applicable 중 하나여야 합니다"}
          }
        }
      }
    },
    "schemas": {
      "PortalError": {
        "type": "object",
        "properties": {
          "error": {"type": "string"}
        }
      },
      "OkError": {
        "type": "object",
        "properties": {
          "ok": {"type": "boolean", "example": false},
          "error": {"type": "string"}
        }
      },
      "SchooltodoError": {
        "type": "object",
        "properties": {
          "detail": {"type": "string"}
        }
      },
      "MeUpdated": {
        "type": "object",
        "properties": {
          "name": {"type": "string"},
          "email": {"type": "string"},
          "has_token": {"type": "boolean", "description": "슬랙 연동 토큰을 저장했는가"},
          "slack_email": {"type": "string", "nullable": true, "description": "로그인 이메일과 다른 슬랙 계정 이메일"},
          "gmail_user": {"type": "string", "nullable": true},
          "has_gmail_pass": {"type": "boolean"},
          "needs_setup": {"type": "boolean", "description": "아직 초기 비밀번호 그대로인가"},
          "color": {"type": "string", "description": "고유색. `#rrggbb`, 저장한 색이 없으면 `hsl(…)`"},
          "bg_pref": {"type": "string", "enum": ["weather", "plain"]}
        }
      },
      "Me": {
        "allOf": [
          {"$ref": "#/components/schemas/MeUpdated"},
          {
            "type": "object",
            "properties": {
              "has_api_token": {"type": "boolean", "description": "개인 API 토큰을 발급받았는가"}
            }
          }
        ],
        "example": {
          "name": "홍길동",
          "email": "user@example.com",
          "has_token": true,
          "slack_email": null,
          "gmail_user": null,
          "has_gmail_pass": false,
          "needs_setup": false,
          "color": "#3b82f6",
          "bg_pref": "weather",
          "has_api_token": true
        }
      },
      "TokenInfo": {
        "type": "object",
        "properties": {
          "ok": {"type": "boolean"},
          "has": {"type": "boolean"},
          "token": {"type": "string"},
          "created_at": {"type": "string", "nullable": true},
          "rotated_at": {"type": "string", "nullable": true},
          "last_used_at": {"type": "string", "nullable": true},
          "last_used_ip": {"type": "string", "nullable": true}
        },
        "example": {
          "ok": true,
          "has": true,
          "token": "iwk_0000000000000000000000000000000000000000000000000000000000000000",
          "created_at": "2026-11-25 09:00:00",
          "rotated_at": null,
          "last_used_at": "2026-11-25 10:00:00",
          "last_used_ip": "192.0.2.1"
        }
      },
      "AccessRow": {
        "type": "object",
        "description": "대학(schools) 칸과 접속 정보(school_access) 칸. 접속 정보 칸은 모두 문자열이고 없으면 빈 문자열입니다.",
        "properties": {
          "school_id": {"type": "integer"},
          "name": {"type": "string"},
          "ver": {"type": "string"},
          "dev": {"type": "string", "description": "개발 URL"},
          "ops": {"type": "string", "description": "운영 URL"},
          "active": {"type": "integer", "enum": [0, 1]},
          "access_id": {"type": "integer", "description": "접속 정보 id. 없으면 0"},
          "os": {"type": "string", "description": "테스트 서버 OS"},
          "ops_os": {"type": "string", "description": "운영 서버 OS"},
          "ops_cnt": {"type": "string", "description": "운영 서버 대수"},
          "opened": {"type": "string", "description": "최초 오픈"},
          "vpn": {"type": "string", "description": "VPN 프로그램"},
          "vpn_note": {"type": "string", "description": "VPN 접속 방법"},
          "repo": {"type": "string", "description": "svn / git 주소"},
          "test_srv": {"type": "string", "description": "테스트 서버 정보(평문). 줄마다 `아이피:포트`, `아이디 / 비밀번호`, 프로젝트 폴더"},
          "deploy": {"type": "string", "description": "배포 방법(평문)"},
          "note": {"type": "string", "description": "비고(평문)"},
          "login_dev_id": {"type": "string", "description": "개발 사이트 아이디"},
          "login_dev": {"type": "string", "description": "개발 사이트 비밀번호(평문)"},
          "dev_note": {"type": "string", "description": "개발 메모"},
          "dev_db": {"type": "string", "description": "개발 DB 접속 정보(평문)"},
          "login_ops_id": {"type": "string", "description": "운영 사이트 아이디"},
          "login_ops": {"type": "string", "description": "운영 사이트 비밀번호(평문)"},
          "ops_sso": {"type": "string", "description": "SSO 제품"},
          "ops_note": {"type": "string", "description": "운영 메모"},
          "ops_db": {"type": "string", "description": "운영 DB 접속 정보(평문)"},
          "haksa_db": {"type": "string", "description": "학사 DB 접속 정보(평문)"}
        },
        "example": {
          "school_id": 12,
          "name": "예시대학교",
          "ver": "4.5",
          "dev": "https://dev.example.com",
          "ops": "https://lms.example.com",
          "active": 1,
          "access_id": 34,
          "os": "Rocky Linux 9",
          "ops_os": "",
          "ops_cnt": "2",
          "opened": "2024.03",
          "vpn": "",
          "vpn_note": "",
          "repo": "https://git.example.com/example-lms.git",
          "test_srv": "192.0.2.10:2222\nexample-user / example-password\n/var/www/example",
          "deploy": "",
          "note": "",
          "login_dev_id": "admin",
          "login_dev": "example-password",
          "dev_note": "",
          "dev_db": "",
          "login_ops_id": "admin",
          "login_ops": "example-password",
          "ops_sso": "",
          "ops_note": "",
          "ops_db": "",
          "haksa_db": ""
        }
      },
      "SchoolApiRow": {
        "type": "object",
        "properties": {
          "name": {"type": "string"},
          "ver": {"type": "string"},
          "repo": {"type": "string", "description": "svn / git 주소. 여러 줄일 수 있습니다."},
          "test_server": {
            "type": "object",
            "description": "테스트 서버 정보를 칸별로 나눈 것. 읽지 못한 칸은 빈 문자열(port 는 null)입니다.",
            "properties": {
              "ip": {"type": "string"},
              "port": {"type": "integer", "nullable": true, "description": "적지 않았으면 null. 기본값(22)은 쓰는 쪽이 정합니다."},
              "username": {"type": "string"},
              "password": {"type": "string", "description": "평문 비밀번호"},
              "project_root": {"type": "string"}
            }
          }
        }
      },
      "StatusKo": {
        "type": "string",
        "enum": ["미조치", "진행중", "개발반영", "운영반영", "해당없음"]
      },
      "StatusCode": {
        "type": "string",
        "enum": ["todo", "in_progress", "dev_applied", "prod_applied", "not_applicable"]
      },
      "CountsKo": {
        "type": "object",
        "description": "한글 상태별 대학 수",
        "properties": {
          "미조치": {"type": "integer"},
          "진행중": {"type": "integer"},
          "개발반영": {"type": "integer"},
          "운영반영": {"type": "integer"},
          "해당없음": {"type": "integer"}
        }
      },
      "CountsCode": {
        "type": "object",
        "description": "영문 상태 코드별 대학 수",
        "properties": {
          "todo": {"type": "integer"},
          "in_progress": {"type": "integer"},
          "dev_applied": {"type": "integer"},
          "prod_applied": {"type": "integer"},
          "not_applicable": {"type": "integer"}
        }
      },
      "Sheet": {
        "type": "object",
        "properties": {
          "id": {"type": "integer"},
          "title": {"type": "string"},
          "description": {"type": "string"},
          "created_by": {"type": "string"},
          "created_at": {"type": "string", "example": "2026-11-25 09:00:00"},
          "archived_at": {"type": "string", "description": "보관한 시각. 보관하지 않았으면 빈 문자열"},
          "total": {"type": "integer"},
          "counts": {"$ref": "#/components/schemas/CountsKo"}
        },
        "example": {
          "id": 1,
          "title": "예시 보안 패치",
          "description": "대학별 적용 현황",
          "created_by": "홍길동",
          "created_at": "2026-11-25 09:00:00",
          "archived_at": "",
          "total": 2,
          "counts": {"미조치": 1, "진행중": 0, "개발반영": 0, "운영반영": 1, "해당없음": 0}
        }
      },
      "SheetRow": {
        "type": "object",
        "properties": {
          "school_id": {"type": "integer"},
          "name": {"type": "string"},
          "code": {"type": "string", "description": "저장소 주소나 개발 URL 에서 뽑은 대학 코드. 못 뽑으면 빈 문자열"},
          "ver": {"type": "string"},
          "vpn": {"type": "string", "description": "EnvHub 의 VPN 프로그램"},
          "status": {"$ref": "#/components/schemas/StatusKo"},
          "note": {"type": "string"},
          "updated_by": {"type": "string"},
          "updated_at": {"type": "string"}
        },
        "example": {
          "school_id": 12,
          "name": "예시대학교",
          "code": "example",
          "ver": "4.5",
          "vpn": "",
          "status": "운영반영",
          "note": "",
          "updated_by": "홍길동",
          "updated_at": "2026-11-25 09:00:00"
        }
      },
      "SheetDetail": {
        "allOf": [
          {"$ref": "#/components/schemas/Sheet"},
          {
            "type": "object",
            "properties": {
              "rows": {"type": "array", "items": {"$ref": "#/components/schemas/SheetRow"}}
            }
          }
        ]
      },
      "VersionsResult": {
        "type": "object",
        "properties": {
          "id": {"type": "integer"},
          "title": {"type": "string"},
          "description": {"type": "string"},
          "archived_at": {"type": "string"},
          "total": {"type": "integer"},
          "counts": {"$ref": "#/components/schemas/CountsCode"},
          "versions": {
            "type": "array",
            "items": {
              "type": "object",
              "properties": {
                "ver": {"type": "string"},
                "total": {"type": "integer"},
                "counts": {"$ref": "#/components/schemas/CountsCode"},
                "schools": {
                  "type": "array",
                  "items": {
                    "type": "object",
                    "properties": {
                      "school_id": {"type": "integer"},
                      "name": {"type": "string"},
                      "code": {"type": "string"},
                      "status": {"$ref": "#/components/schemas/StatusCode"},
                      "status_label": {"$ref": "#/components/schemas/StatusKo"},
                      "updated_at": {"type": "string"},
                      "updated_by": {"type": "string"}
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
  }
}
JSON;
