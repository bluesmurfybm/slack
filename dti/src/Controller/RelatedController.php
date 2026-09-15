<?php

namespace Dti\Controller;

use Dti\Http\Response;

final class RelatedController
{
    public function __construct(private readonly \PDO $pdo) {}

    public function index(int $tid): Response
    {
        dti_topic_find_or_fail($this->pdo, $tid);
        return Response::json(dti_related_top_for($this->pdo, $tid, DTI_MAX_RELATED));
    }
}
