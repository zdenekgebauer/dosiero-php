# Dosiero PHP

PHP server connector for Dosiero file manager 


## Usage
1. Create entry point i.e. https://yourserver/dosiero/index.php by this example:    
```php

declare(strict_types=1);

// entry point of the server part of the connector; see "Protocol" below for the request
// and response format

namespace Dosiero;

// uncomment for debugging
// error_reporting(E_ALL);
// ini_set('display_errors', 'on');

use Exception;
use Dosiero\Local\LocalStorage;

require_once __DIR__ . '/vendor/autoload.php';

$config = new Config();

// at least one of these is required - without any the connector refuses every request
$config->requireBasicAuth('user', 'password');
// $config->requireSession('session_variable_name', 'session_variable_value');
// $config->setAllowedIp(['127.0.0.1', '10.0.0.0/8']);
// $config->requireCallback(static fn(string $action, string $storage): bool => true);
// $config->allowAnonymous();

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

// origins come from Config, so an error response carries the same headers as a successful one
$response->allowAccessFrom($config);
// a browser asks before it will send a cross-origin request carrying the protocol header
if (!$response->sendPreflight()) {
    $response->sendOutput();
}
```
2. Set access control - a connector without any configured check refuses every request
3. Set at least one file storage
4. Set url of entry point in Dosiero client side configuration

### Inside a framework: a controller instead of a standalone script

The example above is a standalone script, which is the shortest thing that works. In an application
that already has routing, authentication and a response object, the connector belongs in a
controller on a route of its own — that way the file manager is reached through the same front
controller as everything else, and it can reuse what the application already knows.

Two things change. **The application answers who the caller is**, through `requireCallback()`, so the
connector does not have to be told which of session, token or cookie your login uses. And
**`sendOutput()` is not used**: it echoes the body and sets headers itself, which a controller must
not do. Take the status and the payload off the response object and hand them to your framework:

```php
final class FilesController
{
    public function handleRequest(): ResponseInterface // your framework's response
    {
        // the connector below filters storages per caller, so for action=storages an anonymous
        // request would get an empty inventory and 200 rather than being turned away
        if ($this->application->currentUser() === null) {
            return $this->json(403, ['msg' => 'access denied']);
        }

        $config = new \Dosiero\Config();
        $config->requireCallback(
            fn(string $action, string $storage): bool => $this->application->currentUser() !== null,
        );

        try {
            $connector = new \Dosiero\Connector($config);
            $connector->addStorage($this->createStorage());
            $response = $connector->handleRequest();
        } catch (\Dosiero\AccessForbiddenException $exception) {
            $response = new \Dosiero\Response(403, $exception->getMessage());
        } catch (\Dosiero\StorageException | \Dosiero\InvalidRequestException $exception) {
            $response = new \Dosiero\Response(400, $exception->getMessage());
        }

        return $this->json($response->getHttpStatus(), $response->toStdClass());
    }
}
```

`getHttpStatus()` and `toStdClass()` are there for exactly this: the same outcome `sendOutput()`
would have produced, as values you can put into a response of your own.

Points worth getting right:

- **Register the route for `GET` and `POST`.** Reading uses `GET`, every mutation uses `POST`.
- **Do not route the endpoint through a URL rewrite that strips the query string** — the action
  arrives as `?action=…`.
