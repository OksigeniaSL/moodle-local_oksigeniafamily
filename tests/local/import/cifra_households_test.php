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

namespace local_oksigeniafamily\local\import;

use local_oksigeniafamily\api;
use local_oksigeniafamily\local\cifra;

/**
 * Tests for the households of Cifra, with made-up answers of Cifra and no network.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\local\import\cifra_households
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cifra_households::class)]
final class cifra_households_test extends \advanced_testcase {
    /** @var int The night of the tests. */
    private const NOW = 1794870000;

    /**
     * A connection to Cifra that answers with made-up students and records.
     *
     * @param array[] $records The records of the students, by id: each becomes a student of the list too.
     * @return cifra
     */
    private function cifra(array $records): cifra {
        return new class ($records) extends cifra {
            /** @var string[] The records asked for. */
            public array $asked = [];

            /** @var array[] The records, by id. */
            private array $records;

            /**
             * A bridge that is never reached.
             *
             * @param array[] $records
             */
            public function __construct(array $records) {
                parent::__construct('key', 'https://bridge.example.invalid', '', '', 'made-up');
                $this->records = $records;
            }

            /**
             * The list of students: what the records say, without their relatives.
             *
             * @param array $query
             * @return array[]
             */
            public function people(array $query = []): array {
                $people = [];
                foreach ($this->records as $id => $record) {
                    $people[$id] = array_diff_key($record, ['familiares' => true]) + ['id' => $id,
                        'caracteristicas' => ['alumno' => true, 'profesor' => false, 'empleado' => false, 'padre' => false]];
                }
                return $people;
            }

            /**
             * One record.
             *
             * @param string $area
             * @param array $query
             * @param string|null $id
             * @return array|null
             */
            public function get(string $area, array $query = [], ?string $id = null): ?array {
                $this->asked[] = $id;
                return $this->records[$id] ?? null;
            }
        };
    }

    /**
     * A relative of a student, as Cifra gives it.
     *
     * @param string $kinship
     * @param bool $guardian
     * @param array $user Their account in Cifra.
     * @return array
     */
    private function relative(string $kinship, bool $guardian, array $user): array {
        return ['parentesco' => $kinship, 'tutor' => $guardian, 'usuario' => $user];
    }

    /**
     * What is in the tables.
     *
     * @return int[] Families, adults and children, active.
     */
    private function totals(): array {
        global $DB;

        return [
            $DB->count_records('local_oksigeniafamily_family', ['status' => api::ACTIVE]),
            $DB->count_records('local_oksigeniafamily_adult', ['status' => api::ACTIVE]),
            $DB->count_records('local_oksigeniafamily_child', ['status' => api::ACTIVE]),
        ];
    }

    /**
     * Two brothers come in together, with their parents; the grandmother, who is not their legal guardian in Cifra, does
     * not come, nor the brother listed as a relative; the accounts are found by their e-mail; the one without an account
     * gets his name; nothing is sent; and the same night again changes nothing.
     */
    public function test_brothers_together(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('phoneprefix', '+34', 'local_oksigeniafamily');
        $emails = $this->redirectEmails();
        $messages = $this->redirectMessages();

        $generator = $this->getDataGenerator();
        $leo = $generator->create_user(['email' => 'leo.arenas@school.example.invalid']);
        $teacher = $generator->create_user(['email' => 'dario.arenas@school.example.invalid']);
        $mother = $this->relative('Madre', true, ['id' => 900, 'nombre' => 'Marta', 'apellido1' => 'Faro',
            'email' => 'marta@example.invalid', 'telefono_movil' => '600 000 001']);
        $father = $this->relative('Padre', true, ['id' => 901, 'nombre' => 'Darío', 'apellido1' => 'Arenas',
            'email' => 'Dario.Arenas@school.example.invalid', 'empleado' => true]);
        $records = [
            '101' => ['nombre' => 'Leo', 'apellido1' => 'Arenas', 'email' => 'leo.arenas@school.example.invalid',
                'familiares' => [$mother, $father, $this->relative('Hermano', false, ['id' => 102, 'nombre' => 'Hugo',
                    'caracteristicas' => ['alumno' => true]]), $this->relative('Abuela', false, ['nombre' => 'Rosa',
                    'email' => 'rosa@example.invalid'])]],
            '102' => ['nombre' => 'Hugo', 'apellido1' => 'Arenas', 'apellido2' => 'Faro',
                'grupo_academico_principal' => ['etiqueta' => 'INF 5 A'],
                'familiares' => [$mother, $father, $this->relative('Hermano', false, ['id' => 101, 'nombre' => 'Leo'])]],
        ];

        $result = cifra_households::run(self::NOW, $this->cifra($records), null, 0);
        $this->assertSame('done', $result['state']);
        $this->assertSame([1, 2, 2], $this->totals());
        $family = $DB->get_record('local_oksigeniafamily_family', []);
        $this->assertSame(['cifra', 'cifra:hogar:'], [$family->source, substr($family->sourcekey, 0, 12)]);
        $adults = $DB->get_records('local_oksigeniafamily_adult', [], 'sourcekey', 'sourcekey, userid, phone, phoneverified');
        $this->assertSame(['cifra:900', 'cifra:901'], array_keys($adults));
        $this->assertEquals($teacher->id, $adults['cifra:901']->userid);
        $this->assertSame(['+34600000001', '1'], [$adults['cifra:900']->phone, (string) $adults['cifra:900']->phoneverified]);
        $this->assertNull($adults['cifra:900']->userid);
        $children = $DB->get_records('local_oksigeniafamily_child', [], 'sourcekey', 'sourcekey, userid, firstname, groupname');
        $this->assertEquals($leo->id, $children['cifra:101']->userid);
        $this->assertSame([null, 'Hugo', 'INF 5 A'], [$children['cifra:102']->userid, $children['cifra:102']->firstname,
            $children['cifra:102']->groupname]);

        // Nobody writes to them before the school's own welcome: they come quiet.
        $this->assertSame([], api::adults_of_child((int) $leo->id));
        $this->assertSame(2, $DB->count_records('local_oksigeniafamily_adult', ['quiet' => 1]));

        $again = cifra_households::run(self::NOW + DAYSECS, $this->cifra($records), null, 0);
        $this->assertSame('done', $again['state']);
        $names = ['familiesnew', 'adultsnew', 'adultschanged', 'adultsleaving', 'childrennew', 'childrenchanged',
            'childrenleaving'];
        foreach ($names as $name) {
            $this->assertSame(0, $again['counts'][$name], $name);
        }
        $this->assertSame([1, 2, 2], $this->totals());
        $this->assertSame(0, $emails->count());
        $this->assertSame(0, $messages->count());
    }

    /**
     * A parent with a child of each partner is in two households, one with each.
     */
    public function test_parent_of_two_households(): void {
        global $DB;
        $this->resetAfterTest();

        $father = $this->relative('Padre', true, ['id' => 910, 'nombre' => 'Iván', 'email' => 'ivan@example.invalid']);
        $records = [
            '201' => ['nombre' => 'Noa', 'familiares' => [$father, $this->relative('Madre', true, ['id' => 911,
                'nombre' => 'Lucía'])]],
            '202' => ['nombre' => 'Pol', 'familiares' => [$father, $this->relative('Madre', true, ['id' => 912,
                'nombre' => 'Sara'])]],
        ];
        $this->assertSame('done', cifra_households::run(self::NOW, $this->cifra($records), null, 0)['state']);
        $this->assertSame([2, 4, 2], $this->totals());
        $this->assertSame(2, $DB->count_records('local_oksigeniafamily_adult', ['sourcekey' => 'cifra:910']));
        $ofnoa = $DB->get_field('local_oksigeniafamily_child', 'familyid', ['sourcekey' => 'cifra:201']);
        $ofpol = $DB->get_field('local_oksigeniafamily_child', 'familyid', ['sourcekey' => 'cifra:202']);
        $this->assertNotEquals($ofnoa, $ofpol);
    }

    /**
     * A child who starts years later joins the household of their brother when they have the same guardians. With only
     * one of them, they are a household of their own, and that parent is in both: also when the other is among their
     * relatives without being their guardian, who would otherwise see them.
     */
    public function test_later_child_joins(): void {
        global $DB;
        $this->resetAfterTest();

        $mother = $this->relative('Madre', true, ['id' => 920, 'nombre' => 'Elena', 'email' => 'elena@example.invalid']);
        $father = $this->relative('Padre', true, ['id' => 921, 'nombre' => 'Raúl', 'telefono_movil' => '+34600000021']);
        $other = $this->relative('Madre', true, ['id' => 950, 'nombre' => 'Celia']);
        $records = [
            '301' => ['nombre' => 'Ana', 'familiares' => [$mother, $father]],
            '701' => ['nombre' => 'Mar', 'familiares' => [$other, $this->relative('Padre', true, ['id' => 951,
                'nombre' => 'Óscar'])]],
        ];
        cifra_households::run(self::NOW, $this->cifra($records), null, 0);
        $this->assertSame([2, 4, 2], $this->totals());
        $familyid = (int) $DB->get_field('local_oksigeniafamily_child', 'familyid', ['sourcekey' => 'cifra:301']);
        $ofmar = (int) $DB->get_field('local_oksigeniafamily_child', 'familyid', ['sourcekey' => 'cifra:701']);

        // Years on: the older one has left, and more start.
        $records['301']['fecha_baja'] = '2026-06-30';
        $records['302'] = ['nombre' => 'Bea', 'familiares' => [$mother, $father]];
        $records['303'] = ['nombre' => 'Carla', 'familiares' => [$mother, ['parentesco' => 'Padre', 'tutor' => false,
            'usuario' => $father['usuario']]]];
        $records['702'] = ['nombre' => 'Teo', 'familiares' => [$other]];
        $result = cifra_households::run(self::NOW + 3 * YEARSECS, $this->cifra($records), null, 0);
        $this->assertSame('done', $result['state']);
        $where = ['sourcekey' => 'cifra:302', 'status' => api::ACTIVE];
        $this->assertSame($familyid, (int) $DB->get_field('local_oksigeniafamily_child', 'familyid', $where));
        $this->assertSame(api::LEFT, $DB->get_field('local_oksigeniafamily_child', 'status', ['sourcekey' => 'cifra:301']));
        $this->assertSame(1, $result['counts']['childrenleaving']);
        $ofcarla = (int) $DB->get_field('local_oksigeniafamily_child', 'familyid', ['sourcekey' => 'cifra:303']);
        $this->assertNotContains($ofcarla, [$familyid, $ofmar]);
        $this->assertSame(['cifra:920'], $DB->get_fieldset_select(
            'local_oksigeniafamily_adult',
            'sourcekey',
            'familyid = :familyid AND status = :active',
            ['familyid' => $ofcarla, 'active' => api::ACTIVE]
        ));
        $ofteo = (int) $DB->get_field('local_oksigeniafamily_child', 'familyid', ['sourcekey' => 'cifra:702']);
        $this->assertNotContains($ofteo, [$familyid, $ofmar, $ofcarla]);
        $this->assertSame(2, $DB->count_records('local_oksigeniafamily_adult', ['sourcekey' => 'cifra:950']));
        $this->assertSame(2, $DB->count_records('local_oksigeniafamily_adult', ['sourcekey' => 'cifra:920']));
        $this->assertSame(1, $DB->count_records('local_oksigeniafamily_adult', ['sourcekey' => 'cifra:921']));
    }

    /**
     * An adult without an id in Cifra is known by their e-mail, or by their mobile, the same every night.
     */
    public function test_adult_without_id(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('phoneprefix', '+34', 'local_oksigeniafamily');

        $records = ['401' => ['nombre' => 'Iris', 'familiares' => [
            ['parentesco' => 'Madre', 'tutor' => true, 'usuario' => ['nombre' => 'Olga', 'email' => 'OLGA@example.invalid']],
            ['parentesco' => 'Tío', 'tutor' => true, 'usuario' => ['nombre' => 'Félix', 'telefono_movil' => '611 22 33 44']],
        ]]];
        cifra_households::run(self::NOW, $this->cifra($records), null, 0);
        cifra_households::run(self::NOW + DAYSECS, $this->cifra($records), null, 0);
        $keys = $DB->get_fieldset_select('local_oksigeniafamily_adult', 'sourcekey', '1 = 1 ORDER BY sourcekey');
        $this->assertSame(['cifra:mail:olga@example.invalid', 'cifra:tel:611223344'], $keys);
        $this->assertSame([1, 2, 1], $this->totals());
    }

    /**
     * A household already in Family from a letter's code is the same one: it takes the rest of the household and keeps
     * its own source, and no family is made twice, that night or the next.
     */
    public function test_joins_family_from_letter(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $student = $generator->create_user(['email' => 'iker@school.example.invalid']);
        $parent = $generator->create_user(['email' => 'nerea@example.invalid']);
        $familyid = api::create_family('claim');
        api::add_adult($familyid, ['firstname' => 'Nerea', 'email' => 'nerea@example.invalid', 'userid' => $parent->id]);
        api::add_child($familyid, (int) $student->id);
        $records = [
            '501' => ['nombre' => 'Iker', 'email' => 'iker@school.example.invalid', 'familiares' => [
                $this->relative('Madre', true, ['id' => 930, 'nombre' => 'Nerea', 'email' => 'nerea@example.invalid']),
                $this->relative('Padre', true, ['id' => 931, 'nombre' => 'Jon']),
            ]],
            '502' => ['nombre' => 'Ane', 'familiares' => [
                $this->relative('Madre', true, ['id' => 930, 'nombre' => 'Nerea', 'email' => 'nerea@example.invalid']),
                $this->relative('Padre', true, ['id' => 931, 'nombre' => 'Jon']),
            ]],
        ];

        $dry = cifra_households::dry_run(self::NOW, $this->cifra($records), 0);
        $this->assertSame([0, 1], [$dry['plan']['counts']['familiesnew'], $dry['plan']['counts']['familiesjoined']]);
        $this->assertSame([1, 1, 1], $this->totals());

        $result = cifra_households::run(self::NOW, $this->cifra($records), null, 0);
        $this->assertSame([0, 1], [$result['counts']['familiesnew'], $result['counts']['familiesjoined']]);
        $this->assertSame([1, 2, 2], $this->totals());
        $this->assertSame('claim', $DB->get_field('local_oksigeniafamily_family', 'source', ['id' => $familyid]));
        $this->assertSame('cifra:930', $DB->get_field('local_oksigeniafamily_adult', 'sourcekey', ['userid' => $parent->id]));
        $this->assertSame('cifra:501', $DB->get_field('local_oksigeniafamily_child', 'sourcekey', ['userid' => $student->id]));

        $again = cifra_households::run(self::NOW + DAYSECS, $this->cifra($records), null, 0);
        $this->assertSame([0, 0, 0], [$again['counts']['familiesnew'], $again['counts']['adultsnew'],
            $again['counts']['childrennew']]);
        $this->assertSame([1, 2, 2], $this->totals());
    }

    /**
     * Only the legal guardians Cifra marks for a student are adults of the household: the father it does not mark, whose
     * account has his e-mail, does not come, and his account sees nothing of his son. For a student for whom Cifra marks
     * nobody, every relative comes.
     */
    public function test_only_guardians(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $son = $generator->create_user(['email' => 'pau@school.example.invalid']);
        $father = $generator->create_user(['email' => 'jordi@school.example.invalid']);
        $records = [
            '811' => ['nombre' => 'Pau', 'email' => 'pau@school.example.invalid', 'familiares' => [
                $this->relative('Madre', true, ['id' => 970, 'nombre' => 'Laia']),
                $this->relative('Padre', false, ['id' => 971, 'nombre' => 'Jordi', 'email' => 'jordi@school.example.invalid',
                    'telefono_movil' => '+34600000071']),
            ]],
            '812' => ['nombre' => 'Vera', 'familiares' => [
                $this->relative('Madre', false, ['id' => 980, 'nombre' => 'Irene']),
                $this->relative('Abuelo', false, ['id' => 981, 'nombre' => 'Tomás']),
            ]],
        ];

        $this->assertSame('done', cifra_households::run(self::NOW, $this->cifra($records), null, 0)['state']);

        $this->assertSame([2, 3, 2], $this->totals());
        $this->assertFalse($DB->record_exists('local_oksigeniafamily_adult', ['sourcekey' => 'cifra:971']));
        $this->assertSame([], api::families_of_user((int) $father->id));
        $this->assertSame([], api::children_of_user((int) $father->id));
        $this->assertFalse(api::is_guardian((int) $father->id, (int) $son->id));
        $this->assertSame([], api::adults_by_phone('+34600000071'));
        $ofvera = (int) $DB->get_field('local_oksigeniafamily_child', 'familyid', ['sourcekey' => 'cifra:812']);
        $this->assertSame(2, $DB->count_records('local_oksigeniafamily_adult', ['familyid' => $ofvera]));
    }

    /**
     * An adult Cifra brought who is no longer in the household in Cifra (as a relative who is not a guardian, which came
     * in before only guardians did) leaves it the next night while they are quiet, and their account loses the family.
     * One who has joined, or whom the school welcomed, stays, for a person to look at. The night after, nothing more.
     */
    public function test_adults_no_longer_in_the_household(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $student = $generator->create_user(['email' => 'noa@school.example.invalid']);
        $grandmother = $generator->create_user(['email' => 'rosa@school.example.invalid']);
        $mother = ['id' => 960, 'nombre' => 'Marta'];
        $rosa = ['id' => 961, 'nombre' => 'Rosa', 'email' => 'rosa@school.example.invalid'];
        $felix = ['id' => 962, 'nombre' => 'Félix', 'telefono_movil' => '+34611223344'];
        $records = ['801' => ['nombre' => 'Noa', 'email' => 'noa@school.example.invalid', 'familiares' => [
            $this->relative('Madre', true, $mother),
            $this->relative('Abuela', true, $rosa),
            $this->relative('Tío', true, $felix),
        ]]];
        cifra_households::run(self::NOW, $this->cifra($records), null, 0);
        $this->assertSame([1, 3, 1], $this->totals());
        $this->assertTrue(api::is_guardian((int) $grandmother->id, (int) $student->id));
        $ofrosa = (int) $DB->get_field('local_oksigeniafamily_adult', 'id', ['sourcekey' => 'cifra:961']);
        $offelix = (int) $DB->get_field('local_oksigeniafamily_adult', 'id', ['sourcekey' => 'cifra:962']);
        // The school welcomed the uncle.
        api::wake_adult($offelix);

        // Cifra no longer has them as guardians.
        $records['801']['familiares'] = [
            $this->relative('Madre', true, $mother),
            $this->relative('Abuela', false, $rosa),
            $this->relative('Tío', false, $felix),
        ];
        $dry = cifra_households::dry_run(self::NOW + DAYSECS, $this->cifra($records), 0);
        $this->assertSame([$ofrosa], $dry['gone']['leaving']);
        $this->assertSame([$offelix], array_column($dry['gone']['review'], 'adultid'));
        $this->assertSame([1, 3, 1], $this->totals());

        $result = cifra_households::run(self::NOW + DAYSECS, $this->cifra($records), null, 0);
        $this->assertSame('done', $result['state']);
        $this->assertSame(1, $result['counts']['adultsleaving']);
        $this->assertSame([1, 2, 1], $this->totals());
        $this->assertSame(api::LEFT, $DB->get_field('local_oksigeniafamily_adult', 'status', ['id' => $ofrosa]));
        $this->assertSame(api::ACTIVE, $DB->get_field('local_oksigeniafamily_adult', 'status', ['id' => $offelix]));
        $this->assertFalse(api::is_guardian((int) $grandmother->id, (int) $student->id));
        $this->assertSame([], api::families_of_user((int) $grandmother->id));

        $again = cifra_households::run(self::NOW + 2 * DAYSECS, $this->cifra($records), null, 0);
        $this->assertSame([0, 0], [$again['counts']['adultsleaving'], $again['counts']['adultsnew']]);
        $this->assertSame([1, 2, 1], $this->totals());
    }

    /**
     * When most children would leave at once, the brake stops the round and nothing changes, until someone confirms
     * how many leave.
     */
    public function test_brake(): void {
        $this->resetAfterTest();

        $mother = $this->relative('Madre', true, ['id' => 940, 'nombre' => 'Inés']);
        $records = [];
        foreach (range(601, 606) as $id) {
            $records[(string) $id] = ['nombre' => 'Hijo ' . $id, 'familiares' => [$mother]];
        }
        cifra_households::run(self::NOW, $this->cifra($records), null, 0);
        $this->assertSame([1, 1, 6], $this->totals());

        foreach ($records as $id => $record) {
            $records[$id]['fecha_baja'] = '2026-06-30';
        }
        $records['607'] = ['nombre' => 'Hija 607', 'familiares' => [$mother]];
        $stopped = cifra_households::run(self::NOW + DAYSECS, $this->cifra($records), null, 0);
        $this->assertSame('stopped', $stopped['state']);
        $this->assertSame([1, 1, 6], $this->totals());
        $this->assertStringContainsString('--confirm=6', cifra_households::status_text());

        $this->assertSame('done', cifra_households::run(self::NOW + DAYSECS, $this->cifra($records), 6, 0)['state']);
        $this->assertSame([1, 1, 1], $this->totals());
    }

    /**
     * Without a connection the round says so, and changes nothing.
     */
    public function test_without_connection(): void {
        $this->resetAfterTest();

        $result = cifra_households::run(self::NOW);
        $this->assertSame('error', $result['state']);
        $this->assertSame([0, 0, 0], $this->totals());
        $said = get_string('cifraerrorsettings', 'local_oksigeniafamily');
        $this->assertStringContainsString($said, cifra_households::status_text());
    }
}
