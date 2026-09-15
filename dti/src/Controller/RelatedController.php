<?php

namespace Dti\Controller;

use Dti\Http\Response;
use Dti\Repository\RelatedRepository;
use Dti\Repository\TopicRepository;
use Dti\Service\RelatedService;

final class RelatedController
{
    public function __construct(
        private readonly TopicRepository $topics,
        private readonly RelatedRepository $related,
    ) {}

    public function index(int $tid): Response
    {
        $this->topics->findOrFail($tid);
        return Response::json($this->related->topFor($tid, RelatedService::MAX_RELATED));
    }
}
