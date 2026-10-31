# Changelog

All notable changes to `local_oksigeniafamily` are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the plugin follows Moodle's date-based versioning.

## [Unreleased]

### Added

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
