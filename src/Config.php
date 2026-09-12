<?php

declare(strict_types=1);

namespace Dosiero;

class Config
{
    /** @var array<string> CIDR notation, single addresses stored as a full-width prefix */
    private array $allowedIp = [];

    /** @var array<string, bool> origin => whether credentials may be sent to it */
    private array $allowedOrigins = [];

    private bool $anonymousAllowed = false;

    /** @var null|callable(string, string): bool */
    private $authorization;

    private string $basicAuthPassword = '';

    private string $basicAuthUser = '';

    private string $sessionName = '';

    private string $sessionValue = '';

    public function allowAnonymous(): void
    {
        $this->anonymousAllowed = true;
    }

    // an origin the client may be served from, e.g. https://app.example.org, or * for any of them
    public function allowOrigin(string $origin, bool $withCredentials = false): void
    {
        $origin = rtrim(trim($origin), '/');
        if ($origin !== '*' && !str_starts_with($origin, 'http')) {
            throw new \InvalidArgumentException('expected origin including protocol or *');
        }
        if ($origin === '*' && $withCredentials) {
            throw new \InvalidArgumentException('credentials require a named origin, not "*"');
        }
        $this->allowedOrigins[$origin] = $withCredentials;
    }

    /** @return array<string> */
    public function getAllowedIp(): array
    {
        return $this->allowedIp;
    }

    /** @return array<string> */
    public function getAllowedOrigins(): array
    {
        return array_keys($this->allowedOrigins);
    }

    /** @return null|callable(string, string): bool */
    public function getAuthorization(): ?callable
    {
        return $this->authorization;
    }

    public function getBasicAuthPassword(): string
    {
        return $this->basicAuthPassword;
    }

    public function getBasicAuthUser(): string
    {
        return $this->basicAuthUser;
    }

    public function getSessionName(): string
    {
        return $this->sessionName;
    }

    public function getSessionValue(): string
    {
        return $this->sessionValue;
    }

    public function isConfigured(): bool
    {
        return $this->anonymousAllowed
            || $this->authorization !== null
            || $this->basicAuthUser !== ''
            || $this->basicAuthPassword !== ''
            || $this->sessionName !== ''
            || $this->allowedIp !== [];
    }

    public function isCredentialsAllowed(string $origin): bool
    {
        return $this->allowedOrigins[rtrim(trim($origin), '/')] ?? false;
    }

    public function isOriginAllowed(string $origin): bool
    {
        return isset($this->allowedOrigins['*']) || isset($this->allowedOrigins[rtrim($origin, '/')]);
    }

    public function requireBasicAuth(string $user, string $password): void
    {
        $this->basicAuthUser = $user;
        $this->basicAuthPassword = $password;
    }

    /**
     * Decides whether the caller may perform an action on a storage. Deliberately not given the
     * path: rename and move change it, so a rule bound to a folder would stop holding afterwards -
     * separate content by giving each user their own storage instead.
     *
     * @param callable(string, string): bool $authorization receives the action and the storage name
     */
    public function requireCallback(callable $authorization): void
    {
        $this->authorization = $authorization;
    }

    public function requireSession(string $sessionName, string $sessionValue): void
    {
        $this->sessionName = $sessionName;
        $this->sessionValue = $sessionValue;
    }

    /** @param array<string> $allowedIp single addresses or CIDR ranges, IPv4 and IPv6 */
    public function setAllowedIp(array $allowedIp): void
    {
        $this->allowedIp = [];
        foreach ($allowedIp as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $this->allowedIp[] = Utils::normalizeIpRange($entry);
        }
    }
}
