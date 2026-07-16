<?php
// Domain-style command dispatch with zero HTTP/DB. Run: php examples/02-command-bus.php
declare(strict_types=1);
require __DIR__ . '/../autoload.php';

use LombokClarion\Bus\CommandBus;
use LombokClarion\Container\Container;

final class RegisterUser { public function __construct(public readonly string $email) {} }
final class RegisterUserHandler {
    public array $registered = [];
    public function handle(object $cmd): string { $this->registered[] = $cmd->email; return 'user-1'; }
}

$c = new Container();
$c->singleton(RegisterUserHandler::class, RegisterUserHandler::class);
$bus = new CommandBus($c);
$bus->register(RegisterUser::class, RegisterUserHandler::class);

echo $bus->dispatch(new RegisterUser('a@b.com')) . PHP_EOL; // user-1
