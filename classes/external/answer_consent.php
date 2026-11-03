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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_oksigeniafamily\api;
use local_oksigeniafamily\local\consent;
use local_oksigeniafamily\local\page;

/**
 * An adult with an account answers a request for consent about one of their children, from the app: the page of the request
 * inside the app calls it, since an account of Aty cannot open the site's pages in a browser.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_consent extends external_api {
    /**
     * What it takes.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'The request'),
            'childuserid' => new external_value(PARAM_INT, 'The student it is about'),
            'adultid' => new external_value(PARAM_INT, 'Which of the caller\'s adult records answers'),
            'answer' => new external_value(PARAM_ALPHA, 'yes or no'),
            'comment' => new external_value(PARAM_TEXT, 'Something the adult adds', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Saves the answer, as the caller and only for their own children.
     *
     * @param int $requestid
     * @param int $childuserid
     * @param int $adultid
     * @param string $answer
     * @param string $comment
     * @return array saved and message.
     */
    public static function execute(int $requestid, int $childuserid, int $adultid, string $answer, string $comment = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'requestid' => $requestid,
            'childuserid' => $childuserid,
            'adultid' => $adultid,
            'answer' => $answer,
            'comment' => $comment,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);

        $component = 'local_oksigeniafamily';
        $adult = $DB->get_record('local_oksigeniafamily_adult', [
            'id' => $params['adultid'],
            'userid' => $USER->id,
            'status' => api::ACTIVE,
        ]);
        $request = consent::get($params['requestid']);
        $children = ($adult && $request) ? consent::children_for_adult($request, (int) $adult->id) : [];
        if (!in_array($params['childuserid'], $children, true)) {
            throw new \moodle_exception('consentnotfamily', $component);
        }

        consent::answer($params['requestid'], $params['childuserid'], (int) $adult->id, $params['answer'], 'app', [
            'userid' => (int) $USER->id,
            'comment' => \core_text::substr(trim($params['comment']), 0, 1000),
        ]);
        $child = \core_user::get_user($params['childuserid'], 'id, firstname');

        return [
            'saved' => true,
            'message' => get_string('consent:saved:' . $params['answer'], $component, (object) [
                'child' => $child ? format_string($child->firstname) : '',
                'deadline' => page::date((int) $request->deadline),
            ]),
        ];
    }

    /**
     * What it gives back.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'saved' => new external_value(PARAM_BOOL, 'Whether it was saved'),
            'message' => new external_value(PARAM_TEXT, 'What to tell the adult'),
        ]);
    }
}
