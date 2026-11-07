<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Store a folder of students' photos at once, each named after the student's number (1234.jpg), for batches too big for
 * the upload page. Without --run it only says what it would do.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniafamily\local\photos;

[$options, $unrecognised] = cli_get_params(
    ['dir' => '', 'kind' => 'idnumber', 'run' => false, 'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown options: ' . implode(', ', $unrecognised));
}
$kinds = photos::kinds();
if ($options['help'] || $options['dir'] === '') {
    echo "Store a folder of students' photos, each named after the student's number (1234.jpg).

Options:
  --dir=PATH    The folder with the photos (searched with its subfolders).
  --kind=KIND   What the number is: " . implode(', ', array_keys($kinds)) . " (default idnumber, the ID number of the account).
  --run         Store them. Without it, only count what would happen.
  -h, --help    This help.
";
    exit(0);
}
if (!photos::enabled()) {
    cli_error("The site keeps no students' photos (setting local_oksigeniafamily/photos).");
}
if (!is_dir($options['dir'])) {
    cli_error('Not a folder: ' . $options['dir']);
}
if (!array_key_exists($options['kind'], $kinds)) {
    cli_error('Unknown kind: ' . $options['kind'] . '. Known: ' . implode(', ', array_keys($kinds)));
}

\core\session\manager::set_user(get_admin());
$result = photos::import_dir($options['dir'], $options['kind'], (bool) $options['run']);
echo ($options['run'] ? 'Stored' : 'Would store') . ": {$result['saved']}. Matching no student: {$result['unmatched']}. "
    . "Not an image: {$result['notimage']}.\n";
if ($result['names']) {
    echo 'Numbers matching no student: ' . implode(', ', array_slice($result['names'], 0, 200))
        . (count($result['names']) > 200 ? ' …' : '') . "\n";
}
