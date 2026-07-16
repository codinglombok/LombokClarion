<?php

declare(strict_types=1);

namespace LombokClarion\View;

use LombokClarion\View\Exceptions\ViewException;

/**
 * Compiles LombokClarion templates (*.lc.php) to plain PHP.
 *
 * `{{ $value }}` is ALWAYS auto-escaped (htmlspecialchars). Opting out
 * requires the explicit `{!! $value !!}` syntax — and per master prompt
 * §7, that opt-out is meant to be flagged by a sibling XSS audit rule
 * (`lombokclarion audit:sql --xss` / a dedicated audit command) unless the
 * value is wrapped in Safe::mark(), which the audit rule treats as an
 * explicit, reviewed opt-out rather than a silent gap.
 *
 * Supported directives: @if/@elseif/@else/@endif, @foreach/@endforeach,
 * @extends/@section/@endsection/@yield, @include.
 */
final class ViewCompiler
{
    public function compile(string $template): string
    {
        $out = $template;
        $out = $this->compileRawEchoes($out);
        $out = $this->compileEscapedEchoes($out);
        $out = $this->compileParenDirective($out, 'if', fn (string $e) => "<?php if ($e): ?>");
        $out = $this->compileParenDirective($out, 'elseif', fn (string $e) => "<?php elseif ($e): ?>");
        $out = $this->compileParenDirective($out, 'foreach', fn (string $e) => "<?php foreach ($e): ?>");
        $out = $this->compileParenDirective($out, 'extends', fn (string $e) => "<?php \$this->extend($e); ?>");
        $out = $this->compileParenDirective($out, 'section', fn (string $e) => "<?php \$this->startSection($e); ?>");
        $out = $this->compileParenDirective($out, 'yield', fn (string $e) => "<?php echo \$this->yieldContent($e); ?>");
        $out = $this->compileParenDirective($out, 'include', fn (string $e) => "<?php echo \$this->renderTemplate($e, get_defined_vars()); ?>");

        $out = str_replace('@else', '<?php else: ?>', $out);
        $out = str_replace('@endif', '<?php endif; ?>', $out);
        $out = str_replace('@endforeach', '<?php endforeach; ?>', $out);
        $out = str_replace('@endsection', '<?php $this->endSection(); ?>', $out);

        return $out;
    }

    private function compileRawEchoes(string $out): string
    {
        return preg_replace_callback('/\{!!\s*(.+?)\s*!!\}/s', function (array $m): string {
            return "<?php echo ({$m[1]}); ?>";
        }, $out);
    }

    private function compileEscapedEchoes(string $out): string
    {
        return preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/s', function (array $m): string {
            return "<?php echo \\LombokClarion\\View\\ViewEngine::escape({$m[1]}); ?>";
        }, $out);
    }

    /**
     * Handles directives whose argument is a balanced-parenthesis
     * expression (which a plain regex cannot reliably capture once the
     * expression itself contains parens, e.g. `@if (count($items) > 0)`).
     */
    private function compileParenDirective(string $source, string $directive, callable $render): string
    {
        $needleRegex = '/@' . preg_quote($directive, '/') . '\s*\(/';
        $result = '';
        $cursor = 0;

        while (preg_match($needleRegex, $source, $m, PREG_OFFSET_CAPTURE, $cursor)) {
            $pos = $m[0][1];
            $result .= substr($source, $cursor, $pos - $cursor);

            $exprStart = $pos + strlen($m[0][0]);
            $depth = 1;
            $i = $exprStart;
            $len = strlen($source);

            while ($i < $len && $depth > 0) {
                if ($source[$i] === '(') {
                    $depth++;
                } elseif ($source[$i] === ')') {
                    $depth--;
                }
                $i++;
            }

            if ($depth !== 0) {
                throw new ViewException("Unbalanced parentheses for @$directive directive.");
            }

            $expr = substr($source, $exprStart, $i - $exprStart - 1);
            $result .= $render(trim($expr));
            $cursor = $i;
        }

        $result .= substr($source, $cursor);

        return $result;
    }
}
