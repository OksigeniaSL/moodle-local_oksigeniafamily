# Changelog

All notable changes to `local_oksigeniafamily` are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the plugin follows Moodle's date-based versioning.

## [Unreleased]

### Added

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
  known adult (by account or phone) brings the student into their family, a new one starts their own. The
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
