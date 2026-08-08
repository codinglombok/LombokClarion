<?php

declare(strict_types=1);

namespace App\Console;

use LombokClarion\Console\Command;
use LombokClarion\Persistence\QueryBuilder;
use LombokClarion\Security\PasswordHasher;
use PDO;

/**
 * The user-creation path the app did not have.
 *
 * Finding #42: users.id is an app-supplied VARCHAR(32) — by design (no
 * auto-increment coupling) — but NOTHING supplied it: no register route,
 * no seeder, no command. The only way to get a user was a hand-written
 * INSERT, and the natural hand-written INSERT omits the id, which SQLite's
 * legacy quirk then accepts as a NULL primary key. Result: login succeeds,
 * the minted token carries an empty user id, and every subsequent request
 * is an unexplainable 401. The migration now says NOT NULL explicitly
 * (closing the quirk), and this command is the supported way to mint a
 * user:
 *
 *   LOMBOKCLARION_PASSWORD='s3cret' php bin/lombokclarion user:create admin@example.com
 *
 * The password rides an environment variable, not argv — argv is visible
 * to every process on the host via `ps` and lands in shell history; an
 * env var scoped to one command invocation is neither.
 */
final class UserCreateCommand implements Command
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PasswordHasher $hasher,
    ) {
    }

    public static function signature(): string
    {
        return 'user:create';
    }

    public function run(array $arguments): int
    {
        $email = $arguments[0] ?? null;

        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            fwrite(STDERR, "Usage: LOMBOKCLARION_PASSWORD='...' lombokclarion user:create <email>\n");
            return 1;
        }

        $password = getenv('LOMBOKCLARION_PASSWORD');

        if ($password === false || $password === '') {
            fwrite(STDERR, "Set LOMBOKCLARION_PASSWORD in the environment (not argv — argv is visible in `ps` and shell history).\n");
            return 1;
        }

        $existing = (new QueryBuilder($this->pdo, 'users'))->where('email', '=', $email)->first();

        if ($existing !== null) {
            fwrite(STDERR, "A user with email \"$email\" already exists (id {$existing['id']}).\n");
            return 1;
        }

        // 16 random bytes hex-encoded: exactly 32 chars (the column width),
        // unguessable, no coordination needed. Generated HERE because the
        // schema deliberately owns no id generation — the id is app data.
        $id = bin2hex(random_bytes(16));

        (new QueryBuilder($this->pdo, 'users'))->insert([
            'id' => $id,
            'email' => $email,
            'password_hash' => $this->hasher->hash($password),
        ]);

        echo "Created user $email (id $id).\n";
        return 0;
    }
}
