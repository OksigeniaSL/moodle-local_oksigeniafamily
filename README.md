# Oksigenia Family (local_oksigeniafamily)

One record per household for Moodle: the adults and their children who are students, kept up to date from the
school's own data, and closed on its own when a student leaves.

Plugins that work with families (a school canteen, notices by WhatsApp, a school library, a mobile app) read the same
record instead of keeping their own list of who is family of whom. None of them depends on it: each one works without
this plugin and uses it when it is installed. It shows nothing to families by itself; each plugin shows its own part.

**Status:** early development. The design is being agreed with the plugins that will use it; the first version brings
the records, how they are filled (an import from the school's data, and a code per student that a family claims) and
the automatic leaving.

## Requirements

- Moodle 4.5 or later (supported: 4.5 and 5.3).

## Install

Copy the plugin into `local/oksigeniafamily` of your Moodle and finish the upgrade from *Site administration*, or
install the ZIP from *Site administration ▸ Plugins ▸ Install plugins*.

## Licence

GNU GPL v3 or later. See [LICENSE](LICENSE).
