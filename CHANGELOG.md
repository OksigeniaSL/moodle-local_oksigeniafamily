# Changelog

All notable changes to `local_oksigeniafamily` are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the plugin follows Moodle's date-based versioning.

## [Unreleased]

### Added (0.3.39-dev)

- `api::adults_of_child()` takes an optional `$leftsince`: with a time, it also counts a student who left the family at
  or after it, while the family is still open. A goodbye sent after the school's data took the student away (Oksigenia
  Ayvurenda's booklet of a whole school life) still reaches the family. Without it, as before.

### Changed (0.3.38-dev, Jujol's design of the 21-11 review)

- A request for consent in the app: «I authorise» and «I do not authorise» side by side and the same size; once
  answered, the answer with its date in words («You authorised on 3 November at 20:11») and «Change the answer», which
  brings the two buttons back with «Leave it as it was». A single tap no longer changes an answer. The date in words
  (`consent_view::datetime()`) is the web's and the team's list's too.

### Added (0.3.38-dev)

- «Not of my family» (`notyours.php`, `local\notyours`): the notice that someone joined a family with a code takes the
  family's other adults to a page where they take the newcomer out themselves, and the code they came with stops working.
  Before, the notice said to tell the school, which had to do it. Only someone who joined with a code in the last month:
  an adult the school's data brought stays the school's, so that one parent cannot take the other out. The code still
  serves several adults (a father, then a mother, then a grandmother); each one who joins is told to the others.

### Changed (0.3.37-dev)

- Its notices in the app (a request for consent, a conflict between answers, a new adult, an import that ended) carry
  the plugin's icon instead of the app's generic pencil: the app draws its own icon only beside notices of activities.

### Security (0.3.36-dev)

- The claim page answers alike whether the e-mail typed has an account of the site or not, so that a good code is no
  longer a way to find out who has one: whoever types it joins at once as an adult without an account, as with any
  other address or none, and an address that any account has (a student's too) is never kept with them. If it is the
  address of an account that signs in as a person does, and not a student's, a link to put the child into that account
  goes to it, and the page says the same in both cases («If that address has an account…»). Once its owner confirms,
  the adult they became goes away when it was only that (`claimmail::merge()`: alone in a family the claim made, with
  only children the account now has); anything else stays for the school. Before, «Check your e-mail» against «You are
  now family» said whether the address had an account, and every try with another address sent another e-mail.
- Brakes on the claim page besides the one on wrong codes (`throttle::LIMITS`): ten claims of one good code a day, three
  links to one account a day, ten links from one visitor's address an hour. Over a code's limit the page asks to try
  tomorrow; over a link's, the link does not go and the page says nothing different.
- Taking an adult out of a family by hand stops the family's invitation and the codes its children's own pages show
  (`api::remove_adult(..., 'manual')`), since the adult may have seen them; taken out by the school's data (`import`),
  only the invitation. A child taken out of a family loses the code of their own page, and, taken out by hand, the
  family its invitation. The pages make new ones when they are opened; a letter's code stays, for the school to
  withdraw from the family's page. Before, an adult removed could join again with the invitation they had seen.
- An adult the school's data brought, still quiet, who answers a request for consent by themselves (on the site, in the
  app, by e-mail) comes in (`api::wake_adult()`), and their answer counts. Before, it was kept and did not count. A paper
  answer the school writes down for them does not wake them.
- The claim page sent again (a reload) is the same adult: one without an account who joined that family less than a day
  ago, by a claim, with the same name and the same phone or e-mail; for a student's code, only in a family where that
  student is the only child, so that it gives nothing the code alone would not. The others are told once. Before, each
  reload added an adult, and for a letter's code a whole family.
- The import of a file runs in the background as whoever applied it (`set_userid()`), so the log says who changed the
  families. A failed import is erased with its file (names, phones, e-mails) a week after Moodle stops trying it again,
  not a month (`job::FAILEDDAYS`).
- An adult is linked to an account of the site by an e-mail alone only when the account's first name is theirs too
  (`names::same_first()`: the same without case nor accents, or one the start of the other, as «María» and «María
  José»): in the households of Cifra (`cifra_households::rows()`) and when an account signed in joins a household
  waiting for its adults (`api::quiet_adult_of_user()`). The e-mail Cifra had for some parents was a teacher's account,
  typed by mistake, and they were linked to it. One that does not match is not linked: `cli/cifra_households.php` lists
  it as «To look at», and one linked before waits in «Families» (`families::review()`, «Linked by their e-mail to an
  account with another first name») for a person to unlink it from «Edit».
