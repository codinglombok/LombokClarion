<?php
// Minimal LombokClarion app in one file. Run: php examples/01-hello-http.php
declare(strict_types=1);
require __DIR__ . '/../autoload.php';

use LombokClarion\Container\Container;
use LombokClarion\Http\{Request, Response};
use LombokClarion\Routing\{Kernel, Router};

final class HelloController {
    public function greet(Request $r): Response {
        return Response::json(['hello' => $r->attribute('name')]);
    }
}

$router = new Router();
$router->get('/hello/{name}', [HelloController::class, 'greet']);
$kernel = new Kernel(new Container(), $router);

$resp = $kernel->handle(new Request('GET', '/hello/lombok'));
echo $resp->status . ' ' . $resp->body . PHP_EOL; // 200 {"hello":"lombok"}
