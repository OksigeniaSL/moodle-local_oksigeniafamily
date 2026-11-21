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
 * «Invite another adult»: the QR another adult of the household scans to join this family, with all its children.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniafamily\local\join;
use local_oksigeniafamily\local\page;

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('guestsarenotallowed');
}
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/oksigeniafamily/invite.php'));
$PAGE->set_pagelayout('standard');
page::dress(get_string('invite:title', 'local_oksigeniafamily'));

$context = join::invite_context((int) $USER->id);
// The button «Copy»: the message goes to the clipboard, and the button says so for a moment (and a screen reader, once).
$PAGE->requires->js_amd_inline("
    require([], function() {
        document.querySelectorAll('[data-okf-copy]').forEach(function(button) {
            var label = button.querySelector('.okf-share-label');
            var said = button.parentNode.querySelector('.okf-share-said');
            var idle = label ? label.textContent : '';
            var done = function() {
                button.classList.add('is-done');
                if (label) {
                    label.textContent = button.getAttribute('data-okf-copied');
                }
                if (said) {
                    said.textContent = button.getAttribute('data-okf-copied');
                }
                window.setTimeout(function() {
                    button.classList.remove('is-done');
                    if (label) {
                        label.textContent = idle;
                    }
                }, 2000);
            };
            var fallback = function(text) {
                var box = document.createElement('textarea');
                box.value = text;
                box.setAttribute('readonly', '');
                box.style.position = 'fixed';
                box.style.opacity = '0';
                document.body.appendChild(box);
                box.select();
                try {
                    if (document.execCommand('copy')) {
                        done();
                    }
                } finally {
                    document.body.removeChild(box);
                }
            };
            button.addEventListener('click', function() {
                var text = button.getAttribute('data-okf-copy');
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done, function() {
                        fallback(text);
                    });
                } else {
                    fallback(text);
                }
            });
        });
    });
");

echo $OUTPUT->header();
if ($context) {
    echo $OUTPUT->render_from_template('local_oksigeniafamily/join', $context);
} else {
    echo $OUTPUT->notification(get_string('invite:none', 'local_oksigeniafamily'), \core\output\notification::NOTIFY_INFO);
}
echo $OUTPUT->footer();