- The privacy provider declares the family of each adult and child, whether an adult's phone is vouched for and whether
  they are quiet, the purpose, family and sealed code of the codes the pages show, and the table of the links already
  used (`local_oksigeniafamily_spent`, only hashes).

### Changed (0.3.36-dev)

- On a new site no role has `local/oksigeniafamily:manage` nor `local/oksigeniafamily:requestconsent` by itself (before,
  the manager role had both): they reach every family's data, and the school gives them to whoever runs the families.
  A course's teachers keep `requestconsent` in their course. Only new sites: on upgrading, Moodle does not change what a
  role already has, so a site that wants the manager role without them takes them away by hand.
- «Invite another adult»: the code goes by WhatsApp (a `wa.me` link with the message) or copied, from two buttons; under
  them, the adults of the family who have already joined. Its words: «For another adult to see your children too, show
  them this QR or send them the code.», and at the end «Whoever has this code joins your family: give it only to someone
  who should see your children.»; when it expires is said once. In the app, the copy is the app's own
  (`core-copy-to-clipboard`) and WhatsApp opens outside it (`core-link`).
- The letters go to the students whose family has nobody who has joined (the quiet adults the school's data brought are
  not counted), and «Code not used» and a code in use count only a letter's codes, not the one a student's own page
  shows nor an invitation (`students::state()`, the need `code`). Before, a student with only quiet adults, or who had
  opened their own page, got no letter.
- «For your family» (`studentpage`) is off on a new site; a site that had it keeps it, also one that never saved it.
- An invitation whose child has left the family gives as `childuserid` one who is still in it; with no child left with an
  account, it joins nobody (`claiminvalid`).
- The top bar asks whether to show «Family» once a minute per person (`join::in_bar()`, cache `joinbar`), not on every
  page; the page itself and the app ask `join::eligible()`.
- When the link shortener gives no short address, the letters and the messages use the long one for fifteen minutes (a
  day when asking again would get the same) instead of waiting for it at every view (`letters::kept_short()`).

### Added (0.3.36-dev)

- `api::account_reassigned($userid)`: an account the school gives to somebody else (that of someone who leaves, given to
  who comes) lets go of every adult it was, who stay in their family without an account (`adult_updated` for each), and
  of the parent role this plugin gave it in every family. An account that is a child of an open family is left as it
  is. For the script of the server that hands the account over.
- «Families» has the tile «To look at» while the last round of Cifra leaves adults for a person to look at: those who
  joined and are no longer in their household in Cifra (before, only `cli/cifra_households.php` listed them), and those
  linked to an account with another first name. Their card and the household's page say so (`families::review()`).
- `join::is_student()`: a child of a family, or a student somewhere with nothing that runs courses.

### Security (0.3.35-dev)

- A household the school's data brought (Cifra), while nobody of it has joined, is joined with its children only by
  one of its own adults: by their account, by their number vouched for, or with an account that has the e-mail the
  school's data has for them. A student's code alone gives that student alone, in a family of their own, as before
  0.3.31, and the household goes on waiting for its adults. Before, anyone with the code (the student sees it on «For
  your family») who typed any name joined the household with every brother and sister, got each one's canteen link and
  PIN (and, with the app, a link that makes an account with the parent role), and the household's adults, being quiet,
  were not told.
- A student, signed in, never joins as an adult a family they or a brother or sister are a child of (`claimself`), as
  an invitation already refused. Before, a sister with her brother's code became an adult of her own household.
