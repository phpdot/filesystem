# phpdot/filesystem

A coroutine-safe, PSR-native file storage suite for PHP 8.3+. One friendly operator over local disk and S3-compatible backends (AWS S3, Cloudflare R2, MinIO, DigitalOcean Spaces), with typed streams, **content validation**, **server-side path generation**, **tracked file records**, resumable chunked uploads, and first-class progress — all over a hand-rolled PSR-18 + SigV4 client.

No AWS SDK. No Flysystem. No build step. Works with or without Swoole — the core has no `ext-swoole` dependency and lights up automatically under coroutine hooks.

---

## Table of Contents

- [Install](#install)
- [Quick Start](#quick-start)
- [Why phpdot/filesystem](#why-phpdotfilesystem)
- [Architecture](#architecture)
- [Storage Backends](#storage-backends)
  - [Local Disk](#local-disk)
  - [S3-Compatible](#s3-compatible)
  - [In-Memory](#in-memory)
- [Reading & Writing](#reading--writing)
- [Progress](#progress)
- [Listing & Metadata](#listing--metadata)
- [Visibility & URLs](#visibility--urls)
- [Validation](#validation)
- [Path Generation](#path-generation)
- [Managed Files](#managed-files)
  - [Bring Your Own Repository](#bring-your-own-repository)
  - [Soft-Delete & Quarantine](#soft-delete--quarantine)
  - [Drafts, Expiry & Purge](#drafts-expiry--purge)
- [Resumable Uploads](#resumable-uploads)
- [CLI Commands](#cli-commands)
- [Events](#events)
- [Errors](#errors)
- [Container Integration](#container-integration)
- [Running under Swoole](#running-under-swoole)
- [Development](#development)
- [License](#license)

---

## Install

```bash
composer require phpdot/filesystem
```

| Requirement | Version | Notes |
|---|---|---|
| PHP | >= 8.3 | |
| ext-fileinfo | * | content MIME sniffing |
| ext-hash | * | checksums |
| league/mime-type-detection | ^1.15 | |
| psr/http-message · psr/http-factory · psr/http-client | ^2 · ^1 · ^1 | provide a PSR-17 factory (e.g. `nyholm/psr7`) |
| psr/event-dispatcher | ^1.0 | optional events |
| guzzlehttp/guzzle | ^7.5 | optional — a PSR-18 client for the S3 backend |
| phpdot/console | ^2 | optional — the `filesystem:*` CLI commands |
| phpdot/http | ^2 | optional — the resumable (tus) PSR-15 endpoint |
| phpdot/container · phpdot/config | ^1 | optional — attribute auto-wiring |

The core depends only on PSR interfaces and `league/mime-type-detection` — zero `phpdot/*` packages. Each integration above is opt-in.

---

## Quick Start

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\LocalAdapter;
use PHPdot\Filesystem\Filesystem;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\Write\WriteContents;

$psr17      = new Psr17Factory();
$adapter    = new LocalAdapter(new FilesystemConfig(root: '/var/storage'), $psr17);
$filesystem = new Filesystem($adapter, new WriteContents($psr17));

$filesystem->write('invoices/2026.pdf', $pdfBytes);            // string | PSR-7 stream | UploadedFile
$filesystem->write('avatar.png', $request->getUploadedFiles()['avatar']);

$pdf = $filesystem->read('invoices/2026.pdf');
$url = $filesystem->url('invoices/2026.pdf');                  // public or presigned, by visibility

foreach ($filesystem->listContents('invoices', deep: true) as $entry) {
    echo $entry->path(), PHP_EOL;
}
```

In a PHPdot application you skip the wiring entirely — the container auto-binds `FilesystemInterface`. **You talk to one interface; swap the backend by swapping one binding.**

---

## Why phpdot/filesystem

- **Backend-agnostic.** One `FilesystemInterface` over local disk, AWS S3, R2, MinIO and Spaces. The operator API never changes; you rebind the adapter.
- **Streaming, bounded memory.** Bodies flow as PSR-7 streams; a multi-gigabyte upload writes in fixed-size chunks. Validation sniffs only a bounded prefix — it never buffers the whole body.
- **No AWS SDK.** A hand-rolled PSR-18 + Signature V4 client (header-auth and query presigning), validated against AWS's published signing vectors and real S3.
- **Validation built in.** A collect-all pipeline over an immutable subject — content MIME, size, extension and image dimensions — gathered once, coroutine-safe.
- **Server picks the key.** A token path engine (`{date}/{uuid}/{hash}`…) with crypto-random entropy and collision-retry, so the browser never dictates where bytes land.
- **Tracked records.** An optional managed-files layer persists a `FileRecord` per file through a pluggable repository (JSON by default; bring your own DB), with soft-delete + quarantine, drafts and expiry.
- **Resumable.** A tus-compatible PSR-15 endpoint and a CLI uploader, both over the same multipart engine.
- **Coroutine-safe.** No `ext-swoole` dependency; native and curl I/O go non-blocking automatically under `SWOOLE_HOOK_ALL`. No per-request mutable state in the shared services.
- **Strict.** `declare(strict_types=1)` throughout, PHPStan level 10 with strict rules, zero ignored errors.

---

## Architecture

```
src/
├── Filesystem.php              # the operator — FilesystemInterface (#[Singleton], inject this)
├── FilesystemConfig.php        # #[Config('filesystem')] — root, visibility, chunk size, paths…
├── Config.php                  # immutable per-operation options bag
├── Contract/                   # FilesystemInterface, AdapterInterface, capability + repo contracts
├── Adapter/
│   ├── LocalAdapter.php        # native fopen/rename — atomic, SWOOLE_HOOK_FILE-safe
│   ├── InMemoryAdapter.php     # fast, side-effect-free (tests)
│   └── S3/                     # S3Adapter, S3Client, S3Config, SignatureV4 (no AWS SDK)
├── Write/WriteContents.php     # collapse string|Stream|UploadedFile → a readable stream
├── Stream/ProgressStream.php   # pass-through byte counter
├── Validation/                 # FileSubject, ValidatorPipeline, ValidationResult, 4 validators
├── Path/                       # PathGenerator (tokens), WhitespacePathNormalizer, PathPrefixer
├── Upload/                     # UploadManager + SessionStore (resumable multipart engine)
├── Http/                       # ResumableUploadHandler, ManagedResumableUploadHandler (tus PSR-15)
├── ManagedFiles/               # Files facade, FileRecord, FileRepositoryInterface + Local/Null impls
├── Cli/                        # filesystem:upload · purge-sessions · purge-files
├── Event/                      # PSR-14 upload/file events
└── Exception/                  # FilesystemException taxonomy with stable error codes
```

Flow: `Filesystem::write()` normalizes the path, collapses the input union to a stream via `WriteContents`, wraps it in a `ProgressStream`, and hands it to the bound `AdapterInterface`. The adapter never sees a raw resource above its own floor; optional capabilities (checksum, URLs, multipart) are probed with `instanceof` and gracefully degraded.

---

## Storage Backends

Swap backends by swapping the adapter — the operator API is identical for all.

### Local Disk

```php
new LocalAdapter(new FilesystemConfig(root: '/var/storage', publicUrl: 'https://cdn.example.com'), $psr17);
```

Native `fopen`/`fwrite`/`rename` I/O — writes land atomically (temp file + rename) and go non-blocking under `SWOOLE_HOOK_FILE`. Supports checksums, multipart, and public URLs.

### S3-Compatible

```php
use GuzzleHttp\Client;
use PHPdot\Filesystem\Adapter\S3\{S3Adapter, S3Client, S3Config, SignatureV4};

$config = new S3Config(
    bucket: 'my-bucket',
    region: 'us-east-1',
    key:    getenv('AWS_ACCESS_KEY_ID'),
    secret: getenv('AWS_SECRET_ACCESS_KEY'),
    // Cloudflare R2:  endpoint: 'https://<acct>.r2.cloudflarestorage.com', region: 'auto'
    // MinIO:          endpoint: 'http://localhost:9000', pathStyle: true
    prefix: 'uploads',
);
$adapter = new S3Adapter(new S3Client(new Client(), $psr17, $psr17, new SignatureV4(), $config), $config);
```

Talks to S3 over a built-in Signature V4 client (no AWS SDK): multipart uploads, `ListObjectsV2` pagination, virtual-hosted and path-style URLs, and SigV4 presigning for temporary URLs.

### In-Memory

`new InMemoryAdapter($psr17)` — a fast, side-effect-free backend for tests, implementing the same contract.

| Capability | Local | S3 | In-Memory |
|---|---|---|---|
| Checksums (`ChecksumProvider`) | ✓ | ✓ | ✓ |
| Public URLs (`PublicUrlGenerator`) | ✓ | ✓ | — |
| Temporary/presigned URLs (`TemporaryUrlGenerator`) | — | ✓ | — |
| Multipart (`MultipartCapable`) | ✓ | ✓ | — |

---

## Reading & Writing

`write()` accepts a `string`, a PSR-7 `StreamInterface`, or a PSR-7 `UploadedFileInterface` — typed end to end, never a raw resource:

```php
$filesystem->write('a.txt', 'plain string');
$filesystem->write('b.bin', $psr7Stream);
$filesystem->write('c.png', $uploadedFile, ['visibility' => 'public']);

$bytes  = $filesystem->read('a.txt');
$stream = $filesystem->readStream('b.bin');     // a StreamInterface, never a resource

$filesystem->copy('a.txt', 'copy.txt');
$filesystem->move('a.txt', 'moved.txt');
$filesystem->delete('moved.txt');
```

| Method | Returns | Notes |
|---|---|---|
| `write($path, $contents, $config = [])` | `void` | `string \| StreamInterface \| UploadedFileInterface` |
| `read($path)` · `readStream($path)` | `string` · `StreamInterface` | prefer `readStream` for large files |
| `copy` · `move` · `delete` · `deleteDirectory` | `void` | |
| `createDirectory($path, $config = [])` | `void` | |
| `fileExists` · `directoryExists` · `has` | `bool` | |

All paths pass through a hardened normalizer that rejects `..` traversal and control characters before they reach a backend.

---

## Progress

Every write accepts a progress callback — `callable(int $soFar, ?int $total)` — that fires as bytes flow, regardless of how the backend consumes the body (`$total` is `null` when the size is unknown):

```php
use PHPdot\Filesystem\Config;

$filesystem->write('large.iso', $stream, [
    Config::PROGRESS => fn (int $soFar, ?int $total) => $bar->setProgress($soFar, $total),
]);
```

| `Config::` constant | Type | Purpose |
|---|---|---|
| `PROGRESS` | `callable` | per-write byte progress |
| `VISIBILITY` · `DIRECTORY_VISIBILITY` | `'public' \| 'private'` | override visibility |
| `MIME_TYPE` | `string` | explicit content type |
| `CHUNK_SIZE` | `int` | multipart part size |
| `EXPIRES_AT` | `DateTimeInterface` | temporary-URL expiry |
| `RETAIN_VISIBILITY` | `bool` | keep visibility on copy/move |

---

## Listing & Metadata

```php
$listing = $filesystem->listContents('photos', deep: true);   // a lazy DirectoryListing
$images  = $listing->filter(fn ($e) => $e->isFile())->sortByPath();

$filesystem->fileSize('a.txt');        // int
$filesystem->lastModified('a.txt');    // int (unix timestamp)
$filesystem->mimeType('a.txt');        // string
$filesystem->checksum('a.txt');        // sha256 by default; streams + hashes if the backend can't
```

`DirectoryListing` is `iterable<StorageAttributes>` with lazy `filter()` / `map()` / `sortByPath()` / `toArray()`. Every entry exposes `path()`, `isFile()`, `isDir()`, `lastModified()` and `visibility()`; file entries are `FileAttributes`, which additionally carry `fileSize()` and `mimeType()`.

---

## Visibility & URLs

```php
use PHPdot\Filesystem\Visibility;
use PHPdot\Filesystem\Config;

$filesystem->setVisibility('a.txt', Visibility::Public);
$filesystem->visibility('a.txt');                                  // Visibility::Public

$filesystem->publicUrl('a.txt');
$filesystem->temporaryUrl('a.txt', new DateTimeImmutable('+15 minutes'));   // SigV4 presigned on S3

// Visibility-aware: a public URL for a public object, otherwise a temporary one.
$filesystem->url('a.txt');
$filesystem->url('a.txt', [Config::EXPIRES_AT => new DateTimeImmutable('+1 hour')]);
```

Local maps visibility to permission bits; on S3 it is bucket-policy controlled (modern AWS and R2 disable per-object ACLs). Probe capability without catching exceptions via `supportsPublicUrls()` / `supportsTemporaryUrls()`.

---

## Validation

A standalone, **collect-all** pipeline — it gathers *every* violation rather than failing on the first. A `FileSubject` wraps the body and sniffs its facts (size, content MIME, image dimensions) once, from a bounded prefix; the declared name is fixed at construction, so concurrent stores never cross filenames.

```php
use PHPdot\Filesystem\Validation\{ValidatorPipeline, FileSubject};
use PHPdot\Filesystem\Validation\{FileSizeValidator, MimeTypeValidator, ExtensionValidator, ImageDimensionsValidator};

$subject = FileSubject::fromContents($uploadedFile, 'avatar.png', $writeContents, $psr17);

$result = (new ValidatorPipeline(
    new FileSizeValidator(maxBytes: 5_000_000),
    new MimeTypeValidator(['image/png', 'image/jpeg']),   // content-sniffed, not the declared name
    new ExtensionValidator(['png', 'jpg', 'jpeg']),
    new ImageDimensionsValidator(maxWidth: 4096, maxHeight: 4096),
))->validate($subject);

if (!$result->isValid()) {
    foreach ($result->violations() as $v) {
        echo $v->code, ': ', $v->message, PHP_EOL;
    }
}

$result->throwIfInvalid();   // or throws FileValidationFailed carrying every violation
```

| Validator | Checks |
|---|---|
| `MimeTypeValidator(array $allowed)` | content-sniffed MIME against an allowlist |
| `FileSizeValidator(int $maxBytes, int $minBytes = 0)` | byte size bounds |
| `ExtensionValidator(array $allowed)` | extension of the *declared* name |
| `ImageDimensionsValidator(int $maxWidth, int $maxHeight, int $minWidth = 0, int $minHeight = 0)` | pixel bounds (skips non-images) |

A `Validator` returns `iterable<Violation>` — it never throws for "invalid". Add your own by implementing the interface.

---

## Path Generation

`PathGenerator` renders a server-side storage key from a token pattern, so the browser never dictates where bytes land. Entropy comes from `random_bytes` (never `mt_rand`), and `generate()` retries with fresh entropy when an existence probe reports a collision.

```php
use PHPdot\Filesystem\Path\PathGenerator;

$key = (new PathGenerator())->generate(
    '{date}/{uuid}{ext}',
    $subject,
    fn (string $candidate): bool => $filesystem->fileExists($candidate),   // optional collision probe
);
// e.g. 2026/06/23/9f3c…-… .png
```

| Token | Renders |
|---|---|
| `{date}` `{year}` `{month}` `{day}` | UTC date parts (`{date}` = `Y/m/d`) |
| `{uuid}` | a crypto-random UUID v4 |
| `{random:N}` | `N` hex chars of entropy |
| `{hash:algo}` | a streamed hash of the body (`sha256` default) |
| `{ext}` `{name}` `{safe_name}` | extension · filename · slugified filename |

Register custom tokens with `addToken(string $name, callable $resolver)`.

---

## Managed Files

The optional managed-files layer turns "store bytes" into "store and **track** files". The `Files` facade composes the operator + validation + path generation + a record repository — the core byte writer is untouched. Inject `Files` when you want tracking, `FilesystemInterface` when you just want bytes.

```php
use PHPdot\Filesystem\ManagedFiles\{Files, FileContext};

$record = $files->store($uploadedFile, new FileContext(
    originalName: 'avatar.png',
    reference: 'user',
    referenceId: (string) $userId,
    tags: ['avatar'],
    visibility: Visibility::Public,
    validators: [new MimeTypeValidator(['image/png', 'image/jpeg'])],
));

$record->id;          // tracked id
$record->path;        // server-generated storage key
$record->size;        // bytes
$files->url($record->id);
```

`store()` validates → generates a key → writes → persists a `FileRecord` and returns it. A `FileRecord` carries id, path, original name, size, mime, checksum, visibility, `reference`/`referenceId`, tags, draft/expiry and soft-delete bookkeeping — immutable, with copy-on-write mutators.

| `Files` method | Purpose |
|---|---|
| `store($contents, FileContext)` | validate, key, write, persist a record |
| `storeDraft($contents, FileContext)` | store as a draft that expires unless published |
| `publish($id)` | promote a draft to permanent |
| `delete($id)` | soft-delete + quarantine the bytes |
| `restore($id)` | reverse a soft-delete |
| `purge($now)` | hard-delete expired drafts + soft-deleted past retention |
| `url($id)` · `repository()` | visibility-aware URL · the underlying repository |

### Bring Your Own Repository

The repository is the seam. The package ships `LocalFileRepository` (JSON sidecars) as the working default and `NullFileRepository` to opt out — but the contract is **DTO-based**, so binding your own database is one class and one binding:

```php
interface FileRepositoryInterface
{
    public function save(FileRecord $record): FileRecord;
    public function find(string $id): ?FileRecord;
    public function findByPath(string $path): ?FileRecord;
    /** @return array{records: list<FileRecord>, total: int} */
    public function search(FilesFilter $filter, int $limit = 20, int $offset = 0): array;
    public function softDelete(string $id): void;
    public function hardDelete(string $id): void;
}
```

```php
// rebind to MySQL, MongoDB, Eloquent… — the managed-files layer is unchanged
$container->set(FileRepositoryInterface::class, fn ($c) => new MongoFileRepository($c->get(Database::class)));
```

### Soft-Delete & Quarantine

`delete()` does not destroy bytes — it **moves them to a private, high-entropy quarantine key** (invalidating any leaked public URL), flips visibility to private, and remembers the original path and visibility so `restore()` can reverse it. The move happens first; if it fails the record is left intact, never orphaned as "deleted".

### Drafts, Expiry & Purge

`storeDraft()` (or a resumable upload's `registerUpload()`) creates a record with a TTL. Unpublished drafts and soft-deleted records past their retention are swept by `Files::purge()` or the `filesystem:purge-files` command.

---

## Resumable Uploads

One multipart engine backs both a tus-style HTTP endpoint and the CLI: S3 multipart, or local write-at-offset with an atomic rename on completion. Sessions persist via a `SessionStoreInterface` (a JSON sidecar by default, shared across Swoole workers).

```php
use PHPdot\Filesystem\Http\{ResumableUploadHandler, ManagedResumableUploadHandler};

// Records-free — POST /uploads · HEAD/PATCH/DELETE /uploads/{id}
$handler = new ResumableUploadHandler($uploadManager, $psr17);

// Also tracks records — registers a draft FileRecord at POST, finalizes it on completion
$tracked = new ManagedResumableUploadHandler($uploadManager, $files, $psr17);
```

Both implement PSR-15 `RequestHandlerInterface` and speak the tus protocol (`Upload-Offset`, `Upload-Length`, `Upload-Metadata`). Drive it directly with `UploadManagerInterface` (`create` / `writeChunk` / `complete` / `abort` / `status` / `purgeExpired`) if you don't want HTTP.

---

## CLI Commands

Behind `phpdot/console` (`#[AsCommand]`):

| Command | Does |
|---|---|
| `filesystem:upload <source> <destination>` | stream a local file to storage with a progress bar |
| `filesystem:purge-sessions` | drop expired resumable-upload sessions + abort their multipart uploads |
| `filesystem:purge-files` | hard-delete expired drafts + soft-deleted records past retention |

---

## Events

Inject a PSR-14 dispatcher and the operator and managed layer emit value-object events for loggers and metrics:

| Event | When |
|---|---|
| `UploadProgressed` | bytes flow during a write |
| `UploadCompleted` · `UploadFailed` | a write succeeds · throws |
| `FileStored` · `FileDeleted` | a managed record is stored · soft-deleted |

Events are observers only — never the mechanism behind a write or a record.

---

## Errors

Everything thrown implements the `FilesystemException` marker (catch it for "anything from the library"); adapter-level failures add `FilesystemOperationFailed` with an `operation()`. Each exception carries a stable, machine-readable `errorCode()`:

```php
try {
    $filesystem->write('x', $bytes);
} catch (FilesystemException $e) {
    $e->errorCode();   // e.g. 'filesystem.write_failed', 'filesystem.path_traversal'
}
```

`FileValidationFailed::violations()` carries every collected violation; `S3RequestFailed` exposes `status()` and `awsErrorCode()`.

---

## Container Integration

In a PHPdot app, attribute discovery wires everything — no service provider. `LocalAdapter` is bound to `AdapterInterface` and `Filesystem` to `FilesystemInterface`, so you inject the interface:

```php
public function __construct(private FilesystemInterface $filesystem) {}
```

Services carry `#[Singleton]` + `#[Binds(...)]`; `FilesystemConfig` is a `#[Config('filesystem')]` DTO hydrated from `config/filesystem.php`:

| `FilesystemConfig` field | Default | |
|---|---|---|
| `root` | `'storage'` | local root / S3 prefix |
| `visibility` | `'private'` | default visibility |
| `publicUrl` | `null` | CDN base for local public URLs |
| `chunkSize` | `8388608` | multipart part size |
| `sessionDirectory` · `sessionTtl` | `storage/.uploads` · 1 day | resumable sessions |
| `defaultPathPattern` | `{date}/{uuid}{ext}` | server-side key pattern |
| `fileRecordsDirectory` | `storage/.files` | default JSON record store |
| `draftTtl` · `softDeleteRetention` | 1 day · 30 days | managed-files lifecycle |

To use S3, rebind `AdapterInterface` → `S3Adapter` (it needs credentials, so it isn't auto-bound). To persist records in your database, rebind `FileRepositoryInterface`. All shared services are stateless — **coroutine-safe** under Swoole.

---

## Running under Swoole

The core has no `ext-swoole` dependency, so it installs and runs anywhere — plain CLI, FPM or Apache run it blocking. Inside a Swoole server with coroutine hooks enabled (`SWOOLE_HOOK_ALL`), its native filesystem and curl I/O become non-blocking automatically: same package, same code, no extra dependency. The adapters hold no per-request mutable state, so they are safe to share across coroutines.

---

## Development

```bash
composer test      # PHPUnit  (210 unit + live-S3 integration)
composer analyse   # PHPStan level 10, strict rules
composer cs-check  # php-cs-fixer dry run
composer check     # all three
```

The S3 integration suite runs against real AWS when `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION` and `PHPDOT_S3_TEST_BUCKET` are set, and self-skips otherwise.

---

## License

MIT — see [LICENSE](LICENSE).
