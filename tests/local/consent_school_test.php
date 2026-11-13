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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/../fixtures/antivirus_scanner.php');

/**
 * Tests for what the school does from the pages of consent: whose families it can ask, the letter checked on the
 * server (deadline, retention, the circular), sending it from a course and from the site, the report and the paper
 * answer.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\local\consent_school
 * @covers \local_oksigeniafamily\local\consent_view
 */
#[\PHPUnit\Framework\Attributes\CoversClass(consent_school::class)]
final class consent_school_test extends \advanced_testcase {
    /**
     * A course under way with two students (one in a group, one with a family of one adult) and its teacher.
     *
     * @return \stdClass course, group, ana, leo, teacher and adultid.
     */
    private function setup_course(): \stdClass {
        $generator = $this->getDataGenerator();
        $now = time();
        $data = (object) [
            'course' => $generator->create_course(['fullname' => 'Armonía', 'startdate' => $now - 30 * DAYSECS,
                'enddate' => $now + 90 * DAYSECS]),
            'ana' => $generator->create_user(['firstname' => 'Ana', 'lastname' => 'Bravo']),
            'leo' => $generator->create_user(['firstname' => 'Leo', 'lastname' => 'Cano']),
            'teacher' => $generator->create_user(),
        ];
        $generator->enrol_user($data->ana->id, $data->course->id, 'student');
        $generator->enrol_user($data->leo->id, $data->course->id, 'student');
        $generator->enrol_user($data->teacher->id, $data->course->id, 'editingteacher');
        $data->group = $generator->create_group(['courseid' => $data->course->id, 'name' => '1.º A']);
        $generator->create_group_member(['groupid' => $data->group->id, 'userid' => $data->ana->id]);
        $familyid = api::create_family();
        $data->adultid = api::add_adult($familyid, ['firstname' => 'Pilar', 'lastname' => 'Bravo', 'email' => 'p@example.com']);
        api::add_child($familyid, (int) $data->ana->id);

        return $data;
    }

    /**
     * What a letter brings from the page, with these changes.
     *
     * @param array $changes The fields that change.
     * @return array
     */
    private function letter(array $changes = []): array {
        return $changes + [
            'who' => '',
            'title' => 'Salida al Teide',
            'message' => "Salimos a las 9:00.\n\nTraed <b>agua</b>.",
            'until' => '7',
            'untilday' => '',
            'needall' => 0,
            'keepdays' => 365,
            'draftitemid' => 0,
        ];
    }

