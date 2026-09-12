<?php

declare(strict_types=1);

namespace Dosiero;

use function in_array;

class Connector
{
    // the header required from client, the value is the protocol version, not a secret
    public const string PROTOCOL_HEADER = 'X-Dosiero-Protocol';

    public const string PROTOCOL_VERSION = '1';

    private const string ACCESS_DENIED = 'access denied';

    /** @var array<string> actions that change something and therefore need the origin checked */
    private const array MUTATING_ACTIONS = ['copy', 'delete', 'mkdir', 'move', 'rename', 'upload'];

    /** @var array<StorageInterface> */
    private array $storages = [];

    public function __construct(private readonly Config $config)
    {
    }

    public function addStorage(StorageInterface $storage): void
    {
        $this->storages[$storage->getName()] = $storage;
    }

    public function copy(Request $request): Response
    {
        $storage = $this->getStorage($request->getStorage());
        $targetStorage = $this->getStorage($request->getTargetStorage());
        $this->assertNotReadOnly($targetStorage);

        if ($request->getStorage() !== $request->getTargetStorage()) {
            throw new InvalidRequestException('unsupported operation: copy to different storage');
        }

        $copiedFolder = false;
        $storage->copy($request->getPath(), $request->getSelectedFiles(), $request->getTargetPath(), $copiedFolder);

        $response = new Response();
        $response->setFiles($storage->getFiles($request->getPath()));
        if ($copiedFolder) {
            $response->setStorage($storage);
        }

        return $response;
    }

    public function delete(Request $request): Response
    {
        $storage = $this->getStorage($request->getStorage());
        $this->assertNotReadOnly($storage);

        $deletedFolder = false;
        $storage->delete($request->getPath(), $request->getSelectedFiles(), $deletedFolder);

        $response = new Response();
        $response->setFiles($storage->getFiles($request->getPath()));
        if ($deletedFolder) {
            $response->setStorage($storage);
        }
        return $response;
    }

    public function getStorages(): Response
    {
        $visible = array_filter(
            $this->storages,
            fn(StorageInterface $storage): bool => $this->isAuthorized('files', $storage->getName()),
        );

        $response = new Response();
        $response->setStorages($visible);
        return $response;
    }

    public function handleRequest(): Response
    {
        $request = new Request();
        self::assertProtocolHeader();
        $this->assertValidAccess();
        $this->assertRequestOrigin($request->getAction());
        $this->assertAuthorized($request->getAction(), $request->getStorage());

        $response = match ($request->getAction()) {
            'storages' => $this->getStorages(),
            'files' => $this->getFiles($request),
            'files-reload' => $this->getFiles($request, true),
            'mkdir' => $this->mkDir($request),
            'upload' => $this->upload($request),
            'rename' => $this->rename($request),
            'delete' => $this->delete($request),
            'copy' => $this->copy($request),
            'move' => $this->move($request),
            default => new Response(Response::STATUS_BAD_REQUEST, 'missing or invalid parameter "action"'),
        };
        return $response;
    }

    public function mkDir(Request $request): Response
    {
        $storage = $this->getStorage($request->getStorage());
        $this->assertNotReadOnly($storage);

        $newFolder = $request->getNewFolder();
        if (!Utils::isValidFolderName($newFolder)) {
            throw new InvalidRequestException('invalid folder name "' . $newFolder . '"');
        }

        $storage->mkDir($request->getPath(), $newFolder);

        $response = new Response();
        $response->setFiles($storage->getFiles($request->getPath()));
        $response->setStorage($storage);
        return $response;
    }

    public function move(Request $request): Response
    {
        $storage = $this->getStorage($request->getStorage());
        $this->assertNotReadOnly($storage);
        $targetStorage = $this->getStorage($request->getTargetStorage());
        $this->assertNotReadOnly($targetStorage);
        $movedFolder = false;

        if ($request->getStorage() === $targetStorage->getName()) {
            $storage->move($request->getPath(), $request->getSelectedFiles(), $request->getTargetPath(), $movedFolder);
        } else {
            throw new InvalidRequestException('unsupported operation: move to different storage');
        }

        $response = new Response();
        $response->setFiles($storage->getFiles($request->getPath()));
        if ($movedFolder) {
            $response->setStorage($storage);
        }

        return $response;
    }

    public function rename(Request $request): Response
    {
        $storage = $this->getStorage($request->getStorage());
        $this->assertNotReadOnly($storage);

        $renamedFolder = false;
        $storage->rename($request->getPath(), $request->getOldFile(), $request->getNewFile(), $renamedFolder);

        $response = new Response();
        $response->setFiles($storage->getFiles($request->getPath()));
        if ($renamedFolder) {
            $response->setStorage($storage);
        }
        return $response;
    }

    public function upload(Request $request): Response
    {
        $storage = $this->getStorage($request->getStorage());
        $this->assertNotReadOnly($storage);
        $status = Response::STATUS_OK;
        $message = '';

        self::assertRequestBodyReceived();

        try {
            $storage->upload($request->getPath(), $request->getUploadedFiles());
        } catch (StorageException $exception) {
            $status = Response::STATUS_BAD_REQUEST;
            $message = $exception->getMessage();
        }

        $response = new Response($status, $message);
        $response->setFiles($storage->getFiles($request->getPath(), true));
        return $response;
    }