- The households of Cifra bring as adults only the legal guardians Cifra marks for each student, and all of their
  relatives only for a student for whom it marks none. Before, every relative came (a grandmother, an uncle, a parent
  without custody), with their number vouched for and, when an account of the site had their e-mail, the parent role.
  A child who starts later with only one of their brother's guardians is now a household of their own, with that
  parent in both, also when the other is among their relatives without being their guardian (before, they joined the
  brother's household, where that other adult would see them).
  - Those brought before: every night the round takes out of their household (`api::remove_adult(..., 'import')`) the
    adults it brought who are no longer in it in Cifra, while they are still quiet, and counts them (`adultsleaving`,
    on the settings page and in the task's log). Those who have joined or whom the school welcomed stay:
    `cli/cifra_households.php` lists them as «To look at», with their household's page, where a person at the school
    removes them or not. A household with a row of Cifra that was not understood is left as it is.
  - Why the round and not a clean-up by hand, and why only the quiet ones: a quiet adult never joined and nobody wrote
    to them, so all they have (the parent role, the number vouched for) came from the import alone, and taking them
    out undoes only that; if Cifra was wrong one night, the school marks them as guardians and the next night brings
    them back. One who joined (with the student's code) or was welcomed may be a parent without custody who still has
    the right to know, or one who must not: Cifra does not say which, so a person decides, never the round.
  - On upgrading, before the first night: `php local/oksigeniafamily/cli/cifra_households.php` lists every adult who
    would leave and every one to look at, without changing anything.
- The link sent to an account's address to claim a code (`claim.php?confirm=`) works once. Opening it only shows a
  «Confirm» button (a mail scanner or a preview opens links too); the claim goes by POST with the session's key, and
  the hash of what the link says is kept (`local_oksigeniafamily_spent`, `claimmail::spend()`) until it would have
  expired anyway. Before, every visit within its two days claimed again and gave again the canteen's link and PIN and,
  in the app's plugin, a new key to sign in. The claim page is never cached (`Cache-Control: no-store`) and sends no
  `Referer` (`Referrer-Policy: no-referrer`).
- A number nobody vouches for (typed on the claim page, or carried by that link) never takes the place of an adult's
  number that someone vouched for; it still fills in an adult who had none.

### Fixed (0.3.35-dev)

- `api::children_of_adult()` gives each child's `firstname` and `lastname` as the school's data gave them to a child
  without an account: the page after joining named those children with them, and got nothing.

### Added (0.3.34-dev)

- `api::adults_by_phone_hint($phone)`: every active adult with that number in their record, vouched for or not, as a
  hint for the staff who answer a chat (who may be writing), never who is writing nor a reason to show them anything, as
  `adults_by_email()`. Each record says whether the number is vouched for (`phoneverified`) and whether the adult is
  still quiet; the vouched for come first. The number in E.164, as the records keep it; spaces and a leading 00 are
  taken.
- The children of `households()`, `each_household()` and `household()` carry `stage`: a guess from the group's name
  (`PRI 5º B` gives `PRI`), or empty, to group or to say, never to decide.

### Added (0.3.33-dev)

- `api::household($familyid, $withadults = false, $activeonly = true)`: one household, as `households()` gives each,
  for whoever starts from one family (a chat that found the adult who writes, by phone or e-mail, and shows their
  children with their group) without walking the whole roll. `each_household()` and it share the same loading.

### Fixed (0.3.32-dev)

- After joining with a child's code, the page names every child of the family they joined, brothers and sisters too
  ("You are now family of Ana and Leo"), and gives each one's links under their name (a canteen's link and PIN for
  each child); the app's, which is the adult's own, comes once and first (`links::for_children()`). Before, it named
  and gave the links of the child of the code alone.

### Added (0.3.31-dev)

- Quiet adults: those the households of Cifra bring stay out of reach of every other plugin until they join or the
  school welcomes them (`api::wake_adult()`), so nobody writes to a family before the school does (a library's
  postcard, a canteen's notice). `adults_of_child()`, `adults_of_children()` and `adult_users_of_family()` leave them
  out; `each_household()` says who is quiet; their own account still sees their children. A file imported by hand
  brings its adults reachable, as before.
- Whoever has a child's code finds the household the school's data brought, while nobody of it has joined: they join it
  with every child in it, brothers and sisters too, instead of a family of their own; as one of its adults when they
  come signed in with that adult's account or e-mail, which wakes them.

### Added (0.3.30-dev)

- The households of the school's management program every night, when it is Cifra (`local\import\cifra_households`,
  through the same importer as a CSV file): brothers and sisters together, and a child who starts years later joins
  the household their family already has, on their own. Off by default (`cifrahouseholds`), with how the last round
  went on the settings page; `cli/cifra_households.php` lists first every household a round would make or change and
  every child who would leave, and `--apply` does it.
  - A household is the students with the same legal guardians, with every adult Cifra gives for any of them; a parent
    with a child of each partner is in two. Brothers and sisters that Cifra lists among the relatives are children,
    never adults (`cifra_people::families()`, which now also says whom each adult is a guardian of).
  - Where it goes, so it is never made twice: the open family that already has its children, by their key or their
    account (a family from a letter's code or made by hand takes it and keeps its own source); then the household made
    for the same guardians; then one whose adults are all among the child's and that has all their guardians, by their
    id in Cifra, then their e-mail, then their mobile. `importer::plan()` takes, for that, the families a source's
    households are already in.
  - Accounts by e-mail, only when one active account has it, and an adult's never a student's. Without one, the child
    goes by their key and gets their name and group (`set_child_details()`); nothing is created and nothing is sent.
  - The list is complete: a child without an account who is no longer in it leaves, with the importer's brake. Cifra is
    read whole or not at all, one record at a time with a pause, and the round waits for any import going in.
  - The connection is the shared reader of Cifra (`local\cifra`, from moodle-tools), set once for every Oksigenia plugin
    of the site; this plugin has its own settings for a site without another one.

### Fixed (0.3.29-dev)

- Seen on the phone: in the app, «Invite another adult» showed the QR white on black in dark mode and «Your codeABCD»
  joined up. A page opened from Oksigenia Aty's home does not get this plugin's sheet, and the app drops the spaces
  between tags: what the QR needs (black on a white tile) and the lines of the code and the address are now on the
  template itself.

### Changed (0.3.28-dev)

- One «For your family»: for a student who eats at a canteen, Oksigenia Meals' way without installing anything goes
  below the app's (`familyhelp::section()`), and Meals' own page leads here. With `?id=`, the office (whoever prints the
  letters) sees a student's, to show it to their family. `api::family_page_url()` and `api::family_page_short()` give
  its address, long and short.

### Added (0.3.27-dev)

- «Invite another adult»: an adult of a family shows a QR to another adult of the household (a partner without an account
  of the site, a grandparent), who joins that same family with all its children, instead of starting a family of their
  own with one child's code. `api::invite_for_family()`; in the app (`mobile_invite_view`, which Oksigenia Aty opens from
  its home) and on the site (`invite.php`). The family's adults are told when somebody joins.

### Changed (0.3.26-dev)

- The short address asks the shortener for a word only where the centre chose one (setting «Word of the short
  address»): a test site that asked first kept «familia» for itself.

### Added (0.3.25-dev)

- «For your family» for every student, not only those who eat at the canteen: a page with their QR, code, short
  address and steps, for their family to join from the student's screen. In the top bar («Family»), on their profile,
  in the app's menu, and in Oksigenia Aty as a card on the home until somebody of the family joins. Students are those
  with a student role and none that runs courses, or children of a family; a setting turns it off.

### Added (0.3.24-dev)

- `api::join_for_student()`: what a student's family needs to join, for a page of the student's own («For your family»
  in Meals, also shown by the office): a code for these pages, kept and shown again until it is near its end (sealed
  with the site's key; a letter's code is never touched), its QR without a size or colour of its own, the short address,
  how many of the family have joined and use the app, and the steps on an iPhone and on Android (`letters::join_steps()`).

### Fixed (0.3.24-dev)

- The letters and the steps name the app only where Oksigenia Aty lets families in (the same test as the claim page's
  «Open in the app»): with the setting on but the official app's scheme, a letter sent families to an app that would not
  let them in.

### Changed (0.3.23-dev)

- From the pilot with families in a school:
  - A code claimed with the address of an account of the site (a teacher who is also a parent) no longer makes a second
    account with the same address: a link goes to that address, and opening it puts the child into that account. Plugins
    learn that the account is proven theirs (a third argument to `_oksigeniafamily_links`), and Oksigenia Aty then leads
    into the app with it.
  - Where families get into the app, the letter's first step is installing it: people tapped «Open in the app» before
    they had it.
  - The letter prints the claim page's address short where the site has a link shortener (nur.click/familia, with the
    common `shortener` piece), and without «https://».

### Changed (0.3.22-dev)

- A letter names the student with first name and surnames, whatever order the site uses for its lists: the school's
  «lastname firstname» put «Rodríguez Pérez Carolina» at the top of the letter (seen by Nuryana).

### Changed (0.3.21-dev)

- The letters with the codes say, at their foot, that everything is also in the Oksigenia Aty app, with its icon and
  where to find it (the App Store and Google Play, in words), when the site lets families into the app
  (`local_oksigeniaaty` with «Families get into the app» on). Asked by Enrique to make the app known to families.

### Changed (0.3.20-dev)

- «Familias» says what each family is missing, and filters by it (design by Jujol). On top, a tile for each need with
  how many active families have it and what it means: consents not answered (with the consents on), nobody with an
  account, a code not used, no confirmed phone; and, good to know, students without an account and a «no» to their
  image. A touch shows only those families, with the search; another one shows them all. Each family is a card with its
  adults, its students with their group and what it is missing in words, naming whom it concerns («Code not used: Leo
  Ruiz»), or «Up to date». Everything is decided in SQL on indexed columns (`families::need_condition()`, a COUNT per
  tile in `need_counts()`, `flags()` for the 25 cards of a page), and the pages say «26–50 of 87» without Moodle's bar.

### Changed (0.3.19-dev)

- A file of more than 2,000 rows is looked at in the background, as a big one is already applied: reading what 40,000
  children would change took some 35 seconds, more than a page holds. The import page says it is preparing the preview
  and comes back to it when the task has it; nothing changes until it is applied. The preview, whether worked out in the
  page or in the background, comes from the new `local\import\preview`.

### Added

- `api::adult_users_of_family()`: the accounts of the active adults of an open family, so another plugin can tell them
  something through Moodle's messages (Eguahe's notices about a visit or the next school year).

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

