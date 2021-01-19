# Dosiero PHP

PHP server connector for Dosiero file manager 


## Usage
1. Create entry point i.e. https://yourserver/dosiero/index.php by this example:    
```php

declare(strict_types=1);

/**
 * entry point of server part of connector
 *
 * always expects:
 * $_GET['action'] - requested action. Some actions requires additional parameters - see bellow
 *
 * optionally expects:
 * $_GET['path'] - current directory path relative to base dir, without starting and leading slash
 *
 * returns JSON with properties:
 * msg - error message
 * folders - tree of folders. Returned if action change folders
 * files - list of folders and files in given path. Returned if action change files in current path
 *
 * action "folders"
 * - returns property "folders" with all directories as array of objects. Each object represents one folder
 *   and contains array "subfolders" with nested folders and files
 *
 * action "files"
 * - returns property "files" with folders and files in current path
 *
 * action "mkdir" create new folder
 * - require $_POST['folder'] with name of new folder
 * - returns property "folders" with all directories
 *
 * action "delete" delete file or folder in current path
 * - require $_POST['files'] with array of names of files or folders
 *
 * action "rename" rename file or folder in current path
 * - require $_POST['old'] with name of file or folder
 * - require $_POST['new'] with new name of file or folder
 *
 * action "upload" upload files
 * - require standard $_FILES array
 *
 * action "copy" copy files and or folders to another folder. Copy to another storage is not supported yet
 * - require $_POST['files'] with array of names of files or folders
 * - require $_POST['target_path'] with path to target folder
 * - require $_POST['target_storage'] with name of target storage
 *
 * action "move" moves file in current path to another folder
 * - require $_POST['files'] with array of names of files or folders
 * - require $_POST['target_path'] with path to target folder
 * - require $_POST['target_storage'] with name of target storage
 */

namespace Dosiero;

// uncomment for debugging
// error_reporting(E_ALL);
// ini_set('display_errors', 'on');

use Exception;
use Dosiero\Local\LocalStorage;

require_once __DIR__ . '/vendor/autoload.php';

$config = new Config();

// access control with Basic authentication
// $config->requireBasicAuth('user', 'password');
// access control with session variable
// $config->requireSession('session_variable_name', 'session_variable_value');
// access control by IP address
// $config->setAllowedIp(['127.0.0.1']);

// set at least one file storage 
$localStorage = new LocalStorage('LOCAL') ;
$localStorage->setOption(LocalStorage::OPTION_BASE_DIR, __DIR__ . '/data');
$localStorage->setOption(LocalStorage::OPTION_BASE_URL, 'https://yourserver/data');
$localStorage->setOption(LocalStorage::OPTION_MODE_DIRECTORY, 0775);
$localStorage->setOption(LocalStorage::OPTION_MODE_FILE, 0664);

try {
    $connector = new Connector($config);
    $connector->addStorage($localStorage);
    $response = $connector->handleRequest();
} catch (AccessForbiddenException $exception) {
    $response = new Response(403, $exception->getMessage());
} catch (StorageException $exception) {
    $response = new Response(400, $exception->getMessage());
} catch (InvalidRequestException $exception) {
    $response = new Response(400, $exception->getMessage());
} catch (Exception $exception) {
    $response = new Response(500, 'unexpected problem: ' . $exception->getMessage());
}

// if is called from different domain, set CORS
// $response->allowAccessFromDomain('*');
// or $response->allowAccessFromDomain('https://otherdomain/');
$response->sendOutput();
```
2. Do not forget set access control (basic auth, session or IP address)
3. Set at least one file storage
4. Set url of entry point in Dosiero client side configuration   
