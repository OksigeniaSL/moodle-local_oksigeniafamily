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
     * Set up a page of the requests for consent for the school: inside its course for the teachers, or with the
     * administration of the site for the requests of the whole site.
     *
     * @param \context $context Where the requests are: a course or the site.
     * @param string $path The page, such as /local/oksigeniafamily/consents.php.
     * @param array $params Its parameters.
     * @param string $title The title.
     * @return void
     */
    public static function consent_setup(\context $context, string $path, array $params, string $title): void {
        global $CFG, $PAGE;

        if (!consent::enabled()) {
            throw new \moodle_exception('consent:off', 'local_oksigeniafamily');
        }
        if ($context->contextlevel == CONTEXT_COURSE) {
            $course = get_course($context->instanceid);
            require_login($course);
            require_capability('local/oksigeniafamily:requestconsent', $context);
            $PAGE->set_url(new \moodle_url($path, $params));
            $PAGE->set_pagelayout('incourse');
            $PAGE->set_title($title);
            $PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
            $PAGE->navbar->add(
                get_string('consent:title', 'local_oksigeniafamily'),
                new \moodle_url('/local/oksigeniafamily/consents.php', ['contextid' => $context->id])
            );
            return;
        }
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            throw new \moodle_exception('invalidcontext');
        }
        require_once($CFG->libdir . '/adminlib.php');
        admin_externalpage_setup('local_oksigeniafamily_consents', '', $params, $path, ['nosearch' => true]);
        self::dress($title);
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
                        // Moodle's own forms send with an input, the templates with a button. Switched off just after
                        // the form leaves, so the one pressed still goes with it.
                        form.querySelectorAll('button[type=submit], input[type=submit]').forEach(function(button) {
                            setTimeout(function() { button.disabled = true; }, 0);
                        });
                    });
                });
            });
        ");
    }

    /**
     * The answer forms of the families (two buttons, «Yes» and «No») do not go twice either. The buttons are switched
     * off just after the form leaves, never during: a button switched off at once would not send its answer.
     *
     * @return void
     */
    public static function once_answers(): void {
        global $PAGE;

        $PAGE->requires->js_amd_inline("
            require([], function() {
                document.querySelectorAll('form[data-okf-answer]').forEach(function(form) {
                    form.addEventListener('submit', function() {
                        setTimeout(function() {
                            form.querySelectorAll('button[type=submit]').forEach(function(button) {
                                button.disabled = true;
                            });
                        }, 0);
                    });
                });
            });
        ");
    }

    /**
     * «Propose an improvement» of Oksigenia Marandu for one of the staff's screens, with what it needs loaded on the
     * page: called before the page's header. Empty on a site without Marandu (or with one before 0.61.1) and for an
     * account that may not propose.
     *
     * @param string $screen The screen, as this plugin names it.
     * @return string Its HTML.
     */
    public static function proposal(string $screen): string {
        $marandu = '\\local_oksigeniamarandu\\api';
        if (!class_exists($marandu) || !method_exists($marandu, 'proposal_button')) {
            return '';
        }

        return (string) $marandu::proposal_button('local_oksigeniafamily', $screen, 'okf-propose');
    }

    /**
     * The way between the pages, and «Propose an improvement» beside it.
     *
     * @param string $current The page: families, import, codes, consents or photos.
     * @param string $screen The screen, for the proposals; the name of the current page when empty.
     * @param bool $links Whether to show the way between the pages (not inside a course).
     * @return array|null For the template local_oksigeniafamily/nav; null when there is nothing to show.
     */
    public static function nav(string $current, string $screen = '', bool $links = true): ?array {
        $pages = ['families' => 'index.php', 'import' => 'import.php', 'codes' => 'codes.php', 'consents' => 'consents.php',
            'photos' => 'photos.php'];
        $caps = ['consents' => 'local/oksigeniafamily:requestconsent'];
        $items = [];
        foreach ($links ? $pages : [] as $name => $script) {
            if ($name === 'consents' && !consent::enabled()) {
                continue;
            }
            if (!has_capability($caps[$name] ?? 'local/oksigeniafamily:manage', \context_system::instance())) {
                continue;
            }
            $items[] = [
                'url' => (new \moodle_url('/local/oksigeniafamily/' . $script))->out(false),
                'label' => get_string('nav:' . $name, 'local_oksigeniafamily'),
                'current' => $name === $current,
            ];
        }

        $proposal = self::proposal($screen !== '' ? $screen : get_string('nav:' . $current, 'local_oksigeniafamily'));
        if (!$items && $proposal === '') {
            return null;
        }

        return ['hasitems' => !empty($items), 'items' => $items, 'proposal' => $proposal];
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
