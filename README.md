# phpdot/filesystem

A coroutine-safe, PSR-native file storage suite for the PHPdot ecosystem. One
friendly operator over local disk and S3-compatible backends (AWS S3, Cloudflare
R2, MinIO, DigitalOcean Spaces), with typed streams, resumable chunked uploads,
and first-class progress reporting — all over a hand-rolled PSR-18 + SigV4
client, no AWS SDK and no Flysystem.

```php
$filesystem->write('invoices/2026.pdf', $pdfBytes);
$filesystem->write('avatar.png', $request->getUploadedFiles()['avatar']);   // PSR-7 UploadedFile

$pdf  = $filesystem->read('invoices/2026.pdf');
$url  = $filesystem->temporaryUrl('invoices/2026.pdf', new DateTimeImmutable('+10 minutes'));

foreach ($filesystem->listContents('invoices', deep: true) as $entry) {
    echo $entry->path(), PHP_EOL;
}
```

## Contents

- [Install](#install)
- [Getting started](#getting-started)
- [Backends](#backends)
- [Reading & writing](#reading--writing)
- [Progress](#progress)
- [Listing & metadata](#listing--metadata)
- [Visibility](#visibility)
- [URLs](#urls)
- [Resumable uploads](#resumable-uploads)
- [Events](#events)
- [Errors](#errors)
- [Container integration](#container-integration)
- [Running under Swoole](#running-under-swoole)

## Install

```bash
composer require phpdot/filesystem
```

The core depends only on PSR interfaces (`psr/http-message`, `psr/http-factory`,
`psr/http-client`, `psr/event-dispatcher`) and `league/mime-type-detection`.
Provide a concrete PSR-17 factory (e.g. `nyholm/psr7`) and, for S3, a PSR-18
client (e.g. `guzzlehttp/guzzle`).

## Getting started

The operator is `Filesystem`; you talk to the `FilesystemInterface`. Wire it
once over an adapter:

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\LocalAdapter;
use PHPdot\Filesystem\Filesystem;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\Write\WriteContents;

$psr17      = new Psr17Factory();
$adapter    = new LocalAdapter(new FilesystemConfig(root: '/var/storage'), $psr17);
$filesystem = new Filesystem($adapter, new WriteContents($psr17));
```

In a PHPdot application you skip all of this — the container auto-binds
`FilesystemInterface` (see [Container integration](#container-integration)).

## Backends

Swap backends by swapping the adapter; the operator API never changes.

**Local disk**

```php
new LocalAdapter(new FilesystemConfig(root: '/var/storage', publicUrl: 'https://cdn.example.com'), $psr17);
```

**S3-compatible** (AWS S3, R2, MinIO, Spaces) — over the built-in SigV4 client:

```php
use GuzzleHttp\Client;
use PHPdot\Filesystem\Adapter\S3\{S3Adapter, S3Client, S3Config, SignatureV4};

$config = new S3Config(
    bucket: 'my-bucket',
    region: 'us-east-1',
    key:    getenv('AWS_ACCESS_KEY_ID'),
    secret: getenv('AWS_SECRET_ACCESS_KEY'),
    // R2:    endpoint: 'https://<acct>.r2.cloudflarestorage.com', region: 'auto'
    // MinIO: endpoint: 'http://localhost:9000', pathStyle: true
);
$client  = new S3Client(new Client(), $psr17, $psr17, new SignatureV4(), $config);
$adapter = new S3Adapter($client, $config);
```

**In-memory** — `InMemoryAdapter`, a fast, side-effect-free backend for tests.

## Reading & writing

`write()` accepts a `string`, a PSR-7 `StreamInterface`, or a PSR-7
`UploadedFileInterface` — typed end to end, no raw resources:

```php
$filesystem->write('a.txt', 'plain string');
$filesystem->write('b.bin', $psr7Stream);
$filesystem->write('c.png', $uploadedFile, ['visibility' => 'public']);

$bytes  = $filesystem->read('a.txt');
$stream = $filesystem->readStream('b.bin');     // never a raw resource

$filesystem->copy('a.txt', 'copy.txt');
$filesystem->move('a.txt', 'moved.txt');
$filesystem->delete('moved.txt');
```

For large files, prefer a file-backed stream (`readStream()` /
`createStreamFromFile()`) over the string form — the body is then streamed in
bounded chunks, so memory stays flat regardless of file size.

## Progress

Every write accepts a progress callback — `callable(int $soFar, ?int $total)` —
that fires as bytes flow, regardless of how the backend consumes the body
(`$total` is `null` when the size is unknown):

```php
use PHPdot\Filesystem\Config;

$filesystem->write('large.iso', $stream, [
    Config::PROGRESS => fn (int $soFar, ?int $total) => $bar->setProgress($soFar, $total),
]);
```

The terminal upload command and the browser endpoint are just two consumers of
this same callback.

## Listing & metadata

```php
$listing = $filesystem->listContents('photos', deep: true);   // a lazy DirectoryListing
$images  = $listing->filter(fn ($entry) => $entry->isFile())->sortByPath();

$filesystem->fileSize('a.txt');        // int
$filesystem->lastModified('a.txt');    // int (unix timestamp)
$filesystem->mimeType('a.txt');        // string
$filesystem->checksum('a.txt');        // sha256 by default; streams + hashes if the backend can't
```

## Visibility

```php
use PHPdot\Filesystem\Visibility;

$filesystem->setVisibility('a.txt', Visibility::Public);
$filesystem->visibility('a.txt');      // Visibility::Public
```

Local maps visibility to permission bits. On S3 it is bucket-policy controlled
(modern AWS and R2 disable per-object ACLs).

## URLs

```php
$filesystem->publicUrl('a.txt');
$filesystem->temporaryUrl('a.txt', new DateTimeImmutable('+15 minutes'));   // SigV4 presigned on S3
```

## Resumable uploads

One mechanism backs both the CLI and the browser: S3 multipart, or Local
write-at-offset with an atomic rename. Drive it with `UploadManagerInterface`,
or expose the bundled tus-compatible PSR-15 endpoint:

```php
use PHPdot\Filesystem\Http\ResumableUploadHandler;

$handler = new ResumableUploadHandler($uploadManager, $psr17);   // POST/HEAD/PATCH/DELETE
```

Sessions persist via a `SessionStoreInterface` (a JSON sidecar by default,
shared across Swoole workers; back it with PSR-16 for multi-node). Sweep expired
ones with the `filesystem:purge-sessions` command, and upload from the terminal
with `filesystem:upload <source> <destination>` (both behind `phpdot/console`).

## Events

Inject a PSR-14 dispatcher and the operator emits `UploadProgressed`,
`UploadCompleted`, and `UploadFailed` so loggers and metrics can listen without
touching the progress callback.

## Errors

Everything thrown implements the `FilesystemException` marker (catch it for
"anything from the library"); operation failures add `FilesystemOperationFailed`.
Each carries a stable machine code (e.g. `filesystem.write_failed`,
`filesystem.path_traversal`). All paths pass through a hardened normalizer that
rejects traversal and control characters before they reach a backend.

## Container integration

In a PHPdot app, attribute discovery wires everything — no service provider.
`LocalAdapter` is bound to `AdapterInterface` and `Filesystem` to
`FilesystemInterface`, so you just inject the interface:

```php
public function __construct(private FilesystemInterface $filesystem) {}
```

`FilesystemConfig` (a `#[Config('filesystem')]` DTO) is hydrated from
`config/filesystem.php`. To use S3 instead of local disk, bind `S3Adapter` to
`AdapterInterface` in your container (S3 isn't auto-bound — it needs
credentials).

## Running under Swoole

The core has no `ext-swoole` dependency, so it installs and runs anywhere —
plain CLI, FPM, or Apache run it blocking. Inside a Swoole server with coroutine
hooks enabled (`SWOOLE_HOOK_ALL`), its native filesystem and curl I/O become
non-blocking automatically: same package, same code, no extra dependency. The
adapters hold no per-request mutable state, so they are safe to share across
coroutines.

## License

MIT — see [LICENSE](LICENSE).
