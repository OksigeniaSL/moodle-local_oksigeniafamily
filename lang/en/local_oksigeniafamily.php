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
 * English strings for local_oksigeniafamily.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['cachedef_claimattempts'] = 'Failed code tries per visitor';
$string['claim:badphone'] = 'This does not look like a phone number.';
$string['claim:code'] = 'Code';
$string['claim:done'] = 'You are now family of {$a->child} at {$a->site}.';
$string['claim:email'] = 'E-mail (optional)';
$string['claim:intro'] = 'Type the code the school gave you and your details. You do not need an account.';
$string['claim:links'] = 'What you have now:';
$string['claim:nolinks'] = 'The school will tell you how to go on.';
$string['claim:phone'] = 'Mobile phone (optional)';
$string['claim:pin'] = 'PIN (keep it: it is only shown now)';
$string['claim:submit'] = 'Join';
$string['claim:title'] = 'Join a student\'s family';
$string['claim:toomany'] = 'Too many tries. Wait a while and try again.';
$string['claiminvalid'] = 'This code does not work: it may be mistyped, out of date or withdrawn by the school.';
$string['event:adultadded'] = 'Adult joined a family';
$string['event:adultremoved'] = 'Adult left a family';
$string['event:childlinked'] = 'Child joined a family';
$string['event:childunlinked'] = 'Child left a family';
$string['event:familyclosed'] = 'Family closed';
$string['messageprovider:newadult'] = 'A new adult joins a student\'s family';
$string['notice:newadult'] = 'Someone with the code of {$a->child} has joined their family as {$a->adult}. If you do not know them, tell the school so it can remove them.';
$string['notice:newadult:subject'] = '{$a->adult} has joined as family of {$a->child}';
$string['notice:someone'] = 'an adult';
$string['oksigeniafamily:manage'] = 'Manage the family records';
$string['pluginname'] = 'Oksigenia Family';
$string['privacy:metadata:adult'] = 'The adults of each household, with or without a Moodle account.';
$string['privacy:metadata:adult:email'] = 'The adult\'s e-mail, if the school or the adult gave it.';
$string['privacy:metadata:adult:firstname'] = 'The adult\'s first name.';
$string['privacy:metadata:adult:lastname'] = 'The adult\'s last name.';
$string['privacy:metadata:adult:phone'] = 'The adult\'s phone number, if the school or the adult gave it.';
$string['privacy:metadata:adult:userid'] = 'The adult\'s Moodle account, if they have one.';
$string['privacy:metadata:child'] = 'The students who are the children of each household.';
$string['privacy:metadata:child:status'] = 'Whether the student is still in the family.';
$string['privacy:metadata:child:timeleft'] = 'When the student left the family.';
$string['privacy:metadata:child:userid'] = 'The student\'s Moodle account.';
$string['privacy:metadata:claim'] = 'The codes that join a family to a student (only their hash is kept).';
$string['privacy:metadata:claim:userid'] = 'The student the code is for.';
$string['privacy:metadata:claim:usermodified'] = 'Who issued the code.';
$string['privacy:metadata:sourcekey'] = 'The person\'s key in the school\'s own data (such as its management system).';
$string['privacy:metadata:timecreated'] = 'When the record was made.';
$string['settings:claimdays'] = 'Codes last (days)';
$string['settings:claimdays_desc'] = 'How many days a student\'s code joins families after it is issued.';
$string['settings:gracedays'] = 'Grace before closing (days)';
$string['settings:gracedays_desc'] = 'How long a family with no children left waits before it closes. A student who comes back within it finds the family as it was.';
$string['settings:parentroleid'] = 'Role for adults with an account';
$string['settings:parentroleid_desc'] = 'The role given to each adult with a Moodle account over each of their children, such as Moodle\'s parent or mentor role. It is taken away on its own when either leaves. None: no role is given.';
$string['settings:phoneprefix'] = 'Country code for phones';
$string['settings:phoneprefix_desc'] = 'Added to the phone numbers typed without one, such as +34.';
$string['settings:retentiondays'] = 'Keep closed families (days)';
$string['settings:retentiondays_desc'] = 'How long a closed family is kept before it is erased with its adults.';
$string['task:sync'] = 'Keep the family records up to date';
