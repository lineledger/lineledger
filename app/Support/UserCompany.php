<?php

namespace App\Support;

readonly class UserCompany
{
    public function __construct(
        public int $id,
        public string $name,
        public string $displayName,
        public string $slug,
        public bool $isPersonal,
        public ?string $role,
        public ?string $roleLabel,
        public ?bool $isCurrent = null,
    ) {
        //
    }
}
