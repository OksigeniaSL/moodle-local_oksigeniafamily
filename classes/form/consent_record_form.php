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

namespace local_oksigeniafamily\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * An answer that came on paper, which the school writes down: from which adult, what they said and any comment.
 *
 * Custom data: adults (id => name, of the student's family) and student (their name).
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_record_form extends \moodleform {
    /**
     * The fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_oksigeniafamily';

        $mform->addElement('static', 'student', get_string('consent:col:student', $component), s($this->_customdata['student']));
        $adults = $this->_customdata['adults'] + [0 => get_string('consent:record:other', $component)];
        $mform->addElement('select', 'adultid', get_string('consent:record:who', $component), $adults);
        $mform->addElement('text', 'name', get_string('consent:record:name', $component), ['size' => 40, 'maxlength' => 255]);
        $mform->setType('name', PARAM_TEXT);
        $mform->hideIf('name', 'adultid', 'neq', 0);

        $choices = [];
        $choices[] = $mform->createElement('radio', 'answer', '', get_string('consent:yes', $component), 'yes');
        $choices[] = $mform->createElement('radio', 'answer', '', get_string('consent:no', $component), 'no');
        $mform->addGroup($choices, 'answergroup', get_string('consent:col:answer', $component), ' ', false);
        $mform->addRule('answergroup', null, 'required', null, 'client');

        $mform->addElement('textarea', 'comment', get_string('consent:comment', $component), ['rows' => 2, 'cols' => 50]);
        $mform->setType('comment', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('consent:record:save', $component));
    }

    /**
     * Checks: an answer, and a name when it is someone not in the records.
     *
     * @param array $data The data.
     * @param array $files The files.
     * @return array Errors by field.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!in_array($data['answer'] ?? '', ['yes', 'no'], true)) {
            $errors['answergroup'] = get_string('required');
        }
        if ((int) ($data['adultid'] ?? 0) === 0 && trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = get_string('required');
        }

        return $errors;
    }
}
