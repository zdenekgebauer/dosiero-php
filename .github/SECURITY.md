# Security policy

## Reporting a vulnerability

Mail **zdenek.gebauer@gmail.com**. Czech, Slovak and English are all fine.

Please do not open a public issue for anything that lets somebody read, write or delete files they
should not reach. Mail first; the issue can follow once there is a fix to point at.

There is no bug bounty. What you get is an answer, credit in the changelog if you want it, and a
fix.

Useful in a report, roughly in order of how much it helps:

- the connector version, the PHP version, and the storage options in play — `BASE_DIR`,
  `ALLOWED_EXTENSIONS`, `OVERWRITE_FILES`, `READ_ONLY`
- the request that triggers it: the `action`, the parameters, and what `Config` was configured with
- what you expected and what happened instead

## Supported versions

| Version | Supported |
| ------- | --------- |
| 1.0.x   | yes       |
| 0.0.x   | no        |

`0.0.1` is from 2021 and predates every fix in this repository, including path traversal, the upload
allowlist and content verification. Treat it as unmaintained.

## This is the half that matters

The client runs in the visitor's browser and enforces nothing — every limit it displays is there to
save a pointless transfer. All of it is re-checked here, so a report about the file manager usually
belongs in this repository rather than in [the client](https://github.com/zdenekgebauer/dosiero).

Of particular interest:

- a path that escapes `BASE_DIR`, by any route — `..`, an absolute path, a symbolic link, a name the
  filesystem normalizes differently
- an upload that lands with a name or a content type the configuration should have refused
- an operation that a `READ_ONLY` storage, or the configured access check, should have stopped
- a response that reveals a filesystem path, a configuration value, or which check refused a request

## Not vulnerabilities

- **An unconfigured connector refuses everything.** Deliberate: a `Config` with no check set is not
  "open by default". It serves nobody until `allowAnonymous()` or a real check is configured.
- **`allowAnonymous()` really does allow anonymous access.** It exists for public demos, and it is
  the caller's decision.
- **The extension allowlist governs what comes in, not what is stored.** Files also arrive by ftp or
  by hand, and renaming those has to stay possible — so renaming enforces only the
  executable-extension rule. Refusing to execute anything in the data directory is a requirement of
  a safe deployment, documented in the README, not a spare layer.

A report that the README recommends something unsafe is welcome. If the documented deployment advice
is wrong, that is a bug worth fixing.