- «Ask for consent» is a letter (0.3.17-dev, Jujol's design), not a Moodle form. Who it is for is a row of chips (the
  whole course or one of its groups), so changing it reloads nothing and loses neither the text nor the file; the
  title is large, the text is a plain text area (kept as plain text: its paragraphs stay, nothing of it is taken as
  HTML), and the circular is clipped to it with an ordinary file field. Under the sheet go its terms as chips: until
  when (in a week, in two, or another day), who answers, and, folded in a sentence, how long the answers are kept
  (three months, six, a year or two; never below the 30 days of the minimum). The send stays at the foot of the screen
  and says how many students' families it goes to. On the site, the page asks first «Whose families?», with the
  courses under way and the cohorts as chips and a search, only those the person can ask (`consent_school`). The
  server checks everything again: the capability in the course or the cohort chosen, the session key, the deadline at
  the end of the day where whoever asks is, the minimum to keep the answers, and the circular (a document or an image,
  under a clean name, within the size of uploads of the site and past Moodle's antivirus, if the site has one). A
  circular taken on a try that had to be fixed waits in the person's drafts for the next one.
- The answers of a request open on who is missing while it is open: the counts are the tabs (Not answered, Agree,
  Do not agree, All), the reminder goes over that list, and the students with nobody in the family records come in an
  alert, each with «Add their family» for whoever runs the records. Each student is a row with what each adult
  answered, and while it is open, the paper answer is a gesture of the row: who signs it among the adults of the
  record (nobody chosen when there are two) and «Agrees» or «Does not agree», which save at once
  (`consent_record.php`, by POST with the session key, kept as the school's answer). Someone not in the records, or a
  comment, still goes through the page of the paper answer.
- The page title no longer shows twice on the site: only inside a course, under its name.
- A big import goes in in the background, by batches (0.3.16-dev). «Apply» on a file of more than 200 rows queues a
  task that goes through the families 500 at a time, each batch in its own transaction, and then the children who
  leave; the import page shows how far it got and what it did, and whoever applied it gets a notification of Moodle
  (`import`) when it ends. Before, the web request did all of it at once: with 40,000 students, 100–140 s, past the
  60 s of a common proxy. A small file still goes in at once, as before.
- In a big import, the brake of «This file is the complete list» is checked before queueing, against what the preview
  said, and the task looks again before applying anything: if the children leaving are no longer the number typed, it
  stops. The file waits in the plugin's own area, not in the person's drafts, which Moodle empties after a few days, and
  it goes once it has gone in; the daily round erases the previews nobody applied. A batch that went in stays if a later
  one fails, and the task, run again by Moodle, goes on from there without duplicating anything. Two imports never go in
  at the same time: the second waits its turn.
- A request for consent, or its reminder, goes by pieces of 200 adults (0.3.16-dev), each piece a task of its own, and
  each adult it reaches gets a mark for that sending (`local_oksigeniafamily_sent`): a piece that fails and runs again
  reaches nobody twice, and a second reminder is another sending. Before, one task sent it to everybody and kept no
  record, so a retry after a failure halfway sent it all again. The students and the adults' accounts are read in a
  few queries, not one each.
- A request for consent works out how every student stands with a few queries, not one per student: a request to a
  whole school (3,000 students) was 3,002 queries for each row of the list.
- The suite of the CI runs with a read-only token.

### Fixed

- «Call off the request» opens its question again: it was a form by GET, which drops the request from the address, so
  the page could not find it (0.3.17-dev).

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
