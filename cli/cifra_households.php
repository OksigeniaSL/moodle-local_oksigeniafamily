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
 * The households of Cifra: what a round would do, every household it would make or change, every child who would
 * leave and every adult who is no longer in their household in Cifra; with --apply, the round itself, as the nightly task
 * does it. Nothing is sent to anyone.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniafamily\local\import\cifra_households as households;

[$options, $unrecognised] = cli_get_params(
    ['apply' => false, 'confirm' => '', 'summary' => false, 'pause' => '', 'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown options: ' . implode(', ', $unrecognised));
}
if ($options['help']) {
    echo "The households of Cifra, the school management program.

Without options, it reads Cifra and says what a round would do: every household it would make or change, every
child who would leave, every adult who would leave their household because Cifra no longer has them in it (those who
never joined), and every adult in that case who has joined, for a person to look at. Nothing changes.

Options:
  --summary      Only the counts.
  --apply        Do the round, as the nightly task does. Nothing is sent to anyone.
  --confirm=N    With --apply, how many children leave, when the brake of a complete list stopped a round.
  --pause=MS     Milliseconds between two records of Cifra (default " . (households::PAUSE / 1000) . ").
  -h, --help     This help.
";
    exit(0);
}
\core\session\manager::set_user(get_admin());
$pause = $options['pause'] === '' ? households::PAUSE : max(0, (int) $options['pause']) * 1000;

if ($options['apply']) {
    $confirm = $options['confirm'] === '' ? null : (int) $options['confirm'];
    $result = households::run(time(), null, $confirm, $pause);
    echo households::status_text() . "\n";
    exit($result['state'] === 'done' ? 0 : 1);
}

try {
    $dry = households::dry_run(time(), null, $pause);
} catch (moodle_exception $e) {
    cli_error($e->getMessage());
}
$plan = $dry['plan'];
$counts = $plan['counts'];
$gone = $dry['gone'];
echo "Students in Cifra: {$dry['students']}. Households: {$dry['households']}.\n";
echo "Would make {$counts['familiesnew']} new households; {$counts['familiesjoined']} were already in Family. "
    . "Adults added: {$counts['adultsnew']}, with changes: {$counts['adultschanged']}, "
    . "leaving: " . count($gone['leaving']) . ", to look at: " . count($gone['review']) . ". "
    . "Children added: {$counts['childrennew']}, with changes: {$counts['childrenchanged']}, "
    . "leaving: {$counts['childrenleaving']}. "
    . "As they are: {$counts['same']}. Rows not understood: {$counts['errors']}.\n";
if ($plan['guarded']) {
    echo "The brake of a complete list would stop the round: to apply it, --apply --confirm=" . count($plan['leaving']) . ".\n";
}
if ($options['summary']) {
    exit(0);
}

$name = function (array $child) use ($dry): string {
    $key = (string) ($child['sourcekey'] ?? '');
    return ($dry['names'][$key] ?? '') ?: $key;
};
foreach ($plan['families'] as $family) {
    $adults = array_filter($family['adults'], fn($adult) => !empty($adult['seen']));
    $children = array_filter($family['children'], fn($child) => !empty($child['seen']));
    $changed = !$family['id'];
    foreach (array_merge($adults, $children) as $person) {
        $changed = $changed || empty($person['id']) || !empty($person['changes']);
    }
    if (!$changed) {
        continue;
    }
    $what = !$family['id'] ? 'New household' : (!empty($family['joined']) ? "Household {$family['id']}, already in Family"
        : "Household {$family['id']}");
    echo "\n$what:\n";
    foreach ($adults as $adult) {
        $data = $adult['data'];
        $mark = empty($adult['id']) ? '+' : (!empty($adult['changes']) ? '~' : ' ');
        $contact = implode(', ', array_filter([(string) ($data['email'] ?? ''), (string) ($data['phone'] ?? '')]));
        $account = !empty($data['userid']) ? ' [account]' : '';
        $who = trim(($data['firstname'] ?? '') . ' ' . ($data['lastname'] ?? ''));
        echo "  $mark " . $who . ($contact !== '' ? " ($contact)" : '') . $account . "\n";
    }
    foreach ($children as $child) {
        $mark = empty($child['id']) ? '+' : (!empty($child['changes']) ? '~' : ' ');
        echo "  $mark " . $name($child) . (!empty($child['userid']) ? ' [account]' : ' [no account]') . "\n";
    }
}
foreach ($plan['leaving'] as $child) {
    echo "\nLeaving: " . ($dry['names'][$child->sourcekey] ?? $child->sourcekey) . " (household {$child->familyid})\n";
}
if ($gone['leaving']) {
    [$in, $params] = $DB->get_in_or_equal($gone['leaving']);
    $adults = $DB->get_records_select('local_oksigeniafamily_adult', "id $in", $params, 'familyid, id');
    foreach ($adults as $adult) {
        $who = trim($adult->firstname . ' ' . $adult->lastname) ?: $adult->sourcekey;
        echo "\nAdult leaving, no longer in the household in Cifra and never joined: $who (household {$adult->familyid})\n";
    }
}
foreach ($gone['review'] as $adult) {
    // They have joined, or the school welcomed them: a person decides, from the household's page in Family.
    $who = $adult['name'] !== '' ? $adult['name'] : "adult {$adult['adultid']}";
    echo "\nTo look at, no longer in the household in Cifra but already joined: $who "
        . "(household {$adult['familyid']}: /local/oksigeniafamily/family.php?id={$adult['familyid']})\n";
}
foreach ($plan['errors'] as $error) {
    echo "\nRow {$error['line']}: {$error['message']}\n";
}
