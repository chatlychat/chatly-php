<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\Pagination\Page;
use Chatly\Tests\Assert;

/**
 * Cases that prove {@see ResponseShapes} is not vacuous.
 *
 * Split out of that class so each file keeps one responsibility — the
 * declaration, the fixtures and the classifier live there; the assertions
 * ABOUT them live here — and so both stay under the 650 lines CONTRACT.md §9
 * allows. Together they were 687.
 *
 * The split is worth more than the line count. {@see ResponseShapes} is the
 * fixture SOURCE for the whole surface pass, which means a flaw in it makes
 * that pass agree with itself rather than with the API: a classifier that
 * answered one word every time, or a declared column that went flat, would
 * satisfy all 159 routes without failing anything. These are the cases that
 * stop that, including the negative one about `is_array()` — the obvious
 * implementation, which would have made the entire shape pass theatre.
 */
final class ShapeSelfTestCases
{
    /**
     * Cases that prove {@see describe()} itself is not vacuous.
     *
     * A classifier is the kind of thing that passes 162 routes while answering
     * the same word every time, so it gets its own tests — including the
     * negative one that `is_array()`, the obvious implementation, would have
     * made the whole pass meaningless.
     *
     * @return array<string,\Closure():void>
     */
    public static function selfTestCases(): array
    {
        return [
            'shapes: the classifier splits array from object, which is_array() cannot' => static function (): void {
                $asObject = json_decode('{"id":"x_1"}', true, 512, \JSON_THROW_ON_ERROR);
                $asArray = json_decode('[{"id":"x_1"}]', true, 512, \JSON_THROW_ON_ERROR);

                /*
                 * The trap, stated as an assertion. `json_decode(…, true)`
                 * gives the SAME PHP type for both, so a classifier built on
                 * `is_array()` would answer one word for 161 of the 162 routes
                 * and the whole shape pass would be theatre.
                 */
                Assert::true(\is_array($asObject), 'a JSON object decodes to a PHP array');
                Assert::true(\is_array($asArray), 'and so does a JSON array — is_array() cannot tell them apart');

                Assert::same(
                    ResponseShapes::OBJECT,
                    ResponseShapes::describe($asObject),
                    'array_is_list() is false for an object with a string key',
                );
                Assert::same(
                    ResponseShapes::ARRAY_SHAPE,
                    ResponseShapes::describe($asArray),
                    'and true for a JSON array, which always decodes to a list',
                );
            },

            'shapes: every fixture classifies as the shape it was built for' => static function (): void {
                foreach ([ResponseShapes::PAGE, ResponseShapes::ARRAY_SHAPE, ResponseShapes::OBJECT] as $shape) {
                    // Round-tripped through JSON the way the client decodes
                    // it, so the fixture is tested as bytes and not as the
                    // PHP literal it was written as.
                    $json = json_encode(ResponseShapes::body($shape), \JSON_THROW_ON_ERROR);
                    $decoded = json_decode((string) $json, true, 512, \JSON_THROW_ON_ERROR);
                    Assert::same($shape, ResponseShapes::describe($decoded), "the {$shape} fixture reads back as {$shape}");
                    Assert::same(
                        ResponseShapes::SENTINEL_ROW,
                        ResponseShapes::sentinelIn($decoded, $shape),
                        "the sentinel row is reachable in a {$shape}",
                    );
                }

                Assert::same(ResponseShapes::EMPTY_SHAPE, ResponseShapes::describe(null), 'a 204 decodes to null, which is `empty`');
                Assert::same(
                    ResponseShapes::PAGE,
                    ResponseShapes::describe(Page::fromDecoded(ResponseShapes::body(ResponseShapes::PAGE))),
                    'and a typed Page is a page',
                );
            },

            'shapes: an envelope without a cursor key is an object, not a page' => static function (): void {
                /*
                 * `page` means `items` AND `nextCursor`. An object that
                 * happens to carry a field called `items` — a ticket with
                 * line items, say — must not be mistaken for a page, or a
                 * walker would be offered over something that cannot be
                 * walked.
                 */
                Assert::same(
                    ResponseShapes::OBJECT,
                    ResponseShapes::describe(['id' => 't_1', 'items' => [['sku' => 'a']]]),
                    'items without nextCursor is just an object with a list in it',
                );
                Assert::same(
                    ResponseShapes::PAGE,
                    ResponseShapes::describe(['items' => [], 'nextCursor' => null, 'total' => 9]),
                    'and an empty page is still a page — `total` sits beside the envelope',
                );
                Assert::same(
                    'scalar(string)',
                    ResponseShapes::describe('x_1'),
                    'a scalar body is named as one rather than silently counted as an object',
                );
            },

            '§10.10 shapes: the declared column covers all 162 routes, with the documented distribution' => static function (): void {
                $declared = ResponseShapes::declared();
                Assert::same(162, \count($declared), 'one declaration per route on the shared surface');

                foreach ($declared as $label => $shape) {
                    Assert::true(
                        \in_array($shape, ResponseShapes::ALL, true),
                        "{$label} is declared \"{$shape}\", which is not one of the four shapes",
                    );
                }

                $counts = array_fill_keys(ResponseShapes::ALL, 0);
                foreach ($declared as $shape) {
                    ++$counts[$shape];
                }
                /*
                 * Anti-vacuity. See EXPECTED_DISTRIBUTION — a column that
                 * said `object` 162 times satisfies every other assertion in
                 * this suite, and unanimity about nothing is what this
                 * catches. It would also be nearly right, which is the part
                 * that makes it dangerous: 115 of the 162 really are objects.
                 */
                foreach (ResponseShapes::EXPECTED_DISTRIBUTION as $shape => $expected) {
                    Assert::same($expected, $counts[$shape], "the surface carries {$expected} `{$shape}` routes");
                }
                Assert::same(
                    array_keys(ResponseShapes::EXPECTED_DISTRIBUTION),
                    array_keys(array_filter($counts, static fn (int $n): bool => $n > 0)),
                    'all four shapes must stay represented',
                );

                /*
                 * The two hand-written lists in this suite must agree.
                 *
                 * `SurfaceCases::PAGED` is the `paged` column — "this SDK
                 * offers a walker here" — and this is the `shape` column.
                 * They are two statements of one fact and were written
                 * separately; the gate checks the same equivalence on the
                 * manifest's side. A disagreement here is a typo in one of
                 * them, caught before it reaches the lock.
                 */
                $paged = SurfaceCases::PAGED;
                sort($paged);
                Assert::same(
                    $paged,
                    ResponseShapes::withShape(ResponseShapes::PAGE),
                    'the routes declared `page` must be exactly the ones this SDK offers a walker over',
                );
            },
        ];
    }
}