    protected function assertNotReadOnly(StorageInterface $storage): void
    {
        if ($storage->isReadOnly()) {
            throw new AccessForbiddenException('storage "' . $storage->getName() . '" is read only');
        }
    }

    private function assertAuthorized(string $action, string $storage): void
    {
        if ($storage !== '' && !$this->isAuthorized($action, $storage)) {
            throw new AccessForbiddenException(self::ACCESS_DENIED);
        }
    }

    private static function assertProtocolHeader(): void
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper(self::PROTOCOL_HEADER));
        $sent = $_SERVER[$key] ?? '';
        if (!is_string($sent) || $sent === '') {
            throw new AccessForbiddenException(self::ACCESS_DENIED);
        }
    }

    // upload of file >= post_max_size discards $_POST and $_FILES entirely and reports nothing
    private static function assertRequestBodyReceived(): void
    {
        $postMaxSize = Utils::iniBytes('post_max_size');
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) && is_numeric($_SERVER['CONTENT_LENGTH'])
            ? (int)$_SERVER['CONTENT_LENGTH']
            : 0;

        if ($postMaxSize > 0 && $contentLength > $postMaxSize && $_FILES === [] && $_POST === []) {
            throw new InvalidRequestException(
                'uploaded data of ' . $contentLength . ' bytes exceeds the post_max_size limit of '
                . $postMaxSize . ' bytes',
            );
        }
    }

    /** Sec-Fetch-Site and Origin are filled in by the browser. Absence means old browser or a "not bowser" client. */
    private function assertRequestOrigin(string $action): void
    {
        if (!in_array($action, self::MUTATING_ACTIONS, true)) {
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $origin = is_string($origin) ? $origin : '';
        if ($origin !== '' && !self::isOwnOrigin($origin) && !$this->config->isOriginAllowed($origin)) {
            throw new AccessForbiddenException(self::ACCESS_DENIED);
        }

        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
        $site = is_string($site) ? $site : '';
        if (($site === 'cross-site' || $site === 'none') && !$this->config->isOriginAllowed($origin)) {
            throw new AccessForbiddenException(self::ACCESS_DENIED);
        }
    }

    private function assertValidAccess(): void
    {
        if (!$this->config->isConfigured()) {
            throw new AccessForbiddenException(
                'no access control configured - set one on Config, or call allowAnonymous() to serve everybody',
            );
        }

        $sessionName = $this->config->getSessionName();
        $sessionValue = $this->config->getSessionValue();
        $allowedIp = $this->config->getAllowedIp();
        $basicAuthUser = $this->config->getBasicAuthUser();
        $basicAuthPassword = $this->config->getBasicAuthPassword();

        if ($basicAuthUser !== '' || $basicAuthPassword !== '') {
            if (!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
                throw new AccessForbiddenException(self::ACCESS_DENIED);
            }
            $user = is_string($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
            $password = is_string($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
            $validUser = hash_equals($basicAuthUser, $user);
            $validPassword = hash_equals($basicAuthPassword, $password);
            if (!$validUser || !$validPassword) {
                throw new AccessForbiddenException(self::ACCESS_DENIED);
            }
        }

        if ($sessionName !== '') {
            // $_SESSION exists only after session_start(), but may also be populated directly
            if (session_status() !== PHP_SESSION_ACTIVE && !isset($_SESSION)) {
                throw new AccessForbiddenException(self::ACCESS_DENIED);
            }
            if (!isset($_SESSION[$sessionName])) {
                throw new AccessForbiddenException(self::ACCESS_DENIED);
            }
            if ($sessionValue !== '' && $_SESSION[$sessionName] !== $sessionValue) {
                throw new AccessForbiddenException(self::ACCESS_DENIED);
            }
        }
        if ($allowedIp !== [] && !self::isAllowedIp($allowedIp)) {
            throw new AccessForbiddenException(self::ACCESS_DENIED);
        }
    }

    private function getFiles(Request $request, bool $ignoreCache = false): Response
    {
        $storage = $this->getStorage($request->getStorage());

        $response = new Response();
        $response->setFiles($storage->getFiles($request->getPath(), $ignoreCache));
        return $response;
    }

    private function getStorage(string $storageName): StorageInterface
    {
        if (!isset($this->storages[$storageName])) {
            throw new InvalidRequestException('not found storage "' . $storageName . '"');
        }
        return $this->storages[$storageName];
    }

    /** @param array<string> $allowedIp */
    private static function isAllowedIp(array $allowedIp): bool
    {
        $address = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($address) || $address === '') {
            return false;
        }
        foreach ($allowedIp as $range) {
            if (Utils::ipMatchesRange($address, $range)) {
                return true;
            }
        }
        return false;
    }

    private function isAuthorized(string $action, string $storage): bool
    {
        $authorization = $this->config->getAuthorization();
        return $authorization === null || $authorization($action, $storage) === true;
    }

    private static function isOwnOrigin(string $origin): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!is_string($host) || $host === '') {
            return false;
        }
        $scheme = isset($_SERVER['HTTPS']) ? 'https' : 'http';
        return rtrim($origin, '/') === $scheme . '://' . $host;
    }
}
