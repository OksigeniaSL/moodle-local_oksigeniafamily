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
 * The form of an adult, by hand.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adult_form extends \moodleform {
    /**
     * The fields: their name, how to reach them, and their account if they have one.
     */
    protected function definition() {
        $form = $this->_form;

        $form->addElement('text', 'firstname', get_string('firstname'), ['maxlength' => 100, 'autocomplete' => 'off']);
        $form->setType('firstname', PARAM_NOTAGS);
        $form->addRule('firstname', null, 'required', null, 'client');
        $form->addElement('text', 'lastname', get_string('lastname'), ['maxlength' => 100, 'autocomplete' => 'off']);
        $form->setType('lastname', PARAM_NOTAGS);
        $form->addElement('text', 'phone', get_string('adult:phone', 'local_oksigeniafamily'), ['autocomplete' => 'off']);
        $form->setType('phone', PARAM_NOTAGS);
        // Only a number the school knows is theirs finds them when they write from it (WhatsApp, Telegram): one the adult
        // typed when claiming a code is kept, but proves nothing until the school says so here.
        $form->addElement('advcheckbox', 'phonechecked', '', get_string('adult:phonechecked', 'local_oksigeniafamily'));
        $form->hideIf('phonechecked', 'phone', 'eq', '');
        $form->addElement('text', 'email', get_string('adult:email', 'local_oksigeniafamily'), ['autocomplete' => 'off']);
        $form->setType('email', PARAM_RAW_TRIMMED);
        $form->addElement('text', 'username', get_string('adult:username', 'local_oksigeniafamily'), ['autocomplete' => 'off']);
        $form->setType('username', PARAM_NOTAGS);
        $this->add_action_buttons(true, get_string('adult:save', 'local_oksigeniafamily'));
    }

    /**
     * A phone has to be a phone, an e-mail an e-mail, and an account has to exist.
     *
     * @param array $data The data.
     * @param array $files The files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        global $DB;

        $errors = parent::validation($data, $files);
        $prefix = \local_oksigeniafamily\local\import\importer::phone_prefix();
        $phone = trim($data['phone'] ?? '');
        if ($phone !== '' && \local_oksigeniafamily\local\phone::normalise($phone, $prefix) === '') {
            $errors['phone'] = get_string('claim:badphone', 'local_oksigeniafamily');
        }
        $email = trim($data['email'] ?? '');
        if ($email !== '' && !validate_email($email)) {
            $errors['email'] = get_string('import:error:email', 'local_oksigeniafamily', $email);
        }
        $username = \core_text::strtolower(trim($data['username'] ?? ''));
        if ($username !== '' && !$DB->record_exists('user', ['username' => $username, 'deleted' => 0])) {
            $errors['username'] = get_string('import:error:username', 'local_oksigeniafamily', $username);
        }

        return $errors;
    }
}
