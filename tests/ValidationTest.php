<?php

declare(strict_types=1);

use LombokClarion\Container\Container;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\I18n\Translator;
use LombokClarion\Routing\Kernel;
use LombokClarion\Routing\Router;
use LombokClarion\Validation\FormRequest;
use LombokClarion\Validation\Rule;
use LombokClarion\Validation\ValidationException;
use LombokClarion\Validation\Validator;
use LombokClarion\Validation\Violation;

// --- rule vocabulary -------------------------------------------------------

test('string rule: type, min, max — and canonical value passes through', function () {
    $rule = Rule::string()->min(3)->max(5);

    assertSame(['abc', null], $rule->check('abc'));
    assertSame('validation.string.min', $rule->check('ab')[1]->key);
    assertSame('validation.string.max', $rule->check('abcdef')[1]->key);
    assertSame('validation.string.type', $rule->check(42)[1]->key);
    assertSame(['min' => 3], Rule::string()->min(3)->check('a')[1]->params);
});

test('int rule canonicalizes numeric strings and rejects the rest', function () {
    $rule = Rule::int()->min(1)->max(100);

    // "42" is an int in intent (form input arrives as strings) — and the
    // canonical value IS int 42, not the string.
    assertSame([42, null], $rule->check('42'));
    assertSame([7, null], $rule->check(7));
    assertSame('validation.int.type', $rule->check('42.5')[1]->key);
    assertSame('validation.int.type', $rule->check('abc')[1]->key);
    assertSame('validation.int.min', $rule->check(0)[1]->key);
    assertSame('validation.int.max', $rule->check('101')[1]->key);
});

test('email rule', function () {
    assertSame(['a@b.co', null], Rule::email()->check('a@b.co'));
    assertSame('validation.email', Rule::email()->check('not-an-email')[1]->key);
    assertSame('validation.email', Rule::email()->check(42)[1]->key);
});

test('bool rule canonicalizes checkbox-shaped input', function () {
    // Browsers send "1"/"on"; APIs send true/false — all bools in intent.
    assertSame([true, null], Rule::bool()->check('on'));
    assertSame([true, null], Rule::bool()->check('1'));
    assertSame([false, null], Rule::bool()->check('0'));
    assertSame([true, null], Rule::bool()->check(true));
    assertSame('validation.bool', Rule::bool()->check('yes')[1]->key);
});

test('date rule rejects rolled-over dates — 2026-02-31 is invalid, not March 3rd', function () {
    [$date, $violation] = Rule::date('Y-m-d')->check('2026-02-14');
    assertSame(null, $violation);
    assertTrue($date instanceof DateTimeImmutable);
    assertSame('2026-02-14', $date->format('Y-m-d'));

    // createFromFormat would "helpfully" roll this to March 3rd; a birthdate
    // silently becoming a different day is corrupted data.
    assertSame('validation.date', Rule::date('Y-m-d')->check('2026-02-31')[1]->key);
    assertSame('validation.date', Rule::date('Y-m-d')->check('14/02/2026')[1]->key);
});

test('in rule is strict on value and refuses an empty allowed set at construction', function () {
    $rule = Rule::in(['draft', 'published']);

    assertSame(['draft', null], $rule->check('draft'));
    assertSame('validation.in', $rule->check('DRAFT')[1]->key);
    assertThrows(InvalidArgumentException::class, fn () => Rule::in([]));
});

test('also() is the typed escape hatch — a callable next to the rule, not a registry', function () {
    $rule = Rule::string()->also(
        fn (mixed $v): ?Violation => str_starts_with((string) $v, 'MP-')
            ? null
            : new Violation('validation.custom.nia_prefix', [])
    );

    assertSame(['MP-001', null], $rule->check('MP-001'));
    assertSame('validation.custom.nia_prefix', $rule->check('X-001')[1]->key);
});

// --- validator semantics ---------------------------------------------------

