<?php

declare(strict_types=1);

namespace Dosiero;

use function in_array;

class Connector
{
    private Config $config;

    /**
     * @var array<StorageInterface>
     */
    private array $storages = [];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function addStorage(StorageInterface $storage): void
    {
        $this->storages[$storage->getName()] = $storage;
    }

    public function handleRequest(): Response
    {
        $request = new Request();
        $this->assertValidAccess();

        switch ($request->getAction()) {
            case 'storages':
                $response = $this->getStorages();
                break;
            case 'files':
                $response = $this->getFiles($request);
                break;
            case 'files-reload':
                $response = $this->getFiles($request, true);
                break;
            case 'mkdir':
                $response = $this->mkDir($request);
                break;
            case 'upload':
                $response = $this->upload($request);
                break;
            case 'rename':
                $response = $this->rename($request);
                break;
            case 'delete':
                $response = $this->delete($request);
                break;
            case 'copy':
                $response = $this->copy($request);
                break;
            case 'move':
                $response = $this->move($request);
                break;
            default:
                $response = new Response(Response::STATUS_BAD_REQUEST, 'missing or invalid parameter "action"');
        }
        return $response;
    }

    private function assertValidAccess(): void
    {
        $sessionName = $this->config->getSessionName();
        $sessionValue = $this->config->getSessionValue();
        $allowedIp = $this->config->getAllowedIp();
        $basicAuthUser = $this->config->getBasicAuthUser();
        $basicAuthPassword = $this->config->getBasicAuthPassword();

        if (
            (!empty($basicAuthUser) || !empty($basicAuthPassword))
            && !isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
        ) {
            throw new AccessForbiddenException('missing required basic auth');
        }
        if (
            isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
            && ($_SERVER['PHP_AUTH_USER'] !== $basicAuthUser || $_SERVER['PHP_AUTH_PW'] !== $basicAuthUser)
        ) {
            throw new AccessForbiddenException('invalid basic authentication');
        }

        if (!empty($sessionName && !isset($_SESSION[$sessionName]))) {
            throw new AccessForbiddenException('missing required session variable');
        }
        if (!empty($sessionValue) && $_SESSION[$sessionName] !== $sessionValue) {
            throw new AccessForbiddenException('missing or invalid value of required session variable');
        }
        if (
            !empty($allowedIp)
            && (!isset($_SERVER['REMOTE_ADDR']) || !in_array($_SERVER['REMOTE_ADDR'], $allowedIp, true))
        ) {
            throw new AccessForbiddenException('access from your IP is not allowed');
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

    public function upload(Request $request): Response
    {
        $storage = $this->getStorage($request->getStorage());
        $this->assertNotReadOnly($storage);
        $status = Response::STATUS_OK;
        $message = '';

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

    public function rename(Request $request): Response
    {
        if (strpos($request->getOldFile(), '/') !== false || strpos($request->getNewFile(), '/') !== false) {
            throw new StorageException('allowed rename in current folder only');
        }

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

    public function delete(Request $request): Response
    {
        $storage = $this->getStorage($request->getStorage());

        $deletedFolder = false;
        $storage->delete($request->getPath(), $request->getSelectedFiles(), $deletedFolder);
        $this->assertNotReadOnly($storage);

        $response = new Response();
        $response->setFiles($storage->getFiles($request->getPath()));
        if ($deletedFolder) {
            $response->setStorage($storage);
        }
        return $response;
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
            if ($request->getStorage() === $request->getTargetStorage()) {
                $response->setStorage($storage);
            } else {
                $response->setStorage($targetStorage);
            }
        }

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
            if ($request->getStorage() === $request->getTargetStorage()) {
                $response->setStorage($storage);
            } else {
                $response->setStorages($this->storages);
            }
        }

        return $response;
    }

    public function getStorages(): Response
    {
        $response = new Response();
        $response->setStorages($this->storages ?: []);
        return $response;
    }

    protected function assertNotReadOnly(StorageInterface $storage): void
    {
        if ($storage->isReadOnly()) {
            throw new AccessForbiddenException('storage "' . $storage->getName() . '" is read only');
        }
    }
}
