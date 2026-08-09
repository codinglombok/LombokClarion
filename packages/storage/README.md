# lombokclarion/storage

**Local filesystem storage with path-traversal blocking and secure file uploads.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/storage
```

## Namespace

```php
LombokClarion\Storage
```

## What's Inside

| Class | Role |
|-------|------|
| `Storage` | Interface: `put()`, `get()`, `exists()`, `delete()`, `store()` |
| `LocalStorage` | Filesystem implementation rooted at one directory |

## Usage

```php
use LombokClarion\Storage\LocalStorage;

$storage = new LocalStorage('/app/storage/app');

$storage->put('reports/q3.txt', $content);
$content = $storage->get('reports/q3.txt');
$storage->exists('reports/q3.txt'); // true
$storage->delete('reports/q3.txt');

// Uploaded file (random name, validated extension)
$path = $storage->store($uploadedFile, 'avatars', 'jpg');
// → "avatars/a1b2c3d4e5f6.jpg"
```

### Security

All paths are validated against directory traversal — `../`, absolute paths, and null bytes are rejected.
File names are generated randomly (16 bytes hex), never derived from client filenames.

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
