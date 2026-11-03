import notify


def test_job_finished_only_for_people(monkeypatch):
    sent = []
    monkeypatch.setattr(notify.php_cli, "push", lambda runner, st, kind, title, body, **kw: sent.append((kind, title, body, kw)))
    base = {"id": 7, "kind": "plan", "request_id": "Rec1", "params": {}}
    notify.job_finished(None, None, {**base, "requested_by": "worker:host"}, "done")
    notify.job_finished(None, None, {**base, "requested_by": ""}, "done")
    notify.job_finished(None, None, {**base, "requested_by": "a@x.kr", "params": {"source": "sync"}}, "done")
    assert sent == []
    notify.job_finished(None, None, {**base, "requested_by": "a@x.kr"}, "done")
    notify.job_finished(None, None, {**base, "requested_by": "a@x.kr"}, "failed", "boom")
    assert [(k, t, b, kw["to"]) for k, t, b, kw in sent] == [
        ("slackai.job", "AI 플랜 끝 · Rec1", "", ["a@x.kr"]),
        ("slackai.job", "AI 플랜 실패 · Rec1", "boom", ["a@x.kr"]),
    ]
