# Oksigenia Family (local_oksigeniafamily)

One record per household for Moodle: the adults and their children who are students, kept up to date from the
school's own data, and closed on its own when a student leaves.

Plugins that work with families (a school canteen, notices by WhatsApp, a school library, a mobile app) read the same
record instead of keeping their own list of who is family of whom. None of them depends on it: each one works without
this plugin and uses it when it is installed. It shows nothing to families by itself; each plugin shows its own part.

**Status:** early development.

## How families get in

Everything is under *Site administration ▸ Users ▸ Accounts ▸ Families*, for anyone with the capability
`local/oksigeniafamily:manage`, which the manager role has by default.

- **From a file.** A CSV with one row per person: `family` and `type` (`adult` or `child`), plus `firstname`, `lastname`,
  `email`, `phone`, `username`, `idnumber` and `key`. You see what would change before anything happens, with what is
  wrong in each row by its line, and repeating a file does not duplicate anything. A child needs a Moodle account or a
  key in your data. The page offers a file to start from.
- **With a code per student.** Choose a student, a group, a course or a cohort and print one letter per student, with
  the code and its QR. Each adult of the family joins on their own at `/local/oksigeniafamily/claim.php`, with no account.
  Codes are only kept as a hash, so a letter can only be printed from the page that issues it; a lost letter is replaced
  by a new code, and the old one stops working.
- **By hand**, for the odd case.

Students who leave take care of themselves: when their account is suspended or deleted they leave their families, and a
family with no children closes and is erased after the periods set in the plugin's settings.

## On the mobile app

These pages are for the school's staff, in a browser, also on a phone. The plugin adds nothing to the Moodle app:
each plugin that works with families shows its own part there.

## Requirements

- Moodle 4.5 or later (supported: 4.5 and 5.3).

## Install

Copy the plugin into `local/oksigeniafamily` of your Moodle and finish the upgrade from *Site administration*, or
install the ZIP from *Site administration ▸ Plugins ▸ Install plugins*.

## Licence

GNU GPL v3 or later. See [LICENSE](LICENSE).
