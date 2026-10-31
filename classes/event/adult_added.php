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

namespace local_oksigeniafamily\event;

/**
 * Event adult added.
 *
 * Other plugins observe it to take back their own part (a key, a linked phone, a reader), and their daily tasks check
 * the same through the API, so the event only brings that forward.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adult_added extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init() {
        $this->data['objecttable'] = 'local_oksigeniafamily_adult';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * The event's name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:adultadded', 'local_oksigeniafamily');
    }

    /**
     * What happened, for the logs.
     *
     * @return string
     */
    public function get_description() {
        return "The adult with id '{$this->objectid}' joined the family with id '{$this->other['familyid']}'.";
    }

    /**
     * Check the data the event needs.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['familyid'])) {
            throw new \coding_exception('The \'familyid\' value must be set in other.');
        }
    }

    /**
     * The object's mapping, for backup and restore (not restored: family records belong to the site).
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_oksigeniafamily_adult', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * The mapping of the other data (none needed).
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
