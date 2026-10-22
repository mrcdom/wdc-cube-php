<?php

declare(strict_types=1);

namespace Wdc\Shopping\Domain\User;

final class User
{
    private ?int $id = null;
    private ?string $userName = null;
    /** hash MD5→base36 (ver PasswordUtil) */
    private ?string $password = null;
    private ?string $name = null;
    /** CSV: "ADMIN", "CUSTOMER", "ADMIN,CUSTOMER" */
    private ?string $roles = null;

    public function id(): ?int
    {
        return $this->id;
    }

    public function withId(?int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function userName(): ?string
    {
        return $this->userName;
    }

    public function withUserName(?string $userName): static
    {
        $this->userName = $userName;

        return $this;
    }

    public function password(): ?string
    {
        return $this->password;
    }

    public function withPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function withName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function roles(): ?string
    {
        return $this->roles;
    }

    public function withRoles(?string $roles): static
    {
        $this->roles = $roles;

        return $this;
    }
}