test('fields are required by default; nullable() is the annotation', function () {
    $rules = ['name' => Rule::string(), 'nickname' => Rule::string()->nullable()];

    // Absent required field → violation; absent nullable field → null in output.
    try {
        (new Validator())->validate([], $rules);
        assertTrue(false, 'expected ValidationException');
    } catch (ValidationException $e) {
        assertSame(['name'], array_keys($e->violations));
        assertSame('validation.required', $e->violations['name'][0]->key);
    }

    $out = (new Validator())->validate(['name' => 'Adi'], $rules);
    assertSame(['name' => 'Adi', 'nickname' => null], $out);

    // Empty string counts as absent — a submitted-but-blank form field is
    // not a value.
    try {
        (new Validator())->validate(['name' => ''], $rules);
        assertTrue(false, 'expected ValidationException');
    } catch (ValidationException $e) {
        assertSame('validation.required', $e->violations['name'][0]->key);
    }
});

test('output is a whitelist: unknown input fields never pass through', function () {
    $out = (new Validator())->validate(
        ['name' => 'Adi', 'is_admin' => '1', 'role' => 'superuser'],
        ['name' => Rule::string()]
    );

    // The smuggled is_admin dies here, structurally — same posture as $fillable.
    assertSame(['name' => 'Adi'], $out);
});

test('all violations are collected, not first-failure-wins', function () {
    try {
        (new Validator())->validate(
            ['email' => 'nope', 'age' => 'abc'],
            ['email' => Rule::email(), 'age' => Rule::int(), 'name' => Rule::string()]
        );
        assertTrue(false, 'expected ValidationException');
    } catch (ValidationException $e) {
        // One submit reveals ALL three problems.
        assertSame(['email', 'age', 'name'], array_keys($e->violations));
    }
});

test('a non-Rule in the rules map is a loud error — there is no string DSL', function () {
    assertThrows(
        InvalidArgumentException::class,
        fn () => (new Validator())->validate(['x' => 'v'], ['x' => 'required|max:255'])
    );
});

// --- FormRequest → DTO -----------------------------------------------------

/** Plain readonly DTO — no framework imports; could live in app/Domain. */
final class Test_MemberInput
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly DateTimeImmutable $birthdate,
        public readonly ?string $nickname,
    ) {
    }
}

/** @extends FormRequest<Test_MemberInput> */
final class Test_RegisterMemberRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => Rule::string()->min(3)->max(255),
            'email' => Rule::email()->max(255),
            'birthdate' => Rule::date('Y-m-d'),
            'nickname' => Rule::string()->max(50)->nullable(),
        ];
    }

    protected function map(array $validated): Test_MemberInput
    {
        return new Test_MemberInput(
            name: $validated['name'],
            email: $validated['email'],
            birthdate: $validated['birthdate'],
            nickname: $validated['nickname'],
        );
    }
}

test('FormRequest returns the typed DTO with canonicalized values', function () {
    $request = new Request('POST', '/members', body: [
        'name' => 'Adi Saputra',
        'email' => 'adi@merpatiputih.or.id',
        'birthdate' => '2001-03-14',
        'is_admin' => '1', // smuggled — must not survive
    ]);

    $input = (new Test_RegisterMemberRequest())->validate($request);

    assertTrue($input instanceof Test_MemberInput);
    assertSame('Adi Saputra', $input->name);
    assertTrue($input->birthdate instanceof DateTimeImmutable);
    assertSame('2001-03-14', $input->birthdate->format('Y-m-d'));
    assertSame(null, $input->nickname);
});

test('FormRequest failure carries structural violations for every bad field', function () {
    $request = new Request('POST', '/members', body: ['name' => 'Ad', 'email' => 'x']);

    try {
        (new Test_RegisterMemberRequest())->validate($request);
        assertTrue(false, 'expected ValidationException');
    } catch (ValidationException $e) {
        assertSame(['name', 'email', 'birthdate'], array_keys($e->violations));
    }
});

// --- rendering: i18n + the Kernel's 422 ------------------------------------

function test_translator(string $locale): Translator
{
    $t = new Translator($locale, 'en');
    // validation.* ships with the package; app keys layer on top (may override)
    $t->addCatalog('en', LombokClarion\Validation\Lang::catalog('en'));
    $t->addCatalog('id', LombokClarion\Validation\Lang::catalog('id'));
    $t->addCatalog('en', require dirname(__DIR__) . '/resources/lang/en.php');
    $t->addCatalog('id', require dirname(__DIR__) . '/resources/lang/id.php');

    return $t;
}

