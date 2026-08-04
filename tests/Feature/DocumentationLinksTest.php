<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every relative link in docs/ must point at a file that exists.
 *
 * This is a test rather than a CI-only script so it runs in `php artisan test`
 * too — breakage is found before pushing, not after.
 *
 * Why it exists: markdown links resolve relative to the file containing them,
 * and the handbook lives in docs/. For most of its life every code reference
 * was written repo-root-relative — `](app/Models/Device.php)` — which a reader
 * clicking from docs/ resolves to `docs/app/Models/Device.php`. All 167 of them
 * 404'd. Nothing noticed, because nothing was checking.
 *
 * It also catches the subtler case a human reviewer misses: a link to a file
 * that someone later renamed or deleted.
 */
class DocumentationLinksTest extends TestCase
{
    /**
     * Anchors (#L120, #section) are stripped before checking — this asserts the
     * FILE exists, not that a line number is still meaningful. Line numbers
     * drift on every reformat and cannot be validated without parsing intent.
     */
    public static function markdownFiles(): array
    {
        // PHPUnit resolves data providers BEFORE the Laravel application is
        // booted, so base_path() is unavailable here — derive the root from
        // this file's own location instead (tests/Feature/ -> repo root).
        $root = dirname(__DIR__, 2);

        $docs = glob($root.'/docs/*.md') ?: [];

        return array_combine(
            array_map(fn (string $path) => basename($path), array_merge($docs, [$root.'/README.md'])),
            array_map(fn (string $path) => [$path], array_merge($docs, [$root.'/README.md']))
        );
    }

    #[DataProvider('markdownFiles')]
    public function test_every_relative_link_resolves(string $file): void
    {
        if (! is_file($file)) {
            $this->markTestSkipped(basename($file).' does not exist.');
        }

        $contents = file_get_contents($file);
        $directory = dirname($file);

        preg_match_all('/\]\(([^)\s]+)\)/', $contents, $matches);

        $broken = [];

        foreach ($matches[1] as $target) {
            // External links and pure in-page anchors are out of scope: the
            // first needs the network, the second needs a heading parser.
            if (preg_match('~^(https?://|mailto:|\#)~', $target)) {
                continue;
            }

            // Drop any #L123 / #heading fragment — we assert the file exists.
            $path = strtok($target, '#');

            if ($path === false || $path === '') {
                continue;
            }

            if (! file_exists($directory.'/'.$path)) {
                $broken[] = $target;
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($broken)),
            sprintf(
                "%s contains links that do not resolve.\n".
                'Paths are relative to the file itself: from docs/, code lives at ../app/…, '.
                "and a sibling doc is just NAME.md\nBroken: %s",
                basename($file),
                implode(', ', array_unique($broken))
            )
        );
    }
}