    /**
     * A file on disk to take as a circular.
     *
     * @param string $content What it holds.
     * @return string Its path.
     */
    private function file(string $content): string {
        $path = make_request_directory() . '/upload';
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Inside a course: the whole course or one of its groups, with how many students each; the letter in plain text,
     * the deadline at the end of the day, and the sending queued. A group of another course is not a choice.
     */
    public function test_ask_from_a_course(): void {
        global $DB;
        $this->resetAfterTest();
        $data = $this->setup_course();
        $other = $this->getDataGenerator()->create_course();
        $othergroup = $this->getDataGenerator()->create_group(['courseid' => $other->id]);
        $this->setUser($data->teacher);
        $context = \context_course::instance($data->course->id);
        $now = time();

        $choices = consent_school::choices($context, 0, 0);
        $this->assertSame('course', $choices['kind']);
        $this->assertSame(['course:' . $data->course->id, 'group:' . $data->group->id], array_column($choices['options'], 'value'));
        $this->assertSame([2, 1], array_column($choices['options'], 'count'));

        [, $errors] = consent_school::read($context, $choices, $this->letter(['who' => 'group:' . $othergroup->id]), $now);
        $this->assertArrayHasKey('who', $errors);
        [, $errors] = consent_school::read($context, $choices, $this->letter(['who' => 'course:1', 'title' => '  ']), $now);
        $this->assertArrayHasKey('who', $errors);
        $this->assertArrayHasKey('title', $errors);

        $who = 'course:' . $data->course->id;
        [$letter, $errors] = consent_school::read($context, $choices, $this->letter(['who' => $who]), $now);
        $this->assertSame([], $errors);
        $requestid = consent_school::send($context, $letter, (int) $data->teacher->id);
        $request = consent::get($requestid);
        $this->assertSame((int) $context->id, (int) $request->contextid);
        $this->assertSame('course', $request->scope);
        $this->assertSame((int) FORMAT_PLAIN, (int) $request->messageformat);
        $this->assertSame(consent::end_of_day($now + 7 * DAYSECS), (int) $request->deadline);
        $this->assertEqualsCanonicalizing([(int) $data->ana->id, (int) $data->leo->id], consent::students($requestid));
        // The text is shown as plain text everywhere: its paragraphs kept and nothing of it taken as HTML.
        $html = format_text($request->message, (int) $request->messageformat, ['context' => \context_system::instance()]);
        $this->assertStringContainsString('&lt;b&gt;agua&lt;/b&gt;', $html);
        $this->assertStringContainsString('<br', $html);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\local_oksigeniafamily\task\consent_send'));

        // One group: only its students.
        [$letter, $errors] = consent_school::read($context, $choices, $this->letter(['who' => 'group:' . $data->group->id]), $now);
        $this->assertSame([], $errors);
        $this->assertSame([(int) $data->ana->id], $letter['students']);
        $this->assertGreaterThan(0, $DB->count_records('local_oksigeniafamily_target', ['requestid' => $requestid]));
    }

    /**
     * On the site: nothing chosen gives no choices (the page asks whose first); a course with its groups, named; a
     * cohort alone. The request stays on the site.
     */
    public function test_ask_from_the_site(): void {
        $this->resetAfterTest();
        $data = $this->setup_course();
        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Alumnado de 1.º']);
        cohort_add_member($cohort->id, $data->leo->id);
        $this->setAdminUser();
        $system = \context_system::instance();
        $now = time();

        $this->assertSame([], consent_school::choices($system, 0, 0));
        $choices = consent_school::choices($system, (int) $data->course->id, 0);
        $this->assertSame('Armonía', $choices['options'][0]['label']);
        $this->assertCount(2, $choices['options']);

        $choices = consent_school::choices($system, 0, (int) $cohort->id);
        $this->assertSame('cohort', $choices['kind']);
        $this->assertSame([['value' => 'cohort:' . $cohort->id, 'label' => 'Alumnado de 1.º', 'count' => 1]], $choices['options']);
        [$letter, $errors] = consent_school::read($system, $choices, $this->letter(['who' => 'cohort:' . $cohort->id]), $now);
        $this->assertSame([], $errors);
        $request = consent::get(consent_school::send($system, $letter, (int) get_admin()->id));
        $this->assertSame((int) $system->id, (int) $request->contextid);
        $this->assertSame('cohort', $request->scope);
        $this->assertSame([(int) $data->leo->id], consent::students((int) $request->id));
    }

    /**
     * The chips of «Whose families?» only show the courses and cohorts the person can ask, and the same is checked when
     * one is chosen: not a course where it is prohibited, not a hidden course, not a cohort it cannot see; a teacher, only
     * their course. Without a search, only the courses under way.
     */
    public function test_only_what_one_can_ask(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $now = time();
        $running = ['startdate' => $now - DAYSECS, 'enddate' => 0];
        $mine = $generator->create_course(['fullname' => 'Coro'] + $running);
        $theirs = $generator->create_course(['fullname' => 'Orquesta'] + $running);
        $hidden = $generator->create_course(['fullname' => 'Piano', 'visible' => 0] + $running);
        $ended = $generator->create_course(['fullname' => 'Lenguaje', 'startdate' => $now - 100 * DAYSECS,
            'enddate' => $now - 10 * DAYSECS]);
        $category = $generator->create_category();
        $open = $generator->create_cohort(['name' => 'Primero']);
        $closed = $generator->create_cohort(['name' => 'Segundo', 'contextid' => \context_coursecat::instance($category->id)->id]);

        // Someone who asks for the whole site, except where it is prohibited.
        $asker = $generator->create_user();
        $system = \context_system::instance();
        $roleid = $generator->create_role();
        assign_capability('local/oksigeniafamily:requestconsent', CAP_ALLOW, $roleid, $system->id);
        assign_capability('moodle/cohort:view', CAP_ALLOW, $roleid, $system->id);
        $theirscontext = \context_course::instance($theirs->id);
        assign_capability('local/oksigeniafamily:requestconsent', CAP_PROHIBIT, $roleid, $theirscontext->id);
        assign_capability('moodle/cohort:view', CAP_PROHIBIT, $roleid, \context_coursecat::instance($category->id)->id);
        role_assign($roleid, $asker->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($asker);

        $courses = array_column(consent_school::courses('', $now)['items'], 'name');
        $this->assertContains('Coro', $courses);
        $this->assertNotContains('Orquesta', $courses);
        $this->assertNotContains('Piano', $courses);
        $this->assertNotContains('Lenguaje', $courses);
        $this->assertSame(['Lenguaje'], array_column(consent_school::courses('Lengu', $now)['items'], 'name'));
        $this->assertSame(['Primero'], array_column(consent_school::cohorts('')['items'], 'name'));
        $this->assertTrue(consent_school::can_ask_course((int) $mine->id));
        $this->assertFalse(consent_school::can_ask_course((int) $theirs->id));
        $this->assertFalse(consent_school::can_ask_course((int) $hidden->id));
        $this->assertFalse(consent_school::can_ask_course((int) SITEID));
        $this->assertTrue(consent_school::can_ask_cohort((int) $open->id));
        $this->assertFalse(consent_school::can_ask_cohort((int) $closed->id));
        try {
            consent_school::choices($system, (int) $theirs->id, 0);
            $this->fail('A course where it is prohibited is not a choice');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        consent_school::choices($system, 0, (int) $closed->id);
    }

    /**
     * A teacher sees only their course, and no cohort.
     */
    public function test_teacher_sees_only_their_course(): void {
        $this->resetAfterTest();
        $data = $this->setup_course();
        $this->getDataGenerator()->create_course(['fullname' => 'Orquesta', 'startdate' => time() - DAYSECS]);
        $this->getDataGenerator()->create_cohort(['name' => 'Primero']);
        $this->setUser($data->teacher);

        $courses = consent_school::courses('', time())['items'];
        $this->assertSame(['Armonía'], array_column($courses, 'name'));
        $this->assertSame([2], array_column($courses, 'count'));
        $this->assertSame([], consent_school::cohorts('')['items']);
    }

    /**
     * The answers are kept at least consent::MINKEEPDAYS days: no choice below it, and the server says no to one.
     */
    public function test_keep_minimum(): void {
        $this->resetAfterTest();
        $data = $this->setup_course();
        $this->setUser($data->teacher);
        $context = \context_course::instance($data->course->id);
        $choices = consent_school::choices($context, 0, 0);
        $who = 'course:' . $data->course->id;

        foreach (consent_school::keep_options() as $days) {
            $this->assertGreaterThanOrEqual(consent::MINKEEPDAYS, $days);
        }
        foreach ([0, 10, consent::MINKEEPDAYS - 1, 100000] as $days) {
            [, $errors] = consent_school::read($context, $choices, $this->letter(['who' => $who, 'keepdays' => $days]), time());
            $this->assertArrayHasKey('keepdays', $errors, "$days days");
        }
        // The site's own number of days is a choice too, never below the minimum.
        set_config('consentkeepdays', 45, 'local_oksigeniafamily');
        $this->assertSame(45, consent_school::keep_default());
        $this->assertContains(45, consent_school::keep_options());
        set_config('consentkeepdays', 10, 'local_oksigeniafamily');
        $this->assertSame(consent::MINKEEPDAYS, consent_school::keep_default());
        $this->assertNotContains(10, consent_school::keep_options());
    }

    /**
     * The deadline: a week or two from today, or another day, always the end of that day where whoever asks is; never a
     * day that has passed or that does not exist.
     */
    public function test_deadline(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['timezone' => 'America/Bogota']);
        $this->setUser($user);
        $now = time();
        $zone = \core_date::get_user_timezone_object($user);

        $this->assertSame(consent::end_of_day($now + 14 * DAYSECS), consent_school::deadline('14', '', $now));
        $this->assertSame(0, consent_school::deadline('3', '', $now));
        $this->assertSame(0, consent_school::deadline('day', '2026-02-30', $now));
        $this->assertSame(0, consent_school::deadline('day', 'mañana', $now));
        $yesterday = (new \DateTime('@' . ($now - DAYSECS)))->setTimezone($zone)->format('Y-m-d');
        $this->assertSame(0, consent_school::deadline('day', $yesterday, $now));

        $day = (new \DateTime('@' . ($now + 10 * DAYSECS)))->setTimezone($zone)->format('Y-m-d');
        $deadline = consent_school::deadline('day', $day, $now);
        $end = (new \DateTime('@' . $deadline))->setTimezone($zone);
        $this->assertSame($day . ' 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    /**
     * The circular: a document or an image, under a clean name, within the size of uploads, past the antivirus; it waits
     * in the draft area and goes to the request's area when the letter goes. Any other kind of file is refused, also
     * one put in the draft area by other means.
     */
    public function test_attachment(): void {
        global $CFG, $USER;
        $this->resetAfterTest();
        $data = $this->setup_course();
        $this->setUser($data->teacher);
        $context = \context_course::instance($data->course->id);
        $choices = consent_school::choices($context, 0, 0);
        $who = 'course:' . $data->course->id;

        foreach (['virus.exe', 'nota.php', 'pagina.html', 'sin-extension', '.pdf'] as $name) {
            try {
                consent_school::take_file($this->file('%PDF-1.4'), $name, $context, 0);
                $this->fail("$name should not go as a circular");
            } catch (\moodle_exception $e) {
                $this->assertSame('consent:attach:type', $e->errorcode, $name);
            }
        }

        $taken = consent_school::take_file($this->file('%PDF-1.4 circular'), '../../tmp/Circular del <Teide>.pdf', $context, 0);
        $this->assertSame('tmpCircular del Teide.pdf', $taken['name']);
        $this->assertSame('tmpCircular del Teide.pdf', consent_school::draft_file($taken['draftitemid'])->get_filename());
        // A second one replaces the first.
        $again = consent_school::take_file($this->file('png'), 'foto.png', $context, $taken['draftitemid']);
        $this->assertSame($taken['draftitemid'], $again['draftitemid']);
        $this->assertSame('foto.png', consent_school::draft_file($again['draftitemid'])->get_filename());

        [$letter, $errors] = consent_school::read($context, $choices, $this->letter([
            'who' => $who, 'draftitemid' => $again['draftitemid'],
        ]), time());
        $this->assertSame([], $errors);
        $request = consent::get(consent_school::send($context, $letter, (int) $data->teacher->id));
        $this->assertSame('foto.png', consent_view::attachment($request)['name']);
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oksigeniafamily',
            'consent',
            $request->id,
            'id',
            false
        );
        $this->assertCount(1, $files);

        // A file of another kind in the draft area does not go with the letter.
        $draftitemid = file_get_unused_draft_itemid();
        $draft = ['contextid' => \context_user::instance($USER->id)->id, 'component' => 'user', 'filearea' => 'draft',
            'itemid' => $draftitemid, 'filepath' => '/', 'filename' => 'script.exe'];
        get_file_storage()->create_file_from_string($draft, 'MZ');
        $letter = $this->letter(['who' => $who, 'draftitemid' => $draftitemid]);
        [, $errors] = consent_school::read($context, $choices, $letter, time());
        $this->assertArrayHasKey('attachment', $errors);

        // The size of uploads of the site.
        $CFG->maxbytes = 10;
        try {
            consent_school::take_file($this->file(str_repeat('x', 20)), 'grande.pdf', $context, 0);
            $this->fail('Too big');
        } catch (\moodle_exception $e) {
            $this->assertSame('consent:attach:size', $e->errorcode);
        }
    }

    /**
     * Moodle's antivirus, when the site has one: an infected circular is refused and removed.
     */
    public function test_attachment_antivirus(): void {
        global $CFG;
        $this->resetAfterTest();
        $data = $this->setup_course();
        $this->setUser($data->teacher);
        $context = \context_course::instance($data->course->id);
        $CFG->antiviruses = 'okftestable';
        $sink = $this->redirectMessages();

        $path = $this->file('X5O!P%@AP EICAR');
        try {
            consent_school::take_file($path, 'circular.pdf', $context, 0);
            $this->fail('An infected file should not go');
        } catch (\moodle_exception $e) {
            $this->assertSame('consent:attach:virus', $e->errorcode);
        }
        $this->assertFileDoesNotExist($path);
        $this->assertSame('limpia.pdf', consent_school::take_file($this->file('%PDF'), 'limpia.pdf', $context, 0)['name']);
        $sink->close();
    }

    /**
     * The paper answer from the row of the report: one of the student's adults, yes or no, kept as the school's with
     * who wrote it down. Not an adult of another student, not a student out of the request, not once it is closed.
     */
    public function test_paper(): void {
        global $DB;
        $this->resetAfterTest();
        $data = $this->setup_course();
        $context = \context_course::instance($data->course->id);
        $requestid = consent::create(
            $context,
            ['title' => 'Concierto', 'deadline' => time() + 3 * DAYSECS],
            [(int) $data->ana->id, (int) $data->leo->id],
            (int) $data->teacher->id
        );
        $request = consent::get($requestid);
        $outsider = $this->getDataGenerator()->create_user();
        $otherfamily = api::create_family();
        $stranger = api::add_adult($otherfamily, ['firstname' => 'Otro', 'email' => 'o@example.com']);
        api::add_child($otherfamily, (int) $outsider->id);

        $row = consent_school::paper($request, (int) $data->ana->id, $data->adultid, consent::YES, (int) $data->teacher->id);
        $this->assertSame('school', $row->channel);
        $this->assertSame((int) $data->teacher->id, $row->userid);
        $this->assertSame('Pilar Bravo', $row->adultname);
        $this->assertSame(consent::YES, consent::student_state($request, (int) $data->ana->id)->status);

        $tries = [
            [(int) $data->ana->id, $stranger, consent::NO, 'consentnotfamily'],
            [(int) $data->leo->id, $data->adultid, consent::NO, 'consentnotfamily'],
            [(int) $outsider->id, $stranger, consent::NO, 'invalidrecord'],
            [(int) $data->ana->id, 0, consent::NO, 'consentnotfamily'],
        ];
        foreach ($tries as [$child, $adult, $answer, $code]) {
            try {
                consent_school::paper($request, $child, $adult, $answer, (int) $data->teacher->id);
                $this->fail("$child, $adult");
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
        }
        try {
            consent_school::paper($request, (int) $data->ana->id, $data->adultid, 'maybe', (int) $data->teacher->id);
            $this->fail('Only yes or no');
        } catch (\invalid_parameter_exception $e) {
            $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        }
        $DB->set_field('local_oksigeniafamily_request', 'deadline', time() - 10, ['id' => $requestid]);
        $this->expectExceptionMessage(get_string('consentclosed', 'local_oksigeniafamily'));
        consent_school::paper(consent::get($requestid), (int) $data->ana->id, $data->adultid, consent::NO, 1);
    }

    /**
     * The report opens on who is missing while it is open, first those the reminder reaches and then those with nobody
     * in the records; the counts are its tabs, and each row carries the paper answer, with nobody chosen when the
     * student has more than one adult. Closed, it opens on everyone and nothing can be written down.
     */
    public function test_report(): void {
        global $DB, $OUTPUT, $PAGE;
        $this->resetAfterTest();
        $data = $this->setup_course();
        $carla = $this->getDataGenerator()->create_user(['firstname' => 'Carla', 'lastname' => 'Abad']);
        $familyid = api::create_family();
        $first = api::add_adult($familyid, ['firstname' => 'Tomás', 'lastname' => 'Abad', 'email' => 't@example.com']);
        api::add_adult($familyid, ['firstname' => 'Nerea', 'lastname' => 'Gil', 'email' => 'n@example.com']);
        api::add_child($familyid, (int) $carla->id);
        $requestid = consent::create(
            \context_system::instance(),
            ['title' => 'Concierto', 'deadline' => time() + 3 * DAYSECS],
            [(int) $data->ana->id, (int) $data->leo->id, (int) $carla->id],
            (int) $data->teacher->id
        );
        $this->setAdminUser();
        $now = time();

        $report = consent_view::report(consent::get($requestid), $now);
        $this->assertSame('pending', $report['show']);
        $this->assertSame([3, 0, 0, 3], array_column($report['tabs'], 'count'));
        // Leo has nobody: last, and in the alert with the way to add his family.
        $this->assertSame(['Carla Abad', 'Ana Bravo', 'Leo Cano'], array_column($report['rows'], 'name'));
        $this->assertTrue($report['hasnofamily']);
        $this->assertSame('Leo Cano', $report['nofamily'][0]['name']);
        $this->assertStringContainsString('index.php?q=Leo', $report['nofamily'][0]['addurl']);
        $this->assertSame(2, $report['remindable']);
        $carlapaper = $report['rows'][0]['paper'];
        $this->assertSame([false, false], array_column($carlapaper['adults'], 'checked'));
        $this->assertSame([true, true], array_column($carlapaper['adults'], 'required'));
        $this->assertSame([true], array_column($report['rows'][1]['paper']['adults'], 'checked'));
        $this->assertFalse($report['rows'][2]['paper']['hasadults']);

        consent::answer($requestid, (int) $carla->id, $first, consent::YES, 'school', ['userid' => 2]);
        $report = consent_view::report(consent::get($requestid), $now, 'yes');
        $this->assertSame(['Carla Abad'], array_column($report['rows'], 'name'));
        $this->assertFalse($report['hasnofamily']);

        $PAGE->set_url('/local/oksigeniafamily/consent_report.php', ['id' => $requestid]);
        $report = consent_view::report(consent::get($requestid), $now);
        $html = $OUTPUT->render_from_template('local_oksigeniafamily/consent_report', $report + [
            'listurl' => '#', 'actionurl' => '#', 'sesskey' => 'abc', 'canremind' => true, 'remindlabel' => 'x',
            'canaddnew' => false, 'cancancel' => true, 'cancelurl' => '#', 'csvurl' => '#', 'pdfurl' => '#',
        ]);
        $this->assertStringContainsString('name="adultid"', $html);
        $this->assertStringContainsString('name="quick" value="1"', $html);
        $this->assertStringContainsString('name="sesskey" value="abc"', $html);
        $this->assertStringContainsString('consent_record.php', $html);

        // Closed: everyone, and no paper answer.
        $DB->set_field('local_oksigeniafamily_request', 'deadline', $now - 10, ['id' => $requestid]);
        $report = consent_view::report(consent::get($requestid), $now);
        $this->assertSame('all', $report['show']);
        $this->assertCount(3, $report['rows']);
        $this->assertSame([null, null, null], array_column($report['rows'], 'paper'));
    }
}
