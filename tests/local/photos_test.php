<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_oksigeniafamily\local;

use core_privacy\local\request\approved_contextlist;
use local_oksigeniafamily\api;
use local_oksigeniafamily\privacy\provider;

/**
 * Students' photos and the consent to the use of their image.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(photos::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(standing::class)]
final class photos_test extends \advanced_testcase {
    /**
     * An image of a size, as PNG.
     *
     * @param int $width The width.
     * @param int $height The height.
     * @return string
     */
    private function png(int $width, int $height): string {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * A family with a child who has no account, under a key in the school's data, and two adults.
     *
     * @param string $sourcekey The child's key.
     * @return array The family, child and adult ids.
     */
    private function family(string $sourcekey = 'cifra:123'): array {
        $familyid = api::create_family('test');
        $childid = api::add_child($familyid, null, $sourcekey);
        $first = api::add_adult($familyid, ['firstname' => 'Luis', 'lastname' => 'Ejemplo']);
        $second = api::add_adult($familyid, ['firstname' => 'Marta', 'lastname' => 'Ejemplo']);

        return ['family' => $familyid, 'child' => $childid, 'adults' => [$first, $second]];
    }

    /**
     * A stored photo is a small JPEG, whatever came in.
     */
    public function test_save_makes_a_small_jpeg(): void {
        $this->resetAfterTest();

        $this->assertTrue(photos::save('cifra:123', $this->png(1200, 800)));
        $file = photos::stored('cifra:123');
        $this->assertNotNull($file);
        $this->assertSame('image/jpeg', $file->get_mimetype());
        $info = getimagesizefromstring($file->get_content());
        $this->assertSame([512, 341], [$info[0], $info[1]]);
        $this->assertFalse(photos::save('cifra:123', 'not an image'));
        $this->assertFalse(photos::save('', $this->png(10, 10)));

        // Saving again replaces it.
        $this->assertTrue(photos::save('cifra:123', $this->png(100, 100)));
        $this->assertSame(1, photos::count());
        $info = getimagesizefromstring(photos::stored('cifra:123')->get_content());
        $this->assertSame(100, $info[0]);
    }

    /**
     * The photo is only given out with the family's yes, and the rule of who decides holds.
     */
    public function test_photo_needs_consent(): void {
        $this->resetAfterTest();
        $ids = $this->family();
        photos::save('cifra:123', $this->png(50, 50));

        // No answer: nothing.
        $this->assertNull(api::photo_for_key('cifra:123'));
        $this->assertNull(api::photo_for_child($ids['child']));
        $this->assertFalse(api::photo_allowed_for_child($ids['child']));

        // The school says yes.
        standing::record('cifra:123', standing::IMAGE, true);
        $this->assertNotNull(api::photo_for_key('cifra:123'));
        $this->assertNotNull(api::photo_for_child($ids['child']));
        $this->assertTrue(api::photo_allowed_for_key('cifra:123'));

        // An adult says no afterwards: no.
        standing::record('cifra:123', standing::IMAGE, false, $ids['adults'][0], 'web');
        $this->assertNull(api::photo_for_key('cifra:123'));

        // The other adult says yes: still no, as one adult said no.
        standing::record('cifra:123', standing::IMAGE, true, $ids['adults'][1], 'app');
        $this->assertSame(standing::NO, standing::state(['cifra:123'], standing::IMAGE));

        // The first adult changes their mind: yes.
        standing::record('cifra:123', standing::IMAGE, true, $ids['adults'][0], 'web');
        $this->assertSame(standing::YES, standing::state(['cifra:123'], standing::IMAGE));

        // A new school load saying no counts again, being the latest.
        standing::record('cifra:123', standing::IMAGE, false);
        $this->assertSame(standing::NO, standing::state(['cifra:123'], standing::IMAGE));

        // The switch off: nothing, whatever the answers.
        standing::record('cifra:123', standing::IMAGE, true);
        standing::record('cifra:123', standing::IMAGE, true, $ids['adults'][0], 'web');
        standing::record('cifra:123', standing::IMAGE, true, $ids['adults'][1], 'web');
        $this->assertNotNull(api::photo_for_key('cifra:123'));
        set_config('photos', 0, 'local_oksigeniafamily');
        $this->assertNull(api::photo_for_key('cifra:123'));
        $this->assertFalse(api::photo_allowed_for_key('cifra:123'));
    }

    /**
     * A student with an account shows the picture of their account, and the uploaded one when it has none.
     */
    public function test_account_picture_first(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $familyid = api::create_family('test');
        $childid = api::add_child($familyid, (int) $user->id, 'cifra:7');
        standing::record(photos::user_key((int) $user->id), standing::IMAGE, true);

        // Only the uploaded one, under the key in the school's data.
        photos::save('cifra:7', $this->png(40, 40));
        $this->assertSame(photos::FILENAME, api::photo_for_user((int) $user->id)->get_filename());
        $this->assertNotNull(api::photo_for_child($childid));

        // The account gets a picture: that one.
        $context = \context_user::instance($user->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'user', 'filearea' => 'icon', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'f3.png',
        ], $this->png(512, 512));
        global $DB;
        $DB->set_field('user', 'picture', 1, ['id' => $user->id]);
        $this->assertSame('f3.png', api::photo_for_user((int) $user->id)->get_filename());
        $this->assertSame('f3.png', api::photo_for_child($childid)->get_filename());
        $this->assertSame('f3.png', api::photo_for_key('cifra:7')->get_filename());
    }

    /**
     * A folder of photos is stored by number: the ID number of the account, or the key in a source.
     */
    public function test_import_dir(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['idnumber' => '1234']);
        $this->family('cifra:555');
        $dir = make_request_directory();
        file_put_contents($dir . '/1234.jpg', $this->png(20, 20));
        file_put_contents($dir . '/9999.jpg', $this->png(20, 20));
        file_put_contents($dir . '/notes.txt', 'hello');
        mkdir($dir . '/sub');
        file_put_contents($dir . '/sub/555.png', $this->png(20, 20));

        $result = photos::import_dir($dir, 'idnumber', false);
        $this->assertSame([1, 2, 1], [$result['saved'], $result['unmatched'], $result['notimage']]);
        $this->assertSame(0, photos::count());

        $result = photos::import_dir($dir, 'idnumber');
        $this->assertSame(1, $result['saved']);
        $this->assertNotNull(photos::stored(photos::user_key((int) $user->id)));

        $result = photos::import_dir($dir, 'source:cifra');
        $this->assertSame(1, $result['saved']);
        $this->assertNotNull(photos::stored('cifra:555'));
        $this->assertArrayHasKey('source:cifra', photos::kinds());
    }

    /**
     * The school's answers load from a spreadsheet, and loading the same one twice changes nothing.
     */
    public function test_import_answers(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['idnumber' => '1']);
        $this->getDataGenerator()->create_user(['idnumber' => '2']);
        $this->getDataGenerator()->create_user(['idnumber' => '3']);
        $text = "Nombre;Expediente;Autoriza el uso de imagen\r\nAna;1;Sí\r\nLuis;2;No autoriza\r\nPedro;3;quizá\r\nEva;4;S\r\n";
        $read = standing::read_csv(\core_text::convert($text, 'utf-8', 'windows-1252'));
        $this->assertSame(['Nombre', 'Expediente', 'Autoriza el uso de imagen'], $read['headers']);
        $this->assertSame(['id' => 1, 'answer' => 2], standing::guess_columns($read['headers']));

        $result = standing::import_rows($read['rows'], 1, 2, 'idnumber');
        $this->assertSame([1, 1, 0, 1, 1], [$result['yes'], $result['no'], $result['same'], $result['unmatched'],
            $result['unknown']]);
        $result = standing::import_rows($read['rows'], 1, 2, 'idnumber');
        $this->assertSame([0, 0, 2], [$result['yes'], $result['no'], $result['same']]);
        $this->assertSame(['yes' => 1, 'no' => 1], standing::summary());
    }

    /**
     * How answers are read.
     */
    public function test_parse(): void {
        foreach (['Sí', 'si', 'S', 'yes', 'X', '1', 'Autoriza', 'autorizado'] as $value) {
            $this->assertTrue(standing::parse($value), $value);
        }
        foreach (['No', 'n', '0', 'No autoriza', 'NO AUTORIZO', 'deniega'] as $value) {
            $this->assertFalse(standing::parse($value), $value);
        }
        foreach (['', 'quizá', '-'] as $value) {
            $this->assertNull(standing::parse($value), $value);
        }
    }

    /**
     * A student who leaves takes their photo and the answers about it with them, after the grace period.
     */
    public function test_purge(): void {
        global $DB;
        $this->resetAfterTest();
        $ids = $this->family('cifra:1');
        photos::save('cifra:1', $this->png(20, 20));
        standing::record('cifra:1', standing::IMAGE, true);
        photos::save('cifra:404', $this->png(20, 20));
        standing::record('cifra:404', standing::IMAGE, true);
        $now = time();

        // Within the grace period nothing goes; the student still here is seen again.
        $this->assertSame(0, photos::purge($now + DAYSECS, 30));
        $this->assertSame(0, standing::purge($now + DAYSECS, 30));
        $this->assertSame(2, photos::count());

        // Past it, the one nobody has goes; the student here stays.
        $this->assertSame(1, photos::purge($now + 40 * DAYSECS, 30));
        $this->assertSame(1, standing::purge($now + 40 * DAYSECS, 30));
        $this->assertNull(photos::stored('cifra:404'));
        $this->assertNotNull(photos::stored('cifra:1'));

        // The student leaves: their photo goes after the grace period too.
        $DB->set_field('local_oksigeniafamily_child', 'status', api::LEFT, ['id' => $ids['child']]);
        $this->assertSame(0, photos::purge($now + 50 * DAYSECS, 30));
        $this->assertSame(1, photos::purge($now + 80 * DAYSECS, 30));
        $this->assertSame(1, standing::purge($now + 80 * DAYSECS, 30));
        $this->assertSame(0, photos::count());
        $this->assertSame(0, $DB->count_records('local_oksigeniafamily_standing'));
    }

    /**
     * A student's photo and the answers about them are exported and deleted with their account.
     */
    public function test_privacy(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $familyid = api::create_family('test');
        api::add_child($familyid, (int) $user->id, 'cifra:9');
        photos::save('cifra:9', $this->png(20, 20));
        standing::record(photos::user_key((int) $user->id), standing::IMAGE, true);

        $contextlist = provider::get_contexts_for_userid((int) $user->id);
        $this->assertContains((int) \context_system::instance()->id, array_map('intval', $contextlist->get_contextids()));
        $approved = new approved_contextlist($user, 'local_oksigeniafamily', [\context_system::instance()->id]);
        provider::export_user_data($approved);
        $writer = \core_privacy\local\request\writer::with_context(\context_system::instance());
        $this->assertTrue($writer->has_any_data());

        provider::delete_data_for_user($approved);
        $this->assertNull(photos::stored('cifra:9'));
        $this->assertSame(0, $DB->count_records('local_oksigeniafamily_standing'));
    }
}
