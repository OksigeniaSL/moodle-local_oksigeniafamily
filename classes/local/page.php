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
 * What the school's pages have in common: a clean page, and the way between them.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page {
    /**
     * Title of the page, without the side blocks and the tabs of the site's administration, which do not help here.
     *
     * @param string $title The title.
     * @return void
     */
    public static function dress(string $title): void {
        global $PAGE;

        $PAGE->set_title($title);
        $PAGE->set_heading($title);
        $PAGE->set_secondary_navigation(false);
        $PAGE->blocks->show_only_fake_blocks(true);
    }

    /**
     * Forms marked data-okf-once do not go twice with a second click.
     *
     * @return void
     */
    public static function once_forms(): void {
        global $PAGE;

        $PAGE->requires->js_amd_inline("
            require([], function() {
                document.querySelectorAll('form[data-okf-once]').forEach(function(form) {
                    form.addEventListener('submit', function() {
                        form.querySelector('button[type=submit]').disabled = true;
                    });
                });
            });
        ");
    }

    /**
     * The way between the pages.
     *
     * @param string $current The page: families, import or codes.
     * @return array For the template local_oksigeniafamily/nav.
     */
    public static function nav(string $current): array {
        $pages = ['families' => 'index.php', 'import' => 'import.php', 'codes' => 'codes.php'];
        $items = [];
        foreach ($pages as $name => $script) {
            $items[] = [
                'url' => (new \moodle_url('/local/oksigeniafamily/' . $script))->out(false),
                'label' => get_string('nav:' . $name, 'local_oksigeniafamily'),
                'current' => $name === $current,
            ];
        }

        return ['items' => $items];
    }

    /**
     * A date people read, without the time.
     *
     * @param int $time The time.
     * @return string
     */
    public static function date(int $time): string {
        return userdate($time, get_string('strftimedate', 'langconfig'));
    }

    /**
     * What a family's origin is called.
     *
     * @param string $source The source.
     * @return string
     */
    public static function source(string $source): string {
        $key = 'source:' . $source;
        $manager = get_string_manager();

        return $manager->string_exists($key, 'local_oksigeniafamily') ? get_string($key, 'local_oksigeniafamily') : $source;
    }
}