test('violations render through the Translator in the request locale', function () {
    $request = new Request('POST', '/members', body: ['email' => 'nope']);

    try {
        (new Test_RegisterMemberRequest(test_translator('id')))->validate($request);
        assertTrue(false, 'expected ValidationException');
    } catch (ValidationException $e) {
        $errors = $e->errors();
        assertSame('Kolom name wajib diisi.', $errors['name'][0]);
        assertSame('Kolom email harus berupa alamat email yang valid.', $errors['email'][0]);
    }
});

test('without a translator, errors are raw keys — obvious, not silently English', function () {
    $request = new Request('POST', '/members', body: []);

    try {
        (new Test_RegisterMemberRequest())->validate($request);
        assertTrue(false, 'expected ValidationException');
    } catch (ValidationException $e) {
        // The wiring gap announces itself instead of masquerading as a
        // deliberate English deployment.
        assertSame('validation.required', $e->errors()['name'][0]);
    }
});

final class Test_MemberController
{
    public function store(Request $request): Response
    {
        $input = (new Test_RegisterMemberRequest(test_translator('id')))->validate($request);

        return Response::json(['created' => $input->name], 201);
    }
}

test('the Kernel renders ValidationException as 422 JSON — no controller try/catch', function () {
    $router = new Router();
    $router->post('/members', [Test_MemberController::class, 'store']);
    $kernel = new Kernel(new Container(), $router);

    $bad = $kernel->handle(new Request('POST', '/members', body: ['name' => 'Adi Saputra']));
    assertSame(422, $bad->status);
    $body = json_decode($bad->body, true);
    assertSame('Kolom email wajib diisi.', $body['errors']['email'][0]);

    $ok = $kernel->handle(new Request('POST', '/members', body: [
        'name' => 'Adi Saputra',
        'email' => 'adi@merpatiputih.or.id',
        'birthdate' => '2001-03-14',
    ]));
    assertSame(201, $ok->status);
});

test('a non-RendersResponse exception still bubbles as the bug it is', function () {
    $router = new Router();
    $router->get('/boom', [Test_BoomController::class, 'boom']);
    $kernel = new Kernel(new Container(), $router);

    // Kernel must NOT dress a crash up as a quiet response.
    assertThrows(DomainException::class, fn () => $kernel->handle(new Request('GET', '/boom')));
});

final class Test_BoomController
{
    public function boom(Request $request): Response
    {
        throw new DomainException('a real bug');
    }
}

// --- Rule::file (Stage 13) ---------------------------------------------------

/** A real tmp file with the given bytes, wrapped as a non-sapi upload. */
function valTest_upload(string $contents, string $clientName, string $clientMime, int $error = UPLOAD_ERR_OK): \LombokClarion\Http\UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'lcvf');
    file_put_contents($tmp, $contents);

    return new \LombokClarion\Http\UploadedFile(
        clientName: $clientName,
        clientMimeType: $clientMime,
        tmpPath: $tmp,
        size: strlen($contents),
        error: $error,
        sapi: false,
    );
}

/** Minimal genuine PNG: signature + IHDR — finfo sniffs image/png from it. */
function valTest_pngBytes(): string
{
    return "\x89PNG\r\n\x1a\n" . hex2bin('0000000d494844520000000100000001080600000000');
}

test('file rule: anything that is not an UploadedFile is a type violation', function () {
    $rule = Rule::file(['image/png']);

    assertSame('validation.file.type', $rule->check('avatar.png')[1]->key);
    assertSame('validation.file.type', $rule->check(['tmp_name' => '/tmp/x'])[1]->key);
    assertSame('validation.file.type', $rule->check(42)[1]->key);
});

test('file rule: a PHP error code other than OK is an upload violation', function () {
    $rule = Rule::file(['image/png']);

    foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE] as $err) {
        $upload = valTest_upload(valTest_pngBytes(), 'a.png', 'image/png', error: $err);
        assertSame('validation.file.upload', $rule->check($upload)[1]->key, "error code $err");
    }

    // A crafted UploadedFile whose tmp path does not exist fails the same
    // way — isValid() checks the file, not just the code.
    $ghost = new \LombokClarion\Http\UploadedFile('a.png', 'image/png', '/nonexistent/tmp', 10, UPLOAD_ERR_OK, false);
    assertSame('validation.file.upload', $rule->check($ghost)[1]->key);
});

test('file rule: oversize is rejected with the limit in params', function () {
    $rule = Rule::file(['image/png'])->maxBytes(4);

    [$value, $violation] = $rule->check(valTest_upload(valTest_pngBytes(), 'a.png', 'image/png'));
    assertSame('validation.file.max', $violation->key);
    assertSame(['max' => 4], $violation->params);
});

