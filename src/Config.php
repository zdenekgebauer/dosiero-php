<?php

declare(strict_types=1);

namespace Dosiero;

class Config
{
    private string $sessionName = '';

    private string $sessionValue = '';

    /**
     * @var array<string>
     */
    private array $allowedIp = [];

    private string $basicAuthUser = '';

    private string $basicAuthPassword = '';

    public function requireSession(string $sessionName, string $sessionValue): void
    {
        $this->sessionName = $sessionName;
        $this->sessionValue = $sessionValue;
    }

    public function getSessionName(): string
    {
        return $this->sessionName;
    }

    public function getSessionValue(): string
    {
        return $this->sessionValue;
    }

    /**
     * @return array<string>
     */
    public function getAllowedIp(): array
    {
        return $this->allowedIp;
    }

    /**
     * @param array<string> $allowedIp
     */
    public function setAllowedIp(array $allowedIp): void
    {
        $this->allowedIp = array_values(array_filter(array_map('trim', $allowedIp)));
    }

    public function requireBasicAuth(string $user, string $password): void
    {
        $this->basicAuthUser = $user;
        $this->basicAuthPassword = $password;
    }

    public function getBasicAuthUser(): string
    {
        return $this->basicAuthUser;
    }

    public function getBasicAuthPassword(): string
    {
        return $this->basicAuthPassword;
    }
}
