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
    'local/oksigeniafamily:requestconsent',
    get_config('local_oksigeniafamily', 'consents') === '0'
));
// The students' photos and what the families say about the use of their image.
$ADMIN->add('accounts', new admin_externalpage(
    'local_oksigeniafamily_photos',
    new lang_string('photos', 'local_oksigeniafamily'),
    new moodle_url('/local/oksigeniafamily/photos.php'),
    'local/oksigeniafamily:manage'
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
        // Requests for consent: a site that asks for them some other way turns them off, and nobody sees them.
        $settings->add(new admin_setting_configcheckbox(
            'local_oksigeniafamily/consents',
            new lang_string('settings:consents', 'local_oksigeniafamily'),
            new lang_string('settings:consents_desc', 'local_oksigeniafamily'),
            1
        ));
        // One photo per student for the plugins that show children, with the families' consent to the use of the image.
        // The word of the short address the letters print, where the site has a link shortener (nur.click/familias).
        $keyword = new admin_setting_configtext(
            'local_oksigeniafamily/claimkeyword',
            new lang_string('settings:claimkeyword', 'local_oksigeniafamily'),
            new lang_string('settings:claimkeyword_desc', 'local_oksigeniafamily'),
            '',
            PARAM_ALPHANUMEXT
        );
        // A new word, a new short address.
        $keyword->set_updatedcallback(fn() => unset_config('claimshort', 'local_oksigeniafamily'));
        $settings->add($keyword);
        // Every student has «For your family»: their QR, code and steps, for their family to join from the student's screen.
        $settings->add(new admin_setting_configcheckbox(
            'local_oksigeniafamily/studentpage',
            new lang_string('settings:studentpage', 'local_oksigeniafamily'),
            new lang_string('settings:studentpage_desc', 'local_oksigeniafamily'),
            1
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_oksigeniafamily/photos',
            new lang_string('settings:photos', 'local_oksigeniafamily'),
            new lang_string('settings:photos_desc', 'local_oksigeniafamily'),
            1
        ));

        // The households of the school's management program every night, when it is Cifra, and the connection with it:
        // optional, and once for every Oksigenia plugin of the site (local\cifra::of_site()).
        $p = 'local_oksigeniafamily';
        $settings->add(new admin_setting_heading(
            "$p/cifra",
            new lang_string('cifra', $p),
            new lang_string('cifra_desc', $p)
        ));
        $settings->add(new admin_setting_configcheckbox(
            "$p/" . \local_oksigeniafamily\local\import\cifra_households::SETTING,
            new lang_string('settings:cifrahouseholds', $p),
            new lang_string('settings:cifrahouseholds_desc', $p),
            0
        ));
        $settings->add(new admin_setting_description(
            "$p/cifrahouseholdsview",
            '',
            \local_oksigeniafamily\local\import\cifra_households::status_text()
        ));
        $settings->add(new admin_setting_configselect("$p/ciframode", new lang_string('ciframode', $p), '', '', [
            '' => new lang_string('ciframode_auto', $p),
            'oauth' => new lang_string('ciframode_oauth', $p),
            'key' => new lang_string('ciframode_key', $p),
        ]));
        $settings->add(new admin_setting_configtext(
            "$p/cifraurl",
            new lang_string('cifraurl', $p),
            new lang_string('cifraurl_desc', $p),
            '',
            PARAM_URL
        ));
        $settings->add(new admin_setting_configtext(
            "$p/cifraclientid",
            new lang_string('cifraclientid', $p),
            '',
            '',
            PARAM_TEXT
        ));
        $settings->add(new admin_setting_encryptedpassword("$p/cifrasecret", new lang_string('cifrasecret', $p), ''));
        $settings->add(new admin_setting_encryptedpassword(
            "$p/cifrakey",
            new lang_string('cifrakey', $p),
            new lang_string('cifrakey_desc', $p)
        ));
        $settings->add(new admin_setting_configtext(
            "$p/cifrainternalhosts",
            new lang_string('cifrainternalhosts', $p),
            new lang_string('cifrainternalhosts_desc', $p),
            '',
            PARAM_TEXT
        ));
    }
}
