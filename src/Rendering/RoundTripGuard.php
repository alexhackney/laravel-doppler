<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Rendering;

use AlexHackney\Doppler\Exceptions\RoundTripFailed;

/**
 * Refuses to let content be written unless it loads back to exactly what went in.
 *
 * This is the single most valuable thing in the package. Every existing hand-rolled copy
 * of this sync writes its file and finds out at the next boot, on the box, in production,
 * whether the quoting was right. Loading the rendered bytes back before the rename turns
 * that entire class of failure into a refusal on the machine doing the rendering.
 *
 * It also means the renderer's correctness is enforced by running code rather than by a
 * grammar table in a document staying accurate as phpdotenv changes.
 *
 * Output is value-blind. A mismatch names keys and nothing else, because the failure
 * message is going to land in a CI log.
 */
final class RoundTripGuard
{
    /**
     * Verify that rendered content loads back to the given map.
     *
     * @param  array<string, string>  $expected
     *
     * @throws RoundTripFailed
     */
    public function verify(Renderer $renderer, string $content, array $expected): void
    {
        $actual = $renderer->parse($content);

        $mismatched = [];

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                $mismatched[] = $key;

                continue;
            }

            if ($actual[$key] !== $value) {
                $mismatched[] = $key;
            }
        }

        // A key the render invented is as much a defect as one it lost. This catches a
        // value that broke out of its quoting and was read back as a new assignment,
        // which is precisely how an unquoted multi-line value fails.
        foreach (array_keys($actual) as $key) {
            if (! array_key_exists($key, $expected)) {
                $mismatched[] = $key;
            }
        }

        if ($mismatched === []) {
            return;
        }

        $mismatched = array_values(array_unique($mismatched));
        sort($mismatched, SORT_STRING);

        throw RoundTripFailed::forKeys($mismatched);
    }
}