- **Build the storage per request.** The entry point runs on every request, so `BASE_DIR` can be
  derived from whoever is signed in; see [Separating content per user](#separating-content-per-user).
- **The check is still yours to configure.** A connector with no access check refuses everything,
  and the callback counts as one — but as the first line above shows, a callback that filters
  storages is not the same thing as refusing an unauthenticated caller outright.
- Only serve `OPTIONS` and the CORS headers when the client is on another origin; same-origin
  deployments need neither, and the framework's own routing may answer `OPTIONS` already.

## Protocol

One entry point, one request per action, JSON in the response body. The authority is
`Connector::handleRequest()`; this section describes what it does.

Every request carries `$_GET['action']`. Actions other than `storages` also carry
`$_GET['storage']` with the storage name, and optionally `$_GET['path']` — the directory
relative to `BASE_DIR`, without a leading or trailing slash. Mutations send their arguments
as `POST`.

| Action | Also sends | Returns |
|---|---|---|
| `storages` | — | `storages` |
| `files` | — | `files` |
| `files-reload` | — | `files`, with the directory cache bypassed |
| `mkdir` | `folder` | `files`, `storage` |
| `delete` | `files[]` | `files`; `storage` if a folder was deleted |
| `rename` | `old`, `new` | `files`; `storage` if a folder was renamed |
| `copy` | `files[]`, `target_path`, `target_storage` | `files`; `storage` if a folder was copied |
| `move` | `files[]`, `target_path`, `target_storage` | `files`; `storage` if a folder was moved |
| `upload` | `$_FILES` | `files` |

The response object always has `msg` — empty on success, the error text otherwise — and
carries these properties when the action produced them:

- **`files`** — the contents of `path`, each entry with `name`, `type` (`file` or `dir`),
  `size`, `modified`, `url`, `width`, `height` and `thumbnail`
- **`storage`** — one storage: `name`, `read_only`, `max_file_size`, `allowed_extensions`
  and `folders`, the complete folder tree from the storage root. It is sent whenever an
  action changed the tree, so the client can redraw it
- **`storages`** — the same objects for every registered storage

`copy` and `move` between two different storages are not supported yet and are refused.

Every request must carry the header `X-Dosiero-Protocol: 1`; one without it is refused
with 403. See [Requests from another site](#requests-from-another-site) for why.

The HTTP status carries the outcome as well: 400 for an invalid request or a storage
error, 403 for a refused access, 500 for anything unexpected. The mapping is the entry
point's job — the `catch` blocks in the example above.

## Securing the data directory

`BASE_URL` has to be publicly reachable, so `BASE_DIR` usually sits under the web
root. Anything that lands there is served by the web server, which makes the data
directory the most sensitive part of the installation.

**Uploads are restricted to a list of extensions.** The default list covers images,
common documents and archives; extensions a web server can be talked into executing
(`php`, `phtml`, `phar`, `cgi`, `htaccess`, …) are refused in *any* position of the
name, so `invoice.php.jpg` does not get through either. A file claiming an image
extension has to actually be an image.

Narrow or widen the list per storage:

```php
$localStorage->setOption(LocalStorage::OPTION_ALLOWED_EXTENSIONS, 'jpg,jpeg,png,gif,webp,pdf');
```

`svg` is **not** in the default list on purpose. An SVG can carry script and is
served from the same origin as your site, so enabling it is a decision to make
knowingly.

**The declared type is verified against the content.** Every upload is inspected with
`finfo`: anything detected as an executable or a script is refused whatever it is
called, and where the extension has an unambiguous signature (`pdf`, archives, audio,
video, plain text) the content has to match it. Office documents are ZIP or OLE
containers that libmagic reports inconsistently, so those are checked by extension
only.

## Access control

`Config` decides who may call the connector, and **a connector with nothing configured
refuses everything**. A public file manager has to be asked for, not arrived at by
forgetting:

```php
$config = new Config();
$config->requireBasicAuth('user', 'password');          // HTTP basic authentication
$config->requireSession('user_id', '42');               // a session variable and its value
$config->setAllowedIp(['127.0.0.1', '10.0.0.0/8']);     // single addresses or CIDR ranges
$config->allowAnonymous();                              // deliberately public, e.g. a demo
```

Whatever you set has to pass — the checks combine with AND. Every refusal answers `403`
with the same message, so the response does not say which check objected.

`setAllowedIp()` takes single addresses or CIDR ranges, IPv4 and IPv6, and compares them
by value: `::1` and `0:0:0:0:0:0:0:1` are the same address. An entry it cannot parse is
refused when you set it, not silently ignored.

### Deciding permissions in the application

The four checks above answer *who is calling*. To answer *what they may do*, hand the
connector a function — it already knows your users, and this way the connector does not
have to:

```php
$config->requireCallback(static function (string $action, string $storage): bool {
    if ($action === 'files' || $action === 'storages') {
        return true;                                    // everybody may look
    }
    return Auth::user()?->isEditor() === true;           // only editors may change
});
```

It receives the action and the storage name, and its answer is final. The `storages`
response is filtered through it as well, so a storage the caller may not reach does not
appear in the list either.

**It is deliberately not given the path.** `rename` and `move` change the path, so a rule
bound to a folder would stop holding the moment a file was moved out of it, and `copy`
would leave one copy inside the rule and one outside. A promise the connector cannot keep
is worse than no promise.

### Separating content per user

Give each user their own storage. The entry point is an ordinary script that runs on every
request, so it can build one from whoever is logged in — no extra configuration:

```php
$storage = new LocalStorage('MINE');
$storage->setOption(LocalStorage::OPTION_BASE_DIR, __DIR__ . '/data/' . Auth::user()->id);
$storage->setOption(LocalStorage::OPTION_BASE_URL, 'https://example.org/data/' . Auth::user()->id);
```

### Requests from another site

Every request must carry the `X-Dosiero-Protocol` header, and the connector refuses one
without it. This is what stops a page on somebody else's site from reaching the endpoint:
a browser will not put a custom header on a plain form submission, and a script that tries
has to get the connector's permission first — which it does not give to an origin you did
not name. **The value is not a secret**; it is the protocol version, and the protection
comes from the browser's rules rather than from anything being hard to guess.

Two further checks apply to `mkdir`, `delete`, `rename`, `copy`, `move` and `upload`: a
request the browser reports as coming from another site (`Sec-Fetch-Site`) or carrying a
foreign `Origin` is refused unless that origin is configured. When the browser reports
neither, the request goes through — those headers are filled in by the browser and cannot
be forged or suppressed by a script, so their absence means an old browser or a client
that is not a browser, not an attack.

### Running the connector on another domain

The recommended deployment is the **same origin** as the application. Serving the
connector from a different domain is supported and sometimes necessary — a domain
reserved for content, one connector shared by several applications, or CKEditor's popup,
which receives the endpoint in its query string:

```php
$config->allowOrigin('https://app.example.org');
// or, when the client has to send cookies or basic auth:
$config->allowOrigin('https://app.example.org', true);
```

The entry point then hands the configuration to the response and answers the question the
browser asks before it will send such a request:

```php
$response->allowAccessFrom($config);
if (!$response->sendPreflight()) {
    $response->sendOutput();
}
```

On the client set `withCredentials: true` when credentials have to travel with it.

- **Name the origin, do not use `*` with credentials.** A browser refuses a wildcard for
  any request carrying credentials, and `allowOrigin()` refuses the combination when you
  set it. Credentials are recorded for the origin you granted them to, not for the
  configuration as a whole, so adding `*` alongside a credentialed origin widens who may
  read — never who may send credentials.
- **Session authentication does not survive the domain change.** A cookie belongs to the
  application's origin and the browser will not attach it to a request going elsewhere.
  A cross-origin deployment therefore needs basic auth, the IP allowlist, or a decision
  callback fed by something the client does send.
- **The base URL of the files is a separate matter.** `BASE_URL` is what the editor
  embeds, so the files themselves have to stay publicly reachable from the page that
  shows them, whatever domain the connector answers on.

## Storage options

Every option goes through `setOption(string $name, bool|int|string $value)`. List-valued
options are passed as a comma-separated string. An unknown name throws.

| Option | Default | Meaning |
| --- | --- | --- |
| `BASE_DIR` (`LocalStorage`) | — | Directory holding the files. Required; throws when it is not a directory. |
| `BASE_URL` | `''` | Public url the same files are served from, used to build the url in the response. |
| `ALLOWED_EXTENSIONS` | images, documents, archives, media — **not** `svg` | What an upload may be called. See [Upload types](#upload-types). |
| `MAX_FILE_SIZE` | `5242880` (5 MB) | Per file, in bytes. `0` leaves only the php.ini ceiling. |
| `OVERWRITE_FILES` | `true` | Whether upload, copy, move and rename may replace an existing file. |
| `NORMALIZE_NAMES` | `true` | Repair an uploaded name instead of refusing it. Off means an invalid name is rejected. |
| `READ_ONLY` | `false` | Refuses every mutation and tells the client, which disables the buttons. |
| `MODE_DIRECTORY` | `0o755` | Permissions for a created directory. |
| `MODE_FILE` | `0o644` | Permissions for a stored file. |
| `THUMBNAIL_SIZE` | `50` | Longer side of the generated thumbnail, in pixels. Minimum 10. |
| `CACHE_ENABLED` | `true` | See [Listing cache](#listing-cache). |
| `CACHE_DIRECTORY` | `''` | Empty keeps `.htdircache` next to the data. |

### What the connector will not do

Some behaviour is not configurable on purpose, because making it optional would mean
offering a setting whose only effect is to make the installation unsafe.

- **Symbolic links are never followed.** A link inside a storage is not listed, not
  offered in the folder tree, and refused by copy, move and delete. `BASE_DIR` is
  canonicalized per request, but the entries met while walking a tree are not covered by
  that check — a link is the one way a path could lead out of the storage, so it stops at
  the link. The API cannot create one; they get there by ftp, by hand, or from another
  process.
- **Dot files are invisible and immutable.** They are not listed and cannot be deleted,
  renamed, copied or moved. An entry the manager does not show must not be removable
  through the API that serves it.
- **A name is never allowed to become executable.** Upload and rename both refuse `php`,
  `phtml`, `phar`, `cgi`, `htaccess` and their kin **in any position** of the name, so
  `invoice.php.jpg` does not get through either.
- **A folder cannot be copied or moved into itself or into its own child.** The whole
  batch is checked before the first file is touched, so a refusal never leaves half of it
  moved.

### Collisions

`OVERWRITE_FILES` governs upload, copy, move and rename alike. With it off, a collision is
reported before anything is written:

```
files were not overwritten: report.pdf,logo.png
```

Renaming onto an existing name is refused the same way. With it on — the default — the
existing file is replaced, as the option says.

### Upload types

What an upload has to pass is described under
[Securing the data directory](#securing-the-data-directory): the extension allowlist, the
executable-extension rule, verification of the content against the declared type, and
`getimagesize()` for anything claiming to be an image.

One consequence is worth stating here, because it decides where the real protection sits.
**The allowlist is a rule for what comes in, not a property of what is stored.** Files also
arrive by ftp, by a migration or by hand, and renaming those has to stay possible — so
renaming enforces only the executable-extension rule, not the allowlist. A storage can
therefore hold a `.svg` even when `svg` is not on the list. Refusing to execute anything in
the data directory is a requirement, not a spare layer.

### Resource limits

- **Uploads** are bounded by `MAX_FILE_SIZE` and the php.ini ceilings; see
  [Upload size](#upload-size).
- **Thumbnails** are refused without decoding above 100 megapixels, or 50 000 pixels on a
  side. The dimensions come from the header long before the pixels do, and a small
  compressed file can demand gigabytes once expanded — for every image in the folder, on
  every listing.
- **There is no quota** on a storage as a whole: not on total bytes, not on the number of
  files, not on the depth of the tree, and no rate limit. An installation open to people
  you do not trust needs all of that in the application around the connector.

### Errors

Every failure reaches the entry point as one of four exceptions, and the mapping to status
codes is yours to make — the connector never sets a status or echoes anything:

| Exception | Meaning | Suggested status |
| --- | --- | --- |
| `AccessForbiddenException` | An access check refused the request. | 403 |
| `InvalidRequestException` | A parameter is missing, malformed, or names something that does not exist. | 400 |
| `StorageException` | The operation itself failed: collision, missing file, refused type, failed write. | 400 |
| `\Throwable` | Anything unforeseen. | 500 |

Successful or not, the body is JSON with a `msg` property — empty on success, the message
on failure. **Every access refusal answers with the same text**, so the response does not
tell a caller which check objected. The one exception is a connector with no access check
configured at all, which names that reason: there is no visitor to keep it from and the
integrator would otherwise be guessing.

For a public deployment, answer unforeseen errors with a generic message and a correlation
id, and log the detail on the server. The catch-all in the example above passes
`getMessage()` to the client, which is convenient in development and can leak paths in
production.

## Upload size

Each storage has its own limit, in bytes, defaulting to 5 MB:

```php
$localStorage->setOption(LocalStorage::OPTION_MAX_FILE_SIZE, 20 * 1024 * 1024);
$localStorage->setOption(LocalStorage::OPTION_MAX_FILE_SIZE, 0); // only php.ini limits
```

**php.ini is a ceiling this option cannot raise.** The limit that really applies is the
smallest of `MAX_FILE_SIZE`, `upload_max_filesize` and `post_max_size`, and that is also
the value `getMaxFileSize()` reports and the connector sends to the client. Setting the
storage to 20 MB on a server configured for 2 MB gives you 2 MB.

To allow larger uploads, raise the ini directives as well — both of them, because a
request also carries multipart overhead:

```ini
upload_max_filesize = 20M
post_max_size = 21M
```

Two traps worth knowing:

- The shorthand suffix is a **single letter**. `20MB` means twenty *bytes*; write `20M`.
- When a request body exceeds `post_max_size`, PHP discards `$_POST` and `$_FILES`
  entirely and reports nothing. The connector detects that case and answers with an
  explicit message instead of behaving as if no file had been sent — but the upload
  still fails, so the ini value has to be large enough in the first place.

The client uses the reported limit to reject an oversized file before it is sent, which
saves the transfer; the server check is the authoritative one and always runs.

**Turn off script execution for the data directory as well.** The extension list is
one layer; the web server should be the second.

**Put the rules outside the directory they protect** — in the vhost, or in the
`.htaccess` of a parent directory scoped to the path. A configuration file sitting
among the managed files is part of the data the connector serves, and a future change
to what it lists or writes could expose it. The connector hides names starting with a
dot and refuses to delete or rename them, so `.htaccess` is not reachable through the
manager, but relying on that is a weaker arrangement than not putting it there at all.

For Apache, in the `.htaccess` one level up or in the vhost:

```apache
<If "%{REQUEST_URI} =~ m#^/files/#">
    SetHandler none
    RemoveHandler .php .phtml .phar .cgi .pl .py
    Options -ExecCGI -Indexes
    <IfModule mod_headers.c>
        Header always set Content-Disposition "attachment"
        Header always set X-Content-Type-Options "nosniff"
    </IfModule>
</If>
```

`Content-Disposition: attachment` makes the browser download a file opened directly
instead of rendering it, which neutralizes an uploaded document that tries to behave
like a page. It does **not** stop `<img src>` from displaying the file, so editor
integrations are unaffected.

On nginx make sure no `location ~ \.php$` block applies to the data directory.

### Listing cache

**Keep the listing cache out of reach.** By default every directory gets a
`.htdircache` file holding its listing including base64 thumbnails. The `.ht`
prefix is only honoured by Apache's default configuration - on nginx the file is
downloadable unless you block it explicitly. What leaks is not the files, which
are public anyway, but the listing: names nobody linked to, plus their thumbnails.

Two options change that:

```php
$storage->setOption(Storage::OPTION_CACHE_DIRECTORY, '/var/cache/dosiero');
$storage->setOption(Storage::OPTION_CACHE_ENABLED, false);
```

`CACHE_DIRECTORY` moves the cache to a directory of your choice - pick one outside
the web root, and not a temp directory shared with other accounts. `CACHE_ENABLED`
switches caching off completely; listings then rebuild thumbnails on every request,
which is slower but writes nothing anywhere.

A directory the web server cannot write to - a read-only mount, another owner - is
handled the same way as `CACHE_ENABLED = false`: the listing works and nothing is
cached. Set `CACHE_DIRECTORY` to get the cache back for such a storage.

## Local development

```bash
lando start          # php 8.5 + apache at https://dosiero-php.local
lando install        # composer install
lando codeception run Unit          # one suite; Integration and Api likewise
lando ecs-fix && lando ecs          # coding standard
lando phpstan                       # level max, strict + deprecation rules
lando rector                        # dry run
lando coverage                      # xdebug on for this one command
```

The `Api` suite drives the connector over real HTTP, which is the only place the CORS and
preflight headers actually exist. It runs against `tests/Support/Endpoint/index.php`, a
committed entry point — not against `/index.php`, which is gitignored and is the local
playground. CI serves the same file with php's built-in server:

```bash
php -S 127.0.0.1:8080 -t tests/Support/Endpoint
vendor/bin/codecept run Api --env ci
```

Client and connector are separate repositories and are released together. The client is at
`../Dosiero`; the demo that wires both together is at `../DosieroDemo`.
