<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\Tpay\Tests\Resources;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

use function defined;
use function file_get_contents;
use function iterator_to_array;
use function preg_match_all;
use function sprintf;
use function str_replace;
use function stripslashes;

use const DIRECTORY_SEPARATOR;

/**
 * Every constant a template names has to exist at render time.
 *
 * Twig's constant() does not degrade to null on a name it cannot resolve — CoreExtension
 * throws RuntimeError('Constant "%s" is undefined.'), which reaches the customer as a 500.
 * So a template pointing at a bundle class is a hard runtime dependency on that class, and
 * nothing else in this repository notices when the bundle drops one: PHPStan does not read
 * Twig, and the templates are the only place these names appear outside `use` statements.
 *
 * That is exactly how this landed. WT-1015 replaced PaymentMethodTypeStruct with the
 * crPaymentContract extension; the struct went away in bundle 6.5.0 while
 * payment-method.html.twig still read its API_ALIAS. Nothing failed at install, at build,
 * or on any page except the one that renders the payment method list — checkout/confirm,
 * for every customer, with Tpay installed.
 */
final class TwigConstantIntegrityTest extends TestCase
{
    public function testEveryConstantNamedInATemplateIsDefined(): void
    {
        $templates = $this->templates();

        self::assertNotEmpty($templates, 'no templates found — the glob is wrong, not the templates');

        foreach ($templates as $path => $source) {
            preg_match_all("/constant\\(\\s*'([^']+)'/", $source, $matches);

            foreach ($matches[1] as $constant) {
                // Twig string literals escape the namespace separator; PHP does not.
                $name = stripslashes($constant);

                self::assertTrue(
                    defined($name),
                    sprintf(
                        '%s renders constant("%s"), which is undefined — Twig throws on that, so the '
                        . 'page this template belongs to is a 500. The bundle most likely renamed or '
                        . 'removed it.',
                        $path,
                        $name,
                    ),
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function templates(): array
    {
        $root = __DIR__ . '/../../src/Resources/views';

        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
            '/\.html\.twig$/',
        );

        $templates = [];
        foreach (iterator_to_array($files) as $file) {
            $path = str_replace($root . DIRECTORY_SEPARATOR, '', (string) $file);
            $templates[$path] = (string) file_get_contents((string) $file);
        }

        return $templates;
    }
}
