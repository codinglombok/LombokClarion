<?php

declare(strict_types=1);

use LombokClarion\Http\UploadedFile;
use LombokClarion\Storage\LocalStorage;
use LombokClarion\Storage\Storage;

/**
 * Every test gets a throwaway root under the system tmp dir. No shared
 * fixture state: LocalStorage holds only its root string, so a fresh
 * instance per test is cheap and honest.
 */
function storageTest_root(): string
{
    $root = sys_get_temp_dir() . '/lombokclarion-storage-test-' . bin2hex(random_bytes(6));
    mkdir($root, 0755, true);

    return $root;
}

/** A real tmp file shaped like PHP's upload tmp — content included. */
function storageTest_upload(string $contents, string $clientName = 'photo.png'): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'lcup');
    file_put_contents($tmp, $contents);

    return new UploadedFile(
        clientName: $clientName,
        clientMimeType: 'application/octet-stream', // client claim — irrelevant here
        tmpPath: $tmp,
        size: strlen($contents),
        error: UPLOAD_ERR_OK,
        sapi: false, // documented test path: moveTo() uses rename()
    );
}

// --- traversal / unsafe paths ----------------------------------------------

test('storage: traversal and unsafe paths are hard errors, never normalized', function () {
    $storage = new LocalStorage(storageTest_root());

    // `..` anywhere in the path — leading, embedded, or as the whole path.
    assertThrows(RuntimeException::class, fn () => $storage->put('../escape.txt', 'x'));
    assertThrows(RuntimeException::class, fn () => $storage->get('a/../../b.txt'));
    assertThrows(RuntimeException::class, fn () => $storage->exists('..'));
    assertThrows(RuntimeException::class, fn () => $storage->delete('dir/..'));

    // Absolute forms: POSIX and Windows drive.
    assertThrows(RuntimeException::class, fn () => $storage->put('/etc/passwd', 'x'));
    assertThrows(RuntimeException::class, fn () => $storage->put('C:/windows/win.ini', 'x'));

    // Backslashes are rejected wholesale — no platform guessing.
    assertThrows(RuntimeException::class, fn () => $storage->put('dir\\file.txt', 'x'));

    // NUL byte and degenerate segments.
    assertThrows(RuntimeException::class, fn () => $storage->put("a\0.txt", 'x'));
    assertThrows(RuntimeException::class, fn () => $storage->put('', 'x'));
    assertThrows(RuntimeException::class, fn () => $storage->put('a//b.txt', 'x'));
    assertThrows(RuntimeException::class, fn () => $storage->put('./a.txt', 'x'));
});

// --- put / get / exists / delete --------------------------------------------

test('storage: put/get/exists/delete round-trip, nested directories created', function () {
    $storage = new LocalStorage(storageTest_root());

    assertSame(false, $storage->exists('avatars/2026/a.txt'));

    $storage->put('avatars/2026/a.txt', 'isi berkas');
    assertSame(true, $storage->exists('avatars/2026/a.txt'));
    assertSame('isi berkas', $storage->get('avatars/2026/a.txt'));

    $storage->delete('avatars/2026/a.txt');
    assertSame(false, $storage->exists('avatars/2026/a.txt'));
});

test('storage: get/delete on a missing path are hard errors, not nulls', function () {
    $storage = new LocalStorage(storageTest_root());

    assertThrows(RuntimeException::class, fn () => $storage->get('tidak/ada.txt'));
    assertThrows(RuntimeException::class, fn () => $storage->delete('tidak/ada.txt'));
});

// --- store(): generated names, explicit extensions ---------------------------

test('storage: store() persists under a generated name, never the client name', function () {
    $root = storageTest_root();
    $storage = new LocalStorage($root);

    $upload = storageTest_upload('PNG-bytes-here', clientName: '../../evil.php');
    $relative = $storage->store($upload, 'uploads/avatars', 'png');

    // Shape: directory as given + 32 hex chars (16 random bytes) + explicit ext.
    assertSame(1, preg_match('#^uploads/avatars/[0-9a-f]{32}\.png$#', $relative), $relative);
    assertTrue(!str_contains($relative, 'evil'), 'client name must not leak into the stored path');

    // Actually on disk, contents intact, tmp gone (moved, not copied).
    assertSame('PNG-bytes-here', $storage->get($relative));
    assertSame(false, is_file($upload->tmpPath));

    // Two stores never collide on the same name.
    $second = $storage->store(storageTest_upload('x'), 'uploads/avatars', 'png');
    assertTrue($second !== $relative, 'generated names must differ');
});

test('storage: store() rejects extensions outside the pattern', function () {
    $storage = new LocalStorage(storageTest_root());

    foreach (['', '.png', 'PNG', 'p.hp', 'tar.gz', 'png ', 'exe/../sh', 'abcdefghijk'] as $bad) {
        assertThrows(
            RuntimeException::class,
            fn () => $storage->store(storageTest_upload('x'), 'up', $bad),
            "extension \"$bad\" should be rejected",
        );
    }
});

test('storage: `php` passes the pattern — the guard is the CALLER whitelist, and it is documented', function () {
    // The extension pattern is about filesystem hygiene (lowercase alnum,
    // short, no dot); it is NOT the security boundary for executable types.
    // That boundary is the caller choosing the extension AFTER Rule::file()
    // proved the content type — a caller whose whitelist never yields 'php'
    // cannot store one. This test pins the division of labor so a future
    // "tighten the pattern" change is a conscious decision, not drift.
    $storage = new LocalStorage(storageTest_root());

    $relative = $storage->store(storageTest_upload('<?php echo 1;'), 'up', 'php');
    assertSame(1, preg_match('#^up/[0-9a-f]{32}\.php$#', $relative), $relative);
});

test('storage: store() validates the directory like any other path', function () {
    $storage = new LocalStorage(storageTest_root());

    assertThrows(RuntimeException::class, fn () => $storage->store(storageTest_upload('x'), '../out', 'png'));
    assertThrows(RuntimeException::class, fn () => $storage->store(storageTest_upload('x'), '/abs', 'png'));
});

// --- UploadedFile::moveTo ----------------------------------------------------

test('uploaded file: non-sapi moveTo() moves via rename and is single-shot', function () {
    $upload = storageTest_upload('konten');
    $dest = sys_get_temp_dir() . '/lcmove-' . bin2hex(random_bytes(6)) . '.txt';

    $upload->moveTo($dest);
    assertSame('konten', file_get_contents($dest));
    assertSame(false, is_file($upload->tmpPath), 'tmp file must be gone after the move');

    // Second call: tmp file no longer exists → isValid() false → hard error.
    assertThrows(RuntimeException::class, fn () => $upload->moveTo($dest . '.again'));

    unlink($dest);
});

test('uploaded file: moveTo() on a failed upload is a hard error', function () {
    $broken = new UploadedFile(
        clientName: 'x.png',
        clientMimeType: 'image/png',
        tmpPath: '/nonexistent/tmp',
        size: 0,
        error: UPLOAD_ERR_PARTIAL,
        sapi: false,
    );

    assertThrows(RuntimeException::class, fn () => $broken->moveTo(sys_get_temp_dir() . '/never.txt'));
});

// --- container binding -------------------------------------------------------

test('storage: container resolves Storage to LocalStorage rooted at storage/app', function () {
    $container = (require __DIR__ . '/../bootstrap/services.php')();

    $storage = $container->get(Storage::class);
    assertTrue($storage instanceof LocalStorage);
    assertTrue($storage === $container->get(Storage::class), 'singleton: same instance');
});
