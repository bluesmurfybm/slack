<?php

namespace Dti\Identity;

final class Identity
{
    public function __construct(
        public readonly string $email,
        public readonly string $name = '',
        public readonly string $color = '',
    ) {}
}
