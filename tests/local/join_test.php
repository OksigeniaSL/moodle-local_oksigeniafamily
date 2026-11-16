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

use local_oksigeniafamily\api;

/**
 * Tests for what a student's own pages show their family to join: the code, its QR and the steps.
 *
 * @package    local_oksigeniafamily
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniafamily\api::join_for_student
 * @covers     \local_oksigeniafamily\local\letters::join_steps
 */
final class join_test extends \advanced_testcase {
    /**
     * One code for the student's pages, the same each time, which joins the student; a letter's code stays good.
     */
    public function test_one_code_shown_again(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_oksigeniafamily');
        $student = $this->getDataGenerator()->create_user(['firstname' => 'Leo']);
        $letter = api::issue_claim_code((int) $student->id);

        $join = api::join_for_student((int) $student->id);

        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/', $join['code']);
        $this->assertStringEndsWith('/local/oksigeniafamily/claim.php#code=' . $join['code'], $join['url']);
        $this->assertSame($join['code'], api::join_for_student((int) $student->id)['code']);
        $this->assertSame((int) $student->id, api::code_student($join['code']));
        $this->assertSame((int) $student->id, api::code_student($letter));
        $this->assertSame(0, $join['adults']);
        $this->assertSame(0, $join['inapp']);

        // The page decides the size and the ink of the QR.
        $this->assertStringContainsString('fill="currentColor"', $join['qrsvg']);
        $this->assertStringContainsString('viewBox="', $join['qrsvg']);
        $this->assertStringNotContainsString('width=', $join['qrsvg']);

        // Who joins with it counts.
        api::claim($join['code'], ['firstname' => 'Ana', 'lastname' => 'Paz']);
        $this->assertSame(1, api::join_for_student((int) $student->id)['adults']);

        // Near its end, a new one; the one before stops, the letter's does not.
        $DB->set_field('local_oksigeniafamily_claim', 'expires', time() + DAYSECS, ['purpose' => 'page']);
        $next = api::join_for_student((int) $student->id);
        $this->assertNotSame($join['code'], $next['code']);
        $this->assertNull(api::code_student($join['code']));
        $this->assertSame((int) $student->id, api::code_student($letter));
        $this->assertSame(1, $DB->count_records('local_oksigeniafamily_claim', ['purpose' => 'page', 'status' => 'active']));

        $this->assertNull(api::join_for_student(-5));
    }

    /**
     * Where families do not get into the app, the letter's steps, the same on both phones.
     */
    public function test_steps_without_the_app(): void {
        $this->resetAfterTest();
        set_config('familyapp', 0, 'local_oksigeniaaty');

        $steps = letters::join_steps('example.org/familia');

        $this->assertSame($steps['ios'], $steps['android']);
        $this->assertSame(['scan', 'form', 'check'], array_column($steps['ios'], 'kind'));
        $this->assertStringContainsString('example.org/familia', $steps['ios'][0]['text']);
    }

    /**
     * Where they do, an iPhone installs the app first; Android finds it from «Open in the app».
     */
    public function test_steps_with_the_app(): void {
        if (!class_exists('\\local_oksigeniaaty\\api')) {
            $this->markTestSkipped('Oksigenia Aty is not installed.');
        }
        $this->resetAfterTest();
        set_config('familyapp', 1, 'local_oksigeniaaty');

        $steps = letters::join_steps('example.org/familia');

        $this->assertSame(['install', 'scan', 'form', 'open', 'pin', 'siblings'], array_column($steps['ios'], 'kind'));
        $this->assertSame(['scan', 'form', 'open', 'pin', 'siblings'], array_column($steps['android'], 'kind'));
    }
}
