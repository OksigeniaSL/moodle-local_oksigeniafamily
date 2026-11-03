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

namespace local_oksigeniafamily\external;

use local_oksigeniafamily\api;
use local_oksigeniafamily\local\consent;

/**
 * Tests for answering a request for consent from the app: as the caller, only for their own children, while it is open.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\external\answer_consent
 */
#[\PHPUnit\Framework\Attributes\CoversClass(answer_consent::class)]
final class answer_consent_test extends \advanced_testcase {
    /**
     * An adult with an account and their child, another family's adult, and a request about the child.
     *
     * @return \stdClass child, parent, adultid, stranger, strangerid and requestid.
     */
    private function setup_request(): \stdClass {
        $generator = $this->getDataGenerator();
        $data = (object) [
            'child' => $generator->create_user(['firstname' => 'Lucas']),
            'parent' => $generator->create_user(),
            'stranger' => $generator->create_user(),
            'other' => $generator->create_user(),
        ];
        $familyid = api::create_family();
        $data->adultid = api::add_adult($familyid, ['firstname' => 'Pilar', 'lastname' => 'Sanz', 'userid' => $data->parent->id]);
        api::add_child($familyid, (int) $data->child->id);
        $otherfamily = api::create_family();
        $data->strangerid = api::add_adult($otherfamily, [
            'firstname' => 'Raúl',
            'lastname' => 'Gil',
            'userid' => $data->stranger->id,
        ]);
        api::add_child($otherfamily, (int) $data->other->id);
        $data->requestid = consent::create(\context_system::instance(), [
            'title' => 'Salida al Teide',
            'deadline' => time() + 3 * DAYSECS,
        ], [(int) $data->child->id], (int) get_admin()->id);

        return $data;
    }

    /**
     * The adult answers for their child, from the app, with a comment; the answer counts and says so.
     */
    public function test_answers_for_own_child(): void {
        global $DB;
        $this->resetAfterTest();
        $data = $this->setup_request();
        $this->setUser($data->parent);

        $result = answer_consent::execute($data->requestid, (int) $data->child->id, $data->adultid, 'yes', 'Lleva gorra');
        $result = \core_external\external_api::clean_returnvalue(answer_consent::execute_returns(), $result);

        $this->assertTrue($result['saved']);
        $this->assertStringContainsString('Lucas', $result['message']);
        $row = $DB->get_record('local_oksigeniafamily_answer', ['requestid' => $data->requestid]);
        $this->assertSame('yes', $row->answer);
        $this->assertSame('app', $row->channel);
        $this->assertSame('Lleva gorra', $row->comment);
        $this->assertEquals($data->parent->id, $row->userid);
        $this->assertSame(consent::YES, consent::student_state(consent::get($data->requestid), (int) $data->child->id)->status);
    }

    /**
     * Nobody answers with another person's adult record, nor for a child that is not theirs.
     */
    public function test_refuses_what_is_not_theirs(): void {
        global $DB;
        $this->resetAfterTest();
        $data = $this->setup_request();
        $this->setUser($data->stranger);

        try {
            answer_consent::execute($data->requestid, (int) $data->child->id, $data->adultid, 'yes');
            $this->fail('Answered with somebody else\'s record');
        } catch (\moodle_exception $e) {
            $this->assertSame('consentnotfamily', $e->errorcode);
        }
        try {
            answer_consent::execute($data->requestid, (int) $data->child->id, $data->strangerid, 'yes');
            $this->fail('Answered for a child of another family');
        } catch (\moodle_exception $e) {
            $this->assertSame('consentnotfamily', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_oksigeniafamily_answer'));
    }

    /**
     * Once the deadline has passed, the answer is refused.
     */
    public function test_refuses_after_the_deadline(): void {
        global $DB;
        $this->resetAfterTest();
        $data = $this->setup_request();
        $DB->set_field('local_oksigeniafamily_request', 'deadline', time() - DAYSECS, ['id' => $data->requestid]);
        $this->setUser($data->parent);

        $this->expectException(\moodle_exception::class);
        answer_consent::execute($data->requestid, (int) $data->child->id, $data->adultid, 'no');
    }
}
