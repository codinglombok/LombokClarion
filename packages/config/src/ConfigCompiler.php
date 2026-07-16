<?php

declare(strict_types=1);

namespace LombokClarion\Config;

use LombokClarion\Config\Exceptions\ConfigException;

/**
 * Compiles a schema (bootstrap/config.schema.php) plus the current
 * environment into a tree of plain, typed, readonly PHP classes — one
 * class per nesting level — so application code reads
 * `$config->mail->smtp->host` as a real typed property access, never
 * `config('mail.smtp.host')` array-soup.
 *
 * The schema shape is a nested associative array. A "leaf" node has a
 * `type` key:
 *
 *   return [
 *       'mail' => [
 *           'smtp' => [
 *               'host' => ['type' => 'string', 'env' => 'MAIL_HOST', 'default' => 'localhost'],
 *               'port' => ['type' => 'int', 'env' => 'MAIL_PORT', 'default' => 587],
 *           ],
 *       ],
 *   ];
 *
 * Anything without a `type` key is treated as a nested group and recursed
 * into. Values are resolved from the environment ONCE, at compile time —
 * the resulting config.compiled.php is a plain PHP file that
 * `require`s to an already-built object graph, safe to opcache-preload
 * and never re-parsed per request (§5).
 */
final class ConfigCompiler
{
    private const VALID_TYPES = ['string', 'int', 'float', 'bool', 'array'];

    /**
     * @param array<string, mixed> $schema
     * @param array<string, string>|null $env defaults to getenv()
     */
    public function compile(array $schema, string $rootClassName = 'AppConfig', ?array $env = null): string
    {
        $env ??= $this->currentEnvironment();
        $classDefs = [];
        $rootExpr = $this->compileNode($rootClassName, $schema, $env, $classDefs);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = '// GENERATED FILE — do not edit by hand.';
        $lines[] = '// Produced by LombokClarion\\Config\\ConfigCompiler at `lombokclarion optimize` time';
        $lines[] = '// from bootstrap/config.schema.php and the environment present at compile time.';
        $lines[] = '';

        foreach ($classDefs as $classDef) {
            $lines[] = $classDef;
            $lines[] = '';
        }

        $lines[] = "return $rootExpr;";
        $lines[] = '';

        return implode("\n", $lines);
    }

    public function compileToFile(array $schema, string $outputPath, string $rootClassName = 'AppConfig', ?array $env = null): void
    {
        $source = $this->compile($schema, $rootClassName, $env);
        $tmp = $outputPath . '.tmp';
        file_put_contents($tmp, $source);
        rename($tmp, $outputPath);
    }

    /**
     * @param array<string, string> $env
     * @param list<string> $classDefs appended to by reference
     */
    private function compileNode(string $className, array $schemaNode, array $env, array &$classDefs): string
    {
        $ctorParams = [];
        $ctorArgs = [];

        foreach ($schemaNode as $key => $value) {
            if (!is_array($value)) {
                throw new ConfigException("Invalid schema node at \"$className.$key\": expected an array.");
            }

            $propName = $this->assertValidPropertyName($className, (string) $key);

            if (isset($value['type'])) {
                $phpType = $this->mapType($className, $propName, $value['type']);
                $resolved = $this->resolveLeaf($className, $propName, $value, $env);
                $ctorParams[] = "public readonly $phpType \$$propName";
                $ctorArgs[] = var_export($resolved, true);
            } else {
                $childClassName = $className . '_' . $this->studly((string) $key);
                $childExpr = $this->compileNode($childClassName, $value, $env, $classDefs);
                $ctorParams[] = "public readonly $childClassName \$$propName";
                $ctorArgs[] = $childExpr;
            }
        }

        $classDefs[] = sprintf(
            "final class %s\n{\n    public function __construct(\n        %s\n    ) {\n    }\n}",
            $className,
            implode(",\n        ", $ctorParams) ?: ''
        );

        return "new $className(" . implode(', ', $ctorArgs) . ')';
    }

    private function assertValidPropertyName(string $className, string $key): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            throw new ConfigException("Invalid config key \"$key\" under \"$className\": must be a valid PHP identifier.");
        }

        return $key;
    }

    private function mapType(string $className, string $prop, string $type): string
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new ConfigException(sprintf(
                'Invalid type "%s" for "%s.%s". Valid types: %s.',
                $type,
                $className,
                $prop,
                implode(', ', self::VALID_TYPES)
            ));
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $def
     * @param array<string, string> $env
     */
    private function resolveLeaf(string $className, string $prop, array $def, array $env): mixed
    {
        $type = $def['type'];
        $envKey = $def['env'] ?? null;
        $raw = null;

        if ($envKey !== null) {
            $raw = $env[$envKey] ?? null;
        }

        if ($raw === null) {
            if (!array_key_exists('default', $def)) {
                throw new ConfigException(sprintf(
                    'Missing config value for "%s.%s": environment variable "%s" is not set and no default was provided.',
                    $className,
                    $prop,
                    $envKey ?? '(none)'
                ));
            }

            return $this->cast($type, $def['default']);
        }

        return $this->cast($type, $raw);
    }

    private function cast(string $type, mixed $raw): mixed
    {
        return match ($type) {
            'string' => (string) $raw,
            'int' => (int) $raw,
            'float' => (float) $raw,
            'bool' => is_bool($raw) ? $raw : in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true),
            'array' => is_array($raw) ? $raw : (is_array($decoded = json_decode((string) $raw, true)) ? $decoded : []),
        };
    }

    /**
     * @return array<string, string>
     */
    private function currentEnvironment(): array
    {
        $env = $_ENV;
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && !isset($env[$k])) {
                $env[$k] = $v;
            }
        }

        return array_map('strval', $env);
    }

    private function studly(string $key): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key)));
    }
}
