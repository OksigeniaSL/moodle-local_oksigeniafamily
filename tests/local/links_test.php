<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_oksigeniafamily\local;

/**
 * Tests for what a family is given once they join: the adult's own once, each child's under that child.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\local\links
 */
#[\PHPUnit\Framework\Attributes\CoversClass(links::class)]
final class links_test extends \basic_testcase {
    /**
     * A link as for_child() gives it.
     *
     * @param string $component The plugin.
     * @param string $label Its label.
     * @param string $pin Its PIN, if any.
     * @return array
     */
    private function link(string $component, string $label, string $pin = ''): array {
        return [
            'component' => $component,
            'label' => $label,
            'url' => 'https://example.com/' . rawurlencode($label) . '/' . $pin,
            'pin' => $pin,
            'note' => '',
        ];
    }

    /**
     * Brothers and sisters: the app, which is the adult's, once and first; each canteen's link and PIN under its child.
     */
    public function test_group_shares_the_adults_own(): void {
        $app = $this->link('local_oksigeniaaty', 'Open the app');
        $each = [
            7 => [$app, $this->link('local_oksigeniameals', 'Canteen', '4826')],
            9 => [$app, $this->link('local_oksigeniameals', 'Canteen', '1937')],
        ];

        [$shared, $own] = links::group($each);

        $this->assertSame([$app], $shared);
        $this->assertSame(['4826'], array_column($own[7], 'pin'));
        $this->assertSame(['1937'], array_column($own[9], 'pin'));
    }

    /**
     * What only one child gets stays with that child, even without a PIN; a child alone shares all that has none.
     */
    public function test_group_keeps_a_childs_own(): void {
        $app = $this->link('local_oksigeniaaty', 'Open the app');
        $library = $this->link('local_oksigeniaayvurenda', 'Library');

        [$shared, $own] = links::group([7 => [$app, $library], 9 => [$app]]);
        $this->assertSame([$app], $shared);
        $this->assertSame([$library], $own[7]);
        $this->assertSame([], $own[9]);

        $this->assertSame([[$app, $library], [7 => []]], links::group([7 => [$app, $library]]));
        $this->assertSame([[], []], links::group([]));
    }
}
