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

namespace local_oksigeniafamily\output;

use local_oksigeniafamily\api;
use local_oksigeniafamily\local\consent;
use local_oksigeniafamily\local\consent_notices;
use local_oksigeniafamily\local\consent_view;
use local_oksigeniafamily\local\page;

/**
 * The requests for consent in the Moodle app and in Aty. What the school or the family wrote never goes into a template,
 * which the app compiles: it travels as data and the templates only bind it.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /** @var string The plugin. */
    private const COMPONENT = 'local_oksigeniafamily';

    /**
     * When the app starts: the links of the requests open inside it, and the menu has «Requests for consent» only for
     * whoever has one to answer.
     *
     * @param array $args What the app sends.
     * @return array
     */
    public static function mobile_init(array $args): array {
        global $CFG, $USER;

        return [
            'templates' => [],
            'javascript' => (string) file_get_contents($CFG->dirroot . '/local/oksigeniafamily/app/mobile-links.js'),
            'otherdata' => '',
            'disabled' => !consent::for_user((int) $USER->id, time()),
        ];
    }

    /**
     * The requests still open about the user's children.
     *
     * @param array $args What the app sends.
     * @return array
     */
    public static function mobile_consents_view(array $args): array {
        global $OUTPUT, $USER;

        $items = [];
        foreach (consent::for_user((int) $USER->id, time()) as $item) {
            $request = $item['request'];
            $names = [];
            foreach ($item['children'] as $childuserid) {
                $child = \core_user::get_user($childuserid, 'id, firstname');
                if ($child) {
                    $names[] = $child;
                }
            }
            $items[] = [
                'id' => (int) $request->id,
                'title' => format_string($request->title, true, ['context' => \context_system::instance()]),
                'detail' => consent_notices::names($names) . ' · '
                    . get_string('consent:until', self::COMPONENT, page::date((int) $request->deadline)),
            ];
        }

        return [
            'templates' => [['id' => 'main', 'html' => $OUTPUT->render_from_template('local_oksigeniafamily/mobile_consents', [])]],
            'javascript' => '',
            'otherdata' => ['items' => self::json(array_values(array_unique($items, SORT_REGULAR)))],
        ];
    }

    /**
     * One request: what the school says and, for each child, what the user answered and the two buttons.
     *
     * @param array $args What the app sends: id.
     * @return array
     */
    public static function mobile_consent_view(array $args): array {
        global $DB, $OUTPUT, $USER;

        $now = time();
        $request = consent::get((int) ($args['id'] ?? 0));
        $sections = [];
        $comments = [];
        $adults = $request ? $DB->get_records('local_oksigeniafamily_adult', ['userid' => $USER->id, 'status' => api::ACTIVE]) : [];
        foreach ($adults as $adult) {
            $children = consent::children_for_adult($request, (int) $adult->id);
            if (!$children) {
                continue;
            }
            $url = new \moodle_url('/local/oksigeniafamily/consent.php', ['id' => $request->id]);
            $view = consent_view::answer($request, $adult, $children, $now, $url, []);
            $rows = [];
            foreach ($view['children'] as $row) {
                $key = 'a' . $adult->id . 'c' . $row['childuserid'];
                $comments[$key] = '';
                $saved = (object) ['child' => $row['name'], 'deadline' => page::date((int) $request->deadline)];
                $rows[] = [
                    'id' => $row['childuserid'],
                    'key' => $key,
                    'fullname' => $row['fullname'],
                    'answered' => $row['answered'],
                    'saidyes' => $row['saidyes'],
                    'answertext' => $row['answertext'],
                    'yesaria' => $row['yesaria'],
                    'noaria' => $row['noaria'],
                    'savedyes' => get_string('consent:saved:yes', self::COMPONENT, $saved),
                    'savedno' => get_string('consent:saved:no', self::COMPONENT, $saved),
                ];
            }
            $sections[] = ['adultid' => (int) $adult->id, 'as' => $view['as'], 'children' => $rows];
        }

        $shown = null;
        if ($sections) {
            $data = consent_view::request($request, $now);
            $message = $data['message'];
            if ($data['attachment']) {
                $message .= \html_writer::tag('p', \html_writer::link($data['attachment']['url'], $data['attachment']['name']));
            }
            $shown = [
                'id' => (int) $request->id,
                'title' => $data['title'],
                'until' => $data['until'],
                'open' => $data['open'],
                'message' => $message,
            ];
        }

        return [
            'templates' => [['id' => 'main', 'html' => $OUTPUT->render_from_template('local_oksigeniafamily/mobile_consent', [])]],
            'javascript' => '',
            'otherdata' => [
                'request' => self::json($shown ?? new \stdClass()),
                'sections' => self::json($sections),
                'comments' => self::json((object) $comments),
            ],
        ];
    }

    /**
     * Data for the app, which decodes a value that starts as an object or a list.
     *
     * @param mixed $value
     * @return string
     */
    private static function json($value): string {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
