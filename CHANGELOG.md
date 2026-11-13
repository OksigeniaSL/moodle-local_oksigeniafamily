# Changelog

All notable changes to `local_oksigeniafamily` are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the plugin follows Moodle's date-based versioning.

## [Unreleased]

### Added

- `api::families_of_user()` (0.3.15-dev): the open families where an account is an adult, with the source and key of
  each household, so another plugin goes from a person to their households (Eguahe's cards on the app's home) without
  the whole roll.

- A card for the home of the app (0.3.14-dev, `local_oksigeniafamily_oksigeniaaty_home_cards()`): the requests for
  consent still waiting for the adult's answer, with the children they are about and an «Answer» button, as a deadline
  that closes. With local_oksigeniaaty 0.5.60, they go up to «Today» as something to do.

- `api::each_household()` (0.3.13-dev): the roll of households one at a time, a few hundred loaded at once, with or
  without the adults. `households()` keeps its answer and is built on it. With 30,000 households the whole roll took
  0.6–1.4 s and 190 MB at once, too much for a web request with 128 MB; a plugin that only counts or asks about the
  children (Eguahe's re-enrolment) goes through it without the adults.
- `api::adults_of_children()`: the adults of many students in one go.

### Changed

- A request for consent works out how every student stands with a few queries, not one per student: a request to a
  whole school (3,000 students) was 3,002 queries for each row of the list.
- The suite of the CI runs with a read-only token.

## [0.3.12] - 2026-11-13

Published for the site with real families: a suspended student no longer loses their family, nor their family's
«no» to the use of their image.

From the first full review of Aty and Family: what could lose a family, or let the wrong person in, on a site with
real families.

### Fixed

- A student whose account was only suspended (the summer, a fee, a sync that suspended by mistake) comes back to their
  family when the account is active again, as long as the family is still open, and the family's «no» to the use of
  their image stays while the account exists. Before, a suspension took the student out for good: after the grace
  period the family closed, Aty's family account was deleted and the «no» was erased, so their photo showed again. Each
  child now keeps why they left (`leftreason`); the upgrade takes it from the log for those who already left.
- A student signed in can no longer claim their own code (the letter goes home in their bag) and so answer the requests
  about themselves; an adult that is the student's own account never answers for them; and an account that is a child
  in a family is never given the parent role over anyone.
- A phone an adult typed when claiming a code no longer becomes «vouched for» when the school changes their name or an
  import brings their e-mail: only a change of the number, or the school's mark «The school knows this mobile is
  theirs», vouches for it. Until then it never finds them on WhatsApp.
- «This file is the complete list» now has a brake: when it would take more than a fifth of the children without an
  account out of their families (and more than five), or comes with rows it did not understand, the number of children
  leaving has to be typed to apply it.
- Two claims at the same moment (a double tap, the app trying again) no longer make two families: one claim at a time
  per person or code, and the claim form goes once.
- A request for consent keeps its answers at least 30 days after the deadline: they are the outing's proof, and the
  outing may come after the deadline. Also for one stored with fewer days before.
- The e-mail of a request for consent opens the app for who has it: the account Aty makes for an adult never signs in
  on the web, and its button left them at the sign-in page.
- A plugin that fails while giving its links (canteen, library) no longer shows the adult an error once they have joined.

### Changed

- The event `adult_updated` carries `oldphonehash` (SHA-256 of the old number) instead of the number: the log keeps
  events for as long as the site wants, and an adult without an account cannot have them removed.
- The brake on guessing codes counts an IPv6 line by its /64, not each address in it.

## [0.3.11] - 2026-11-12

The first version of Family published for a site with real families: everything below, up to this point.

### Added

- The name and group of a student from the school's data (0.3.11-dev): `api::set_child_details()`, for a nightly sync
  from the school's management system, completes the students the families already have under a key (cifra:123) with
  their name and their group this school year, as the school names it; no one and no account is created, and running
  it again changes nothing. `api::households()` returns that name for a student without an account, and the group as
  `group`, so the youngest students, who have no account, can be asked about too.
- The whole roll of households for another plugin, such as Eguahe asking every family whether each child stays next
  school year (0.3.10-dev): `api::households()`, each household with its source and key, its children (account and
  name, or only their key) and its adults (name, e-mail and phone, from the account when the record has none), only the
  active ones or everyone, the gone and the closed with when.
- For a plugin that starts families itself, such as the admissions of Oksigenia Eguahe (0.3.9-dev):
  `api::adults_by_email()`, the adults with an address in their record or in their account, in any case, as a hint
  for a person to confirm (nobody vouches for an address); and `api::family_by_key()`, the open family of a household
  by its source and key, so a sibling who comes later joins the same family without the plugin keeping its id.
- A switch for the requests for consent (0.3.8-dev), on by default, for a site that asks for consent some other way:
  off, the school sees no requests (no page, no course link, no door in «Your work») and families have none to answer
  in the app or on a child's page. `api::consents_enabled()` says which.
- Doors in «Your work», the dashboard block of Oksigenia Tembiapo (contract version 2), from
  `local_oksigeniafamily_oksigenia_work()` (0.3.7-dev): the family records for whoever runs them, with their figures
  and the codes and the import at hand; the requests for consent for whoever asks for them on the site, or for a
  teacher while one of theirs is open, with an alert on the day for each of their own that closes with students still
  without an answer. Kept for a minute per person (cache `work`). The plugin's official logo in `pix/icon.svg`.
- Students' photos and the consent to the use of their image (0.3.4-dev), in one place for every plugin that shows
  children (screens, the dining room, the library), also for students with no account. A photo is held under the
  student's account or their key in the school's data, stored at 512 px as JPEG without its EXIF data. The school
  uploads a zip named after each student's number (the ID number of the account, or the key in a source), or a folder
  from the command line (`cli/import_photos.php`). A standing consent, with no deadline, holds what each family says
  about the use of the image: the last answer of each adult counts, plus the school's until an adult answers after it,
  and any no among them hides the photo; with no answer, it is shown (0.3.5-dev). The school loads its answers from any
  spreadsheet, choosing the columns. `api::photo_for_child()`, `photo_for_user()` and `photo_for_key()` give the photo
  unless that no (the account's picture first), `photo_allowed_for_*()` tell "no photo" from "not to be shown", and `set_photo()`,
  `set_photo_for_user()` and `student_key()` bring photos from another plugin; `photos_enabled()` (0.3.6-dev) says
  whether the site keeps photos in Family at all. A photo and its answers are erased with
  the grace period after the student leaves. A switch in the settings, on by default. Tables
  `local_oksigeniafamily_photo` and `_standing`, in the privacy provider.
- Requests for consent (0.3.0-dev). The school asks the families of some students for their consent, and each adult
  answers yes or no: from Moodle's notification (app, e-mail or site) with an account, from a signed personal link by
  e-mail without one, or with two buttons on WhatsApp and Telegram through Oksigenia Marandu's `ask()` and its hook
  `answer_received`. A new capability, `local/oksigeniafamily:requestconsent`, for teachers in their course and
  managers on the site. The school's page shows who said what and who is missing, reminds those missing, adds new
  students of the course, writes down paper answers and downloads the whole history (CSV, PDF). Answers are never
  changed, only added; a «no» from any adult counts as a no, and whoever made the request is told when two adults
  disagree. A reminder the day before the deadline, and erasing after the retention, by a new scheduled task. Tables
  `local_oksigeniafamily_request`, `_target` and `_answer`, in the privacy provider.
- `api::pending_consents($childuserid, $keyid)` (0.3.1-dev), for a plugin that gives a student's family a key of its own,
  such as the canteen's link with its PIN: the requests still waiting for that student, each with a signed link. The key
  is the student's, so the page asks which of the student's adults is answering, and keeps the key's id (never the key);
  a request that needs every adult is not answered there.
- On a child's page in Oksigenia Aty, a card with the open requests about the child and what the adult answered, each
  opening the request (`local_oksigeniafamily_oksigeniaaty_child_cards()`, 0.3.1-dev).
- The requests for consent inside the Moodle app and Oksigenia Aty (0.3.3-dev): the page of a request, with the two
  buttons for each child and a comment, answered through a new web service, `local_oksigeniafamily_answer_consent`;
  «Requests for consent» in the app's menu for whoever has one to answer; and the links of the notices and of a child's
  page in Aty open that page instead of the site in a browser, where an account of Aty cannot sign in. What the school
  wrote travels to the app as data, never inside the template it compiles. The comment folds away behind «Add a comment»,
  as on the web, and is a few lines high, with a small sheet of the plugin's own for the app.

### Fixed

- The «Consents» item of the school's menu showed its string's name: `nav:consents` was missing (0.3.8-dev).
- The OneRoster reader, up to date with Oksigenia Ayvurenda's: a zip without a manifest no longer counts as a full export,
  so it takes no one out (0.3.3-dev).
- On a child's page in Aty, a request's row no longer repeats its deadline: the row's date already says it (0.3.3-dev).
- Hover effects only where a pointer hovers, so that a touch on a tablet or a classroom board does not leave them stuck
  (0.3.3-dev).
- The page behind a signed link, on the site's sign-in layout, puts its heading in the middle and clear of the top, so
  that a sign-in box with arched corners does not cut it (0.3.3-dev).
- The label of each adult in Marandu's list of numbers moves to `marandu:adultname`: `marandu:label` is what the plugin
  is in a few words («family records»), as Marandu reads it. The WhatsApp text of a request starts with the name of the
  site, so any organisation can use the same approved template (0.3.2-dev).
- The two text columns of the requests without an empty default, which Moodle warns about on installing (0.3.1-dev).
- The school's side, with the accounts of the site (*Site administration ▸ Users ▸ Accounts ▸ Families*), for anyone
  with `local/oksigeniafamily:manage`, without needing to be a site administrator:
  - The list of families, with a search by name, mobile phone, e-mail or key, and each family's page: its adults and
    children with how they stand, removing either, changing an adult's details, and a new family by hand. Lists show
    only names; phones, e-mails and codes stay in each family's page.
  - Importing families from a CSV file: one row per person, separator and encoding found on their own, headings in
    English or Spanish. A preview says what would change, with what is wrong in each row by its line. Repeating a file
    changes nothing: a family is known by its key, an adult within it by their key, account or name, and a child by
    their account or key. An empty cell wipes nothing. Optionally, the file is the complete list, and children without
    an account who are no longer in it leave. The importer works on rows, so the management programs' readers can feed
    it later with their own source and keys.
  - Codes and letters: one search for a student, a group, a course or a cohort; how each one stands; one letter per
    student and per sheet, with the code and its QR, for those with no family and no code, for those with no code, or
    for everyone with a new code; withdrawing codes, by student or for all of them.
- Events `code_issued` and `codes_revoked`: who issued or withdrew a student's code, never the code.
- `api::add_child()` gives a known child what they lacked: the account of one known by key, or the key of one known by
  account. `api::revoke_claim_codes()` says how many it stopped.
- The daily task also erases adults and children who left a family that goes on, and codes that no longer work, after
  the retention period.
- The notice of a new adult takes those who manage the families to the family's page, where they can remove them.

### Security

- `api::claim()` joins an adult by phone only with its new `$phoneverified` set to true, as for a number WhatsApp vouches
  for. A phone that was only typed, as on the claim page, used to get whoever held a valid code and knew another parent's
  number into that parent's family. Now it is kept with a new adult in a new family, and nothing of the other adult
  changes. Joining by the account of who is signed in stays as it was. Callers that vouch for the number have to pass
  `true`.
- The letter's QR brings the code after the `#` of the claim page's address, which browsers do not send: it stays out of
  the server's logs. A code exists in the clear only in the page that issues the letters, which is not cached and
  answers only once; a lost letter is replaced by a new code.

- The notice that a new adult joined a child also reaches the phone, in the Moodle app or Oksigenia Aty.
- For Oksigenia Marandu's list of numbers: who each adult is (`local_oksigeniafamily_oksigeniamarandu_label()`, such
  as "Lucía Sanz (family of Ana)") and the string `marandu:item`.
- For WhatsApp (Oksigenia Marandu): `api::adults_by_phone()`, every adult with a number; `api::update_adult()`,
  with the event `adult_updated` carrying the phone the adult had, so the old number is let go at once; an adult
  known by their account who claims with a new phone keeps it. `claim()` says that it does not slow down guessing by
  itself: the claim page does, by address, and callers from shared addresses slow down by their own key.
- The page where a family claims a student's code (`claim.php`): no account needed, the adult's name and, if they
  give them, a mobile phone (kept in E.164, with the site's country code) and an e-mail; a signed-in adult joins with
  their account. After it, what each plugin gives that family for the child, from
  `<component>_oksigeniafamily_links(\stdClass $adult, int $childuserid)`, one or several links per child, each
  with an optional PIN shown apart and an optional note. Ten wrong codes from one address in
  fifteen minutes and it waits.
- The family records: households with their adults (with or without a Moodle account) and their children, who are
  students with an account or only a key in the school's data (`cifra:<id>`, `oneroster:<sourcedId>`).
- The API for other plugins (`\local_oksigeniafamily\api`): who is family of whom by account, by adult and by phone,
  a student's key in a source, and the changes (start a family, add an adult or a child, give an adult an account,
  a child or an adult leaving, closing a family).
- Claim codes: eight characters that are not read wrong, kept only as a hash, that join a family to a student; a
  known adult (by account or by a phone someone vouches for) brings the student into their family, a new one starts
  their own. The
  student's other adults and the school's managers are told when a new adult joins.
- Keeping up to date on its own (a daily task and an observer): children whose account is deleted or suspended
  leave, families with no children left close after a grace period, and closed families are erased after their
  retention period. Events for each change, for other plugins to take back their own part.
- Moodle's parent role, optional: given to each adult with an account over each of their children and taken away
  when either leaves, as assignments of this plugin, so the ones the school gives by hand are never touched.
- Privacy: what belongs to a Moodle account is exported and deleted with it.
- The base of the plugin: version, strings in English and Spanish, a privacy declaration (no personal data yet) and the
  continuous integration of the other Oksigenia plugins (Moodle Plugin CI on Moodle 4.5 and 5.3, PostgreSQL and
  MariaDB).