test('file rule: mime comes from the BYTES — a PHP payload dressed as .png with an image/png header is rejected', function () {
    $rule = Rule::file(['image/png']);

    // Client controls name AND Content-Type header; both claim PNG. The
    // content is PHP. finfo sniffs text/x-php → mime violation.
    $masquerade = valTest_upload('<?php system($_GET["c"]);', 'kitten.png', 'image/png');
    [$value, $violation] = $rule->check($masquerade);
    assertSame('validation.file.mime', $violation->key);
    assertSame(['allowed' => 'image/png'], $violation->params);

    // The same claim with genuinely PNG bytes passes, and the canonical
    // value IS the UploadedFile — storage takes it from here.
    $real = valTest_upload(valTest_pngBytes(), 'kitten.png', 'image/png');
    [$value, $violation] = $rule->check($real);
    assertSame(null, $violation);
    assertTrue($value === $real);
});

/** DTO for the multipart FormRequest test. */
final class Test_AvatarInput
{
    public function __construct(
        public readonly string $name,
        public readonly ?\LombokClarion\Http\UploadedFile $avatar,
    ) {
    }
}

/** @extends FormRequest<Test_AvatarInput> */
final class Test_AvatarRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => Rule::string()->min(3)->max(255),
            'avatar' => Rule::file(['image/png'])->maxBytes(1024 * 1024)->nullable(),
        ];
    }

    protected function map(array $validated): Test_AvatarInput
    {
        return new Test_AvatarInput(
            name: $validated['name'],
            avatar: $validated['avatar'],
        );
    }
}

test('FormRequest merges $request->files: a multipart-shaped Request validates end-to-end', function () {
    $upload = valTest_upload(valTest_pngBytes(), 'foto.png', 'image/png');
    $request = new Request('POST', '/profile', body: ['name' => 'Adi Saputra'], files: ['avatar' => $upload]);

    $input = (new Test_AvatarRequest())->validate($request);

    assertTrue($input instanceof Test_AvatarInput);
    assertSame('Adi Saputra', $input->name);
    assertTrue($input->avatar === $upload, 'the validated value is the UploadedFile itself');
});

test('FormRequest: UPLOAD_ERR_NO_FILE means absent — nullable() sees null, not a broken upload', function () {
    // An empty <input type="file"> still occupies a slot in $_FILES with
    // error=4. FormRequest drops it before validation, so nullable() treats
    // the field as absent instead of failing validation.file.upload.
    $empty = new \LombokClarion\Http\UploadedFile('', '', '', 0, UPLOAD_ERR_NO_FILE, false);
    $request = new Request('POST', '/profile', body: ['name' => 'Adi Saputra'], files: ['avatar' => $empty]);

    $input = (new Test_AvatarRequest())->validate($request);
    assertSame(null, $input->avatar);

    // Without nullable() the same absence is a required-field failure —
    // NO_FILE never masquerades as a present-but-broken upload either way.
    $required = new class extends FormRequest {
        public function rules(): array
        {
            return ['avatar' => Rule::file(['image/png'])];
        }

        protected function map(array $validated): object
        {
            return (object) $validated;
        }
    };
    try {
        $required->validate($request);
        assertTrue(false, 'expected ValidationException');
    } catch (ValidationException $e) {
        assertSame('validation.required', $e->violations['avatar'][0]->key);
    }
});

test('FormRequest: an over-limit multipart upload renders through the normal 422 path', function () {
    $tooBig = valTest_upload(valTest_pngBytes() . str_repeat('A', 2048), 'foto.png', 'image/png');
    $request = new Request('POST', '/profile', body: ['name' => 'Adi Saputra'], files: ['avatar' => $tooBig]);

    $limited = new class extends FormRequest {
        public function rules(): array
        {
            return ['name' => Rule::string()->min(3), 'avatar' => Rule::file(['image/png'])->maxBytes(100)];
        }

        protected function map(array $validated): object
        {
            return (object) $validated;
        }
    };

    try {
        $limited->validate($request);
        assertTrue(false, 'expected ValidationException');
    } catch (ValidationException $e) {
        assertSame('validation.file.max', $e->violations['avatar'][0]->key);
    }
});
