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

/**
 * Settings of local_oksigeniafamily.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Whoever manages the families finds them with the accounts of the site, also without being a site administrator.
$ADMIN->add('accounts', new admin_externalpage(
    'local_oksigeniafamily_families',
    new lang_string('families', 'local_oksigeniafamily'),
    new moodle_url('/local/oksigeniafamily/index.php'),
    'local/oksigeniafamily:manage'
));
// The requests for consent of the whole site; teachers find those of their course in the course.
$ADMIN->add('accounts', new admin_externalpage(
    'local_oksigeniafamily_consents',
    new lang_string('consent:title', 'local_oksigeniafamily'),
    new moodle_url('/local/oksigeniafamily/consents.php'),
    'local/oksigeniafamily:requestconsent'
));

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_oksigeniafamily', new lang_string('pluginname', 'local_oksigeniafamily'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_description(
            'local_oksigeniafamily/where',
            '',
            get_string('settings:where', 'local_oksigeniafamily', (new moodle_url('/local/oksigeniafamily/index.php'))->out(false))
        ));
        // The role given to each adult with an account over each of their children (Moodle's parent or mentor role).
        $roles = [0 => new lang_string('none')];
        foreach (get_roles_for_contextlevels(CONTEXT_USER) as $roleid) {
            $roles[$roleid] = role_get_name(get_role_instance($roleid), null, ROLENAME_ALIAS);
        }
        $settings->add(new admin_setting_configselect(
            'local_oksigeniafamily/parentroleid',
            new lang_string('settings:parentroleid', 'local_oksigeniafamily'),
            new lang_string('settings:parentroleid_desc', 'local_oksigeniafamily'),
            0,
            $roles
        ));
        $settings->add(new admin_setting_configtext(
            'local_oksigeniafamily/phoneprefix',
            new lang_string('settings:phoneprefix', 'local_oksigeniafamily'),
            new lang_string('settings:phoneprefix_desc', 'local_oksigeniafamily'),
            '',
            PARAM_NOTAGS
        ));
        $settings->add(new admin_setting_configtext(
            'local_oksigeniafamily/claimdays',
            new lang_string('settings:claimdays', 'local_oksigeniafamily'),
            new lang_string('settings:claimdays_desc', 'local_oksigeniafamily'),
            60,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_oksigeniafamily/gracedays',
            new lang_string('settings:gracedays', 'local_oksigeniafamily'),
            new lang_string('settings:gracedays_desc', 'local_oksigeniafamily'),
            30,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_oksigeniafamily/retentiondays',
            new lang_string('settings:retentiondays', 'local_oksigeniafamily'),
            new lang_string('settings:retentiondays_desc', 'local_oksigeniafamily'),
            90,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_oksigeniafamily/consentkeepdays',
            new lang_string('settings:consentkeepdays', 'local_oksigeniafamily'),
            new lang_string('settings:consentkeepdays_desc', 'local_oksigeniafamily'),
            365,
            PARAM_INT
        ));
    }
}
