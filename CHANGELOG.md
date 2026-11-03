# Changelog

All notable changes to `local_oksigeniafamily` are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the plugin follows Moodle's date-based versioning.

## [Unreleased]

### Added

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
  wrote travels to the app as data, never inside the template it compiles.

### Fixed

- On a child's page in Aty, a request's row no longer repeats its deadline: the row's date already says it (0.3.3-dev).
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
