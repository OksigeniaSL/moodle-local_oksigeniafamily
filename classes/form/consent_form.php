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
 * A new request for consent: what it is, the circular, to whom and until when.
 *
 * Custom data: context (where it is made: a course or the site) and groups (of the course: id => name).
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_form extends \moodleform {
    /**
     * The fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_oksigeniafamily';
        $context = $this->_customdata['context'];

        $mform->addElement('text', 'title', get_string('consent:field:title', $component), ['size' => 60, 'maxlength' => 255]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addHelpButton('title', 'consent:field:title', $component);

        $mform->addElement('editor', 'message', get_string('consent:field:message', $component), ['rows' => 8], [
            'maxfiles' => 0,
            'noclean' => false,
            'context' => $context,
        ]);
        $mform->setType('message', PARAM_RAW);

        $mform->addElement('filemanager', 'attachment', get_string('consent:field:attachment', $component), null, [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => ['document', 'image'],
            'return_types' => FILE_INTERNAL,
        ]);
        $mform->addHelpButton('attachment', 'consent:field:attachment', $component);

        if ($context->contextlevel == CONTEXT_COURSE) {
            $choices = ['course:' . $context->instanceid => get_string('consent:field:wholecourse', $component)];
            foreach ($this->_customdata['groups'] ?? [] as $groupid => $name) {
                $choices['group:' . $groupid] = get_string('consent:field:group', $component, $name);
            }
            $mform->addElement('select', 'who', get_string('consent:field:who', $component), $choices);
        } else {
            $mform->addElement('course', 'courseid', get_string('consent:field:course', $component), [
                'multiple' => false,
                'includefrontpage' => false,
            ]);
            $mform->addElement('cohort', 'cohortid', get_string('consent:field:cohort', $component), ['multiple' => false]);
            $mform->addElement('static', 'whohelp', '', get_string('consent:field:whohelp', $component));
        }

        $mform->addElement('date_selector', 'deadline', get_string('consent:field:deadline', $component));
        $mform->setDefault('deadline', time() + 7 * DAYSECS);
        $mform->addHelpButton('deadline', 'consent:field:deadline', $component);

        $mform->addElement(
            'advcheckbox',
            'needall',
            get_string('consent:field:needall', $component),
            get_string('consent:field:needall_label', $component)
        );
        $mform->addHelpButton('needall', 'consent:field:needall', $component);

        $mform->addElement('text', 'keepdays', get_string('consent:field:keepdays', $component), ['size' => 5]);
        $mform->setType('keepdays', PARAM_INT);
        $mform->setDefault('keepdays', (int) (get_config('local_oksigeniafamily', 'consentkeepdays') ?: 365));
        $mform->addHelpButton('keepdays', 'consent:field:keepdays', $component);
        $mform->setAdvanced('keepdays');

        $this->add_action_buttons(true, get_string('consent:send', $component));
    }

    /**
     * Checks: someone to send it to, and a deadline that has not passed.
     *
     * @param array $data The data.
     * @param array $files The files.
     * @return array Errors by field.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $component = 'local_oksigeniafamily';
        if (trim((string) ($data['title'] ?? '')) === '') {
            $errors['title'] = get_string('required');
        }
        if ((int) ($data['deadline'] ?? 0) < usergetmidnight(time())) {
            $errors['deadline'] = get_string('consent:error:deadline', $component);
        }
        if ($this->_customdata['context']->contextlevel != CONTEXT_COURSE) {
            $course = (int) ($data['courseid'] ?? 0);
            $cohort = is_array($data['cohortid'] ?? null) ? (int) reset($data['cohortid']) : (int) ($data['cohortid'] ?? 0);
            if (($course > 0) === ($cohort > 0)) {
                $errors['courseid'] = get_string('consent:error:who', $component);
            }
        }
        if ((int) ($data['keepdays'] ?? 0) < \local_oksigeniafamily\local\consent::MINKEEPDAYS) {
            $errors['keepdays'] = get_string('consent:error:keepdays', $component, \local_oksigeniafamily\local\consent::MINKEEPDAYS);
        }

        return $errors;
    }

    /**
     * Who the request goes to, from what was chosen.
     *
     * @param \stdClass $data The data of the form.
     * @return array scope (course, group or cohort) and its id.
     */
    public static function scope_of(\stdClass $data): array {
        if (!empty($data->who) && preg_match('/^(course|group):(\d+)$/', $data->who, $parts)) {
            return [$parts[1], (int) $parts[2]];
        }
        if (!empty($data->courseid)) {
            return ['course', (int) $data->courseid];
        }
        $cohort = is_array($data->cohortid ?? null) ? reset($data->cohortid) : ($data->cohortid ?? 0);

        return ['cohort', (int) $cohort];
    }
}
