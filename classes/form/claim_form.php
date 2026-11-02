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
 * The form where a family claims a student's code.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class claim_form extends \moodleform {
    /**
     * The fields: the code, and who claims it.
     */
    protected function definition() {
        $form = $this->_form;

        $form->addElement('text', 'code', get_string('claim:code', 'local_oksigeniafamily'), ['autocomplete' => 'off']);
        $form->setType('code', PARAM_ALPHANUMEXT);
        $form->addRule('code', null, 'required', null, 'client');
        $form->addElement('text', 'firstname', get_string('firstname'), ['autocomplete' => 'given-name']);
        $form->setType('firstname', PARAM_NOTAGS);
        $form->addRule('firstname', null, 'required', null, 'client');
        $form->addElement('text', 'lastname', get_string('lastname'), ['autocomplete' => 'family-name']);
        $form->setType('lastname', PARAM_NOTAGS);
        $form->addElement('text', 'phone', get_string('claim:phone', 'local_oksigeniafamily'), ['autocomplete' => 'tel']);
        $form->setType('phone', PARAM_NOTAGS);
        $form->addElement('text', 'email', get_string('claim:email', 'local_oksigeniafamily'), ['autocomplete' => 'email']);
        $form->setType('email', PARAM_EMAIL);
        $this->add_action_buttons(false, get_string('claim:submit', 'local_oksigeniafamily'));
    }

    /**
     * A phone, when given, has to be a phone.
     *
     * @param array $data The data.
     * @param array $files The files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $prefix = (string) get_config('local_oksigeniafamily', 'phoneprefix');
        if (trim($data['phone'] ?? '') !== '' && \local_oksigeniafamily\local\phone::normalise($data['phone'], $prefix) === '') {
            $errors['phone'] = get_string('claim:badphone', 'local_oksigeniafamily');
        }

        return $errors;
    }
}
