# Oksigenia Family (local_oksigeniafamily)

One record per household for Moodle: the adults and their children who are students, kept up to date from the
school's own data, and closed on its own when a student leaves.

Plugins that work with families (a school canteen, notices by WhatsApp, a school library, a mobile app) read the same
record instead of keeping their own list of who is family of whom. None of them depends on it: each one works without
this plugin and uses it when it is installed. Besides requests for consent, it shows nothing to families by itself; each
plugin shows its own part.

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

## Requests for consent

A trip, the photos of the year, going home alone: the school asks the families of some students for their consent, and
each adult answers «I consent» or «I do not consent» wherever the request reaches them.

- **Who asks.** Anyone with `local/oksigeniafamily:requestconsent`: teachers in their course (*More ▸ Requests for
  consent*), for the whole course or one of its groups; whoever runs the site, for a course or a cohort, under
  *Site administration ▸ Users ▸ Accounts ▸ Requests for consent*. A request has a title, a text, an optional circular
  and a deadline. One adult of each student is enough, or every adult can be required; a «no» from any of them counts
  as a no.
- **How it reaches families.** Adults with an account get a notification of Moodle, in the app, by e-mail or on the
  site, as each one chose. Adults with no account get an e-mail with a personal link, signed by the site, to a page
  where they answer without signing in. With Oksigenia Marandu, a question with two buttons also goes by WhatsApp or
  Telegram to whoever signed up there. The day before the deadline, whoever has not answered gets a reminder.
- **What the school sees.** How many said yes and no and who is missing, every student with what each adult answered,
  when and how; a reminder button, new students of the course added later, answers that came on paper written down,
  and the whole history to download as CSV or PDF.
- **The record.** An answer is never changed: changing one's mind adds a new answer, and the history is what is kept.
  A request is erased with its answers a number of days after its deadline (365 by default, per request).

A site that asks for consent some other way turns the requests off in the settings: then nobody sees them.

## On the mobile app

The school's pages are for its staff, in a browser, also on a phone. In the Moodle app, families answer requests for
consent on a page of the app itself, which opens from its notification, from the menu (*Requests for consent*, for
whoever has one to answer) and, in Oksigenia Aty, from each child's page. Everything else each plugin that works with
families shows on its own.

With Oksigenia Tembiapo («Your work» on the dashboard), whoever runs the family records gets a door to them with their
figures, and whoever asks for consent gets one to their requests, told on the day which close with students still
without an answer.

## Requirements

- Moodle 4.5 or later (supported: 4.5 and 5.3).

## Install

Copy the plugin into `local/oksigeniafamily` of your Moodle and finish the upgrade from *Site administration*, or
install the ZIP from *Site administration ▸ Plugins ▸ Install plugins*.

## Licence

GNU GPL v3 or later. See [LICENSE](LICENSE).
