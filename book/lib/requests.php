<?php
/**
 * 신청 읽기·쓰기. 행은 PDO 연관 배열이고 화면에 줄 때만 book_row_out() 으로 형을 맞춘다.
 *
 * PDO 는 값을 문자열로 준다. JS 에서 "0" 은 참이라 done·price 를 그대로 내보내면
 * 미처리 행이 완료로, 단가 없음이 0원으로 뒤집힌다 — 여기서 캐스팅한다.
 */

const BOOK_EDITABLE = ['title', 'author', 'publisher', 'gubun', 'price', 'done', 'note', 'kyobo_url', 'thumbnail'];

function book_row_out(array $r): array {
    return [
        'id' => (int)$r['id'],
        'year' => $r['year'] === null ? null : (int)$r['year'],
        'date' => (string)$r['date'],
        'applicant' => (string)$r['applicant'],
        'applicant_email' => (string)$r['applicant_email'],
        'gubun' => (string)$r['gubun'],
        'title' => (string)$r['title'],
        'author' => (string)$r['author'],
        'publisher' => (string)$r['publisher'],
        'price' => $r['price'] === null ? null : (int)$r['price'],
        'done' => (bool)(int)$r['done'],
        'note' => (string)$r['note'],
        'kyobo_url' => $r['kyobo_url'] === null ? null : (string)$r['kyobo_url'],
        'thumbnail' => $r['thumbnail'] === null ? null : (string)$r['thumbnail'],
        'created_at' => (string)$r['created_at'],
    ];
}

function book_requests_all(PDO $pdo): array {
    $rows = $pdo->query("SELECT * FROM book_requests ORDER BY `date` DESC, `id` DESC")->fetchAll();
    return array_map('book_row_out', $rows);
}

function book_request_find(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT * FROM book_requests WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new BookError('신청 내역을 찾을 수 없습니다', 404);
    return $row;
}

function book_year_of(string $date): ?int {
    return ctype_digit(substr($date, 0, 4)) ? (int)substr($date, 0, 4) : null;
}

/** 신청자는 항상 로그인한 본인 — 클라이언트가 보낸 applicant 는 보지 않는다 */
function book_request_create(array $ctx, array $body): array {
    $pdo = $ctx['pdo'];
    $date = book_want_date($body['date'] ?? '', '신청일');
    $title = book_want_str($body, 'title', '도서명', true);
    $author = book_want_str($body, 'author', '저자');
    $kyobo = book_want_url($body['kyobo_url'] ?? '', '교보문고 링크');
    if ($kyobo === '') $kyobo = book_kyobo_url($title, $author);

    $st = $pdo->prepare("INSERT INTO book_requests
        (`year`, `date`, `applicant`, `applicant_email`, `gubun`, `title`, `author`, `publisher`,
         `price`, `done`, `note`, `kyobo_url`, `thumbnail`, `created_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)");
    $st->execute([
        book_year_of($date),
        $date,
        $ctx['identity']['name'],
        $ctx['identity']['email'],
        book_want_one_of($body['gubun'] ?? BOOK_DEFAULT_GUBUN, BOOK_GUBUNS, '구분'),
        $title,
        $author,
        book_want_str($body, 'publisher', '출판사'),
        book_want_nullable_int($body, 'price', '도서단가'),
        book_want_str($body, 'note', '비고'),
        $kyobo !== '' ? $kyobo : null,
        book_want_str($body, 'thumbnail', '표지') ?: null,
        date('Y-m-d H:i:s'),
    ]);

    $rec = book_row_out(book_request_find($pdo, (int)$pdo->lastInsertId()));
    book_notify_slack($ctx['config']['slack_webhook'], $rec);
    return $rec;
}

/** 본문에 들어 있는 칸만 고친다(파이썬의 exclude_unset 과 같다). 완료 처리는 관리자만. */
function book_request_update(array $ctx, int $id, array $body): array {
    $pdo = $ctx['pdo'];
    $existing = book_request_find($pdo, $id);
    book_assert_can_edit($ctx, $existing, '수정');

    $changes = [];
    foreach (BOOK_EDITABLE as $key) {
        if (!array_key_exists($key, $body)) continue;
        $changes[$key] = match ($key) {
            'title' => book_want_str($body, 'title', '도서명', true),
            'gubun' => book_want_one_of($body['gubun'], BOOK_GUBUNS, '구분'),
            'price' => book_want_nullable_int($body, 'price', '도서단가'),
            'done' => book_flag($body['done']),
            'kyobo_url' => book_want_url($body['kyobo_url'], '교보문고 링크') ?: null,
            'thumbnail' => book_want_str($body, 'thumbnail', '표지') ?: null,
            default => book_want_str($body, $key, $key),
        };
    }
    if (isset($changes['done']) && !book_is_admin($ctx['config'], $ctx['identity']['email'])) {
        throw new BookError('완료 처리는 관리자만 할 수 있습니다', 403);
    }
    // 제목·저자가 바뀌었는데 링크를 따로 주지 않았으면 교보문고를 다시 찾는다
    if (!array_key_exists('kyobo_url', $changes) && (isset($changes['title']) || isset($changes['author']))) {
        $changes['kyobo_url'] = book_kyobo_url(
            $changes['title'] ?? $existing['title'],
            $changes['author'] ?? $existing['author']);
    }

    if ($changes) {
        $sets = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($changes)));
        $st = $pdo->prepare("UPDATE book_requests SET {$sets} WHERE id = ?");
        $st->execute([...array_values($changes), $id]);
    }
    return book_row_out(book_request_find($pdo, $id));
}

function book_request_delete(array $ctx, int $id): void {
    $existing = book_request_find($ctx['pdo'], $id);
    book_assert_can_edit($ctx, $existing, '삭제');
    $ctx['pdo']->prepare("DELETE FROM book_requests WHERE id = ?")->execute([$id]);
}

function book_assert_can_edit(array $ctx, array $row, string $what): void {
    $email = $ctx['identity']['email'];
    $owner = $row['applicant_email'] !== '' && strcasecmp($row['applicant_email'], $email) === 0;
    if (!$owner && !book_is_admin($ctx['config'], $email)) {
        throw new BookError("본인이 작성한 신청만 {$what}할 수 있습니다", 403);
    }
}
