<?php

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/members.php';
require_once __DIR__ . '/../lib/emotions.php';
require_once __DIR__ . '/../lib/topics.php';
require_once __DIR__ . '/../lib/presentations.php';
require_once __DIR__ . '/../lib/related.php';
require_once __DIR__ . '/../lib/score.php';
require_once __DIR__ . '/../lib/fields.php';
require_once __DIR__ . '/../lib/storage.php';
require_once __DIR__ . '/../lib/slots.php';
require_once __DIR__ . '/../lib/notify.php';

// 테스트는 실서버 DB 를 건드리지 않는다. 이름을 여기서 한 번만 정한다.
define('DTI_TEST_DB', getenv('DTI_TEST_DB') ?: 'slackapi_test');
define('DTI_TEST_TMP', sys_get_temp_dir() . '/dti-tests');

date_default_timezone_set('Asia/Seoul');
