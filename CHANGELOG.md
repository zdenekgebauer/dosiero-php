# Changelog

Notable changes per release. Versioning follows [semver](https://semver.org/).

## [1.0.0] — 2026-09-13

The first release that can be trusted with a public directory. Every request path was reachable
outside `BASE_DIR`, uploads were accepted by name alone, and copy and move were broken in
subfolders. All of that is fixed, with a regression test per item.

`0.0.1` (2021) is the only previous tag; this release is best read as the first real one.

### Breaking

These change what an existing installation accepts. Read them before upgrading — the defaults are
deliberately conservative and some of them will refuse uploads that used to go through.

- **Every request must carry `X-Dosiero-Protocol`**, and one without it is refused with 403. This
  is what stops a page on another site from reaching the endpoint: a browser will not put a custom
  header on a plain form submission, and a script that tries has to obtain the connector's
  permission first. The value is the protocol version, **not a secret** — the protection comes from
  the browser's rules, not from being hard to guess. Client and connector are released together, so
  only a hand-written client is affected.
- **A connector with no access check configured now refuses everything.** Previously an empty
  `Config` allowed everybody, so a forgotten line left the file manager open. Call
  `allowAnonymous()` where that is genuinely intended, such as a public demo.
- **`setAllowedIp()` takes CIDR ranges and compares addresses by value.** `10.0.0.0/8` works,
  `::1` and `0:0:0:0:0:0:0:1` are the same address, and an entry that cannot be parsed now throws
  when you set it instead of being silently ignored — which used to turn a typo into a check that
  passed nobody.
- **`Response::allowAccessFromDomain()` is gone**, replaced by `Config::allowOrigin()` plus
  `Response::allowAccessFrom($config)`. Origins are access control, so they belong with the other
  access settings, and putting them on the configuration is what lets an *error* response carry the
  same CORS headers as a successful one — without them a cross-origin client cannot even read why
  it failed.
- **Uploads are restricted to a list of extensions.** `OPTION_ALLOWED_EXTENSIONS` defaults to
  images, common documents, archives and media. Extensions a web server can be talked into
  executing (`php`, `phtml`, `phar`, `cgi`, `htaccess`, …) are refused in **any** position of the
  name, so `invoice.php.jpg` does not get through either. `svg` is not in the default list on
  purpose: it can carry script and is served from your own origin, so enabling it is a decision to
  make knowingly. Narrow or widen per storage with a comma-separated string.
- **The declared type is verified against the content.** Every upload is inspected with `finfo`:
  anything detected as an executable or a script is refused whatever it is called, and where the
  extension has an unambiguous signature (`pdf`, archives, audio, video, plain text) the content
  has to match. A file claiming an image extension has to pass `getimagesize()`. Office documents
  are ZIP or OLE containers that libmagic reports inconsistently, so those are checked by extension
  only.
- **`OPTION_MAX_FILE_SIZE` defaults to 5 MB**, roughly a phone photo. Until now the only ceiling was
  the global `upload_max_filesize`, so an installation that accepts larger files has to raise this
  or set `0` for the previous behaviour. The effective limit is the smallest of the option,
  `upload_max_filesize` and `post_max_size` — php.ini is a ceiling the option cannot lift.
- **Files whose name starts with a dot are neither listed nor writable.** They do not appear in the
  file list or the folder tree, and they cannot be deleted, renamed, copied or moved. An entry the
  manager does not show must not be removable through the API that serves it. **This changes the
  advice in the README:** protect the data directory from the server configuration or a parent
  `.htaccess`, not with an `.htaccess` inside it — that file used to be listed, and therefore
  deletable, by anyone using the manager.
- **`.htdircache` changed format** from a bare map to `{mtime, files}`. Old files are not parsed and
  are regenerated once.
- **PHP 8.3 or newer.** CI runs 8.3, 8.4 and 8.5.

### Security

- **Renaming could turn any stored file into a script.** The upload rules were applied on the way in
  and nowhere else, so a file accepted as `.txt` could be renamed to `.php` afterwards and the
  extension allowlist was never an invariant of what is stored. Renaming now refuses the executable
  extensions in any position of the name. The allowlist itself deliberately does **not** apply here:
  files also arrive by ftp or by hand, and those are exactly the ones whose names need repairing.
  The consequence is worth stating plainly — the allowlist is a rule for what comes in, so refusing
  to execute anything in the data directory is a requirement, not a second line of defence.
- **A symbolic link inside a storage was followed out of it.** `absPath()` canonicalizes the root of
  the request, but nothing re-checked the entries met on the way down: the listing showed a link's
  target, a recursive copy read through it, and a recursive delete descended into it — verified to
  delete a file outside the storage. Links are now neither listed, walked, copied, moved nor
  deleted through. The API cannot create one; they arrive by ftp or from another process, which is
  enough to make it a real path.
- **Copying or moving a folder into itself had no guard.** Source and target were never compared, so
  `A` into `A` produced `A/A` and the iterator then walked into the copy it was writing. The whole
  batch is validated before the first change, so a refusal leaves nothing half-moved behind. A
  sibling whose name merely starts the same (`A` into `AB`) is unaffected.
- **A wildcard origin could end up granting credentials.** `allowOrigin('*')` followed by
  `allowOrigin('https://trusted.example', true)` set a single global flag, after which any origin was
  allowed *and* credentialed. Credentials are now recorded per origin and a wildcard is stored with
  them off, so no order of calls opens credentialed access to an origin that was not named.
  `Config::isCredentialsAllowed()` takes the origin.
- **The cache sweep deleted files it had not written.** `cleanExpired()` removed everything in
  `CACHE_DIRECTORY` older than the ttl regardless of name, so pointing it at a directory the
  application already used lost data there. Only entries in this class's own `sha1().json` form are
  removed, and never through a link.
- **Thumbnails decoded an image before checking its size.** The dimensions come from the header long
  before the pixels do, and a small compressed file can demand gigabytes once expanded — for every
  image in the folder, on every listing. Anything over 100 Mpx, or 50 000 px on a side, is refused
  without decoding. The upload size limit bounds the bytes on disk, which says nothing about this.

- **Requests from another site are refused.** Beyond the header above, `mkdir`, `delete`, `rename`,
  `copy`, `move` and `upload` also reject a request the browser reports as cross-site
  (`Sec-Fetch-Site`) or carrying a foreign `Origin`, unless that origin is configured. When the
  browser reports neither, the request goes through: both headers are filled in by the browser and
  cannot be forged or suppressed by a script, so their absence means an old browser or a client that
  is not a browser, not an attack.
- **Permissions can be decided by the application.** `Config::requireCallback()` receives the action
  and the storage name and its answer is final; the `storages` response is filtered through it too,
  so a storage the caller may not reach is not even listed. It deliberately does not receive the
  path — `rename` and `move` change it, so a rule bound to a folder would stop holding the moment a
  file left it. Separate content by giving each user their own storage.
- Every access refusal now answers with the same message, so the response no longer says which
  check objected.
- `OPTIONS` preflight is answered, and the duplicated `Access-Control-Allow-Origin` from
  `allowAccessFromDomain('*')` is gone. Responses also carry `X-Content-Type-Options: nosniff`.
- Path traversal: `LocalStorage::absPath()` now canonicalizes both the request path and `BASE_DIR`
  with `realpath()` and refuses anything landing outside. `..`, nested `..` and absolute paths were
  all accepted before, for listing as well as for delete, upload, rename and mkdir.
- Item names are validated in `Request` for every parameter that carries one — `files[]`, `folder`,
  `old`, `new` — and at two strengths. Names of items that already exist only have to be free of
  path escapes, because a file predating the installation may legitimately carry a name this
  connector would never create and still has to be deletable. Names being created additionally have
  to be usable: no `< > : " / \ | ? *`, no control characters, no trailing dot or space, no Windows
  device names.
- Basic authentication compared the submitted password against the configured **user name**, so
  anyone who knew the name got in by sending it twice — while the correct password was rejected.
  `requireBasicAuth()` was effectively no protection. Both halves are now compared against the right
  value and through `hash_equals()`. The regression test is new as well: the original one never
  tried a *correct* login, which is why the bug survived.
- A read-only storage refused a delete only after performing it: `assertNotReadOnly()` ran after
  `delete()`.
- The session requirement could not tell "no session started" from "variable missing", so a missing
  session silently satisfied it.
- An upload larger than `post_max_size` is now reported. PHP discards `$_POST` and `$_FILES`
  entirely in that case and says nothing, so the request used to look like an empty one.
- `move_uploaded_file()` return value is checked; a failed write was reported as a successful
  upload.

### Fixed

- **`OPTION_OVERWRITE_FILES` applied to uploads only.** Copy, move and rename replaced an existing
  file in silence whatever it was set to, and the return values of `copy()` and `rename()` were not
  checked, so a failed write was reported as a success. Both are fixed; a collision is reported
  before the first change rather than halfway through the batch.
- **An SVG in a listed folder raised a PHP notice on every listing.** `mime_content_type()` reports
  `image/svg+xml`, which the listing took for a raster image and passed to `getimagesize()`. With
  `display_errors` on, the notice landed in the response body and broke the JSON — the same way the
  unwritable `.htdircache` used to. A vector image now simply gets no dimensions and no thumbnail.
- **Copy and move inside a subfolder.** `absPath()` returns a path without a trailing slash,
  including for the storage root, so `$sourceDir . $file` produced `…/subfile.txt`. The whole test
  suite stayed green because every test worked in the root.
- The same missing slash in `BASE_URL . $path` gave every file in a subfolder a wrong URL.
- File names and path segments are passed through `rawurlencode()`, so a name with a space or a
  diacritic yields a usable URL.
- Listing a directory compared an `SplFileInfo` object against the string `'dir'`, which is always
  true, so `mime_content_type()` and `getimagesize()` ran on directories as well. Nothing broke, but
  it was pointless I/O on every cache miss.
- Neither `Thumbnail` nor `Storage` calls the deprecated release functions any more —
  `imagedestroy()` and `finfo_close()`. Both have been no-ops for years and are deprecated as of PHP
  8.5, but "deprecated yet working" is not a neutral state: in an application that escalates
  deprecations to errors, `finfo_close()` made **every upload fail with a 500**, because the content
  check runs on each uploaded file. Reading worked, writing did not.
- `File::$width` and its siblings default to `null`. They were typed but uninitialized, so a
  `getWidth()` before `setWidth()` raised `Error: must not be accessed before initialization` — not
  reachable through the shipped storage, but a trap for anyone writing another one.

### Added

- `Response::getHttpStatus()`, so a framework controller can put the outcome into a response object
  of its own instead of letting `sendOutput()` echo it. README gained a controller example.

- **The directory cache is configurable and no longer breaks read-only storages.** Writing
  `.htdircache` into a directory that is not writable made PHP emit a warning on *every* request,
  which with `display_errors` on landed in the response body and broke the JSON. The order is now:
  cache disabled → `OPTION_CACHE_DIRECTORY` → `.htdircache` next to the data if that is writable →
  do not cache, silently. `OPTION_CACHE_ENABLED` defaults to `true`.

  `OPTION_CACHE_ENABLED` exists because `.htdircache` reveals a *listing* — names, sizes, times and
  base64 thumbnails of everything in the folder. The files themselves are public anyway, since
  `BASE_URL` has to be reachable; the difference between "public if you know the name" and "here is
  a list of everything" is what this turns off.

  Validity is decided by the folder's mtime, stored inside the cache file rather than compared
  between files — writing the cache into the folder it describes changes that folder's mtime, so
  the entry would look stale the moment it was written. The two-hour TTL remains as an upper bound,
  because editing a file in place does not change the folder's mtime.
- Storages report their limits to the client: the storage description now carries `max_file_size`
  and `allowed_extensions`, so the manager can state them and refuse a file before transferring it.
  The server checks every upload regardless; this only saves the transfer.
- `Cache` is part of the library (it was a copy inside `DosieroFtp`). Keys are hashed from the
  storage name and the path, so `a/b` and `a_b` no longer collide and two storages with the same
  path no longer share an entry. Expired entries are cleaned probabilistically, once in fifty
  writes, and only for an external cache directory — a file next to the data goes away with the
  folder.
- README gained a description of the wire protocol, guidance for securing the data directory and a
  section on the php.ini settings that bound uploads.

### Development

- **The licence is MIT**, matching the client. `composer.json` declared `WTFPL` and no `LICENSE`
  file existed, so the package stated a licence nothing in the repository carried.
- **The Api suite runs in CI.** It drives the connector over real HTTP, which is the only place the
  CORS and preflight headers exist at all — no in-process test can see them. What kept it local was
  the entry point: the suite pointed at `/index.php`, which is gitignored because it doubles as the
  developer's playground. A committed `tests/Support/Endpoint/index.php` is now the suite's entry
  point, served by php's built-in server in CI and by Apache locally, so both run the same file. Six
  cases cover the preflight, the echoed origin, a foreign origin, a cross-site mutation,
  `Sec-Fetch-Site` and `nosniff`.
- README gained a storage-options table with defaults, the symlink and overwrite policies, the
  resource limits, an exception-to-status table and a local development section.

- Tooling moved from bundled phars to Composer dev dependencies: Codeception 5, PHPStan 2 at level
  max with strict and deprecation rules, Rector 2 and Easy Coding Standard.
- **PHPStan at level max is green**, from 58 findings. Most of them came from one root cause:
  `copy()`, `delete()`, `move()` and `upload()` declared a bare `array $files`, so every name and
  every upload field was `mixed` from there on. `StorageInterface` now states the value types and
  carries an `UploadedFile` shape, and `Request` is the single place where superglobals become
  typed values — it drops `$_FILES` entries that do not carry the five scalar keys PHP fills in for
  a file input, since those cannot be uploads. Two of the fixes are behavioural, not cosmetic:
  `imagecreatetruecolor()` is no longer called with a zero dimension, and a failed
  `imagecolorallocate()` no longer fills a GIF thumbnail with colour index `-1`.
- Test suites renamed to the Codeception 5 convention (`tests/Unit`, `tests/Integration`,
  `tests/Api`, `tests/Support`).
- `AccessTest` used to unset the whole `$_SERVER`, which crashed PHPUnit at the start of the next
  suite — and that had been hiding the entire `Api` suite, ten tests that had never run. Both are
  fixed. A green suite says nothing about whether every suite ran.
