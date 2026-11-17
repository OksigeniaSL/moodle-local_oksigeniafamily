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

namespace local_oksigeniafamily\local;

/**
 * What each plugin gives a family for a child once the family has joined: Meals its family link and PIN, a library its
 * page, and so on. Plugins say it with a function `<component>_oksigeniafamily_links(\stdClass $adult, int $childuserid)`
 * in their lib.php that returns a list of ['label' => string, 'url' => string, 'pin' => string (optional), 'note' =>
 * string (optional, free text)], one or several per child (one per canteen, for instance). It is called only when the
 * family claims the code, since that is the only time a PIN is shown. A function from Moodle 4.3 on, rather than a hook,
 * so plugins that support older versions can give it too. A third argument, `bool $proven`, says whether whoever claimed
 * has shown the adult's account of the site is theirs; a function with two arguments simply does not get it.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class links {
    /**
     * Every plugin's links for an adult and a child, checked.
     *
     * @param \stdClass $adult The adult record.
     * @param int $childuserid The child's account.
     * @param bool $proven Whether whoever claimed has shown the adult's account is theirs (signed in to it, or come through
     *     the link sent to its address): a plugin may then lead them into it, as Oksigenia Aty does into the app.
     * @return array A list of ['component', 'label', 'url', 'pin', 'note'].
     */
    public static function for_child(\stdClass $adult, int $childuserid, bool $proven = false): array {
        $links = [];
        foreach (get_plugins_with_function('oksigeniafamily_links') as $type => $plugins) {
            foreach ($plugins as $name => $function) {
                // Called once the code is claimed: a plugin that fails must not show the adult an error after they joined.
                try {
                    $given = self::clean($function($adult, $childuserid, $proven));
                } catch (\Throwable $e) {
                    debugging('Family links of ' . $type . '_' . $name . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                    continue;
                }
                foreach ($given as $link) {
                    $links[] = ['component' => $type . '_' . $name] + $link;
                }
            }
        }

        return $links;
    }

    /**
     * Every plugin's links for an adult and all the children of their family, each link once.
     *
     * @param \stdClass $adult The adult record.
     * @param int[] $childuserids The children's accounts, in the order to show them.
     * @param bool $proven As for for_child().
     * @return array As group() returns.
     */
    public static function for_children(\stdClass $adult, array $childuserids, bool $proven = false): array {
        $each = [];
        foreach ($childuserids as $childuserid) {
            $each[(int) $childuserid] = self::for_child($adult, (int) $childuserid, $proven);
        }

        return self::group($each);
    }

    /**
     * The links of several children, each once: one that every child gets alike and without a PIN (the app, which is the
     * adult's own) goes once, first; the rest (a canteen's link and PIN) stay with the child they are for.
     *
     * @param array $each Child's account => their links, as for_child() returns them.
     * @return array [the links every child shares, [child's account => their own links]]
     */
    public static function group(array $each): array {
        $key = fn(array $link) => $link['pin'] === '' ? $link['component'] . "\n" . $link['label'] . "\n" . $link['note'] : '';
        $shared = [];
        foreach (reset($each) ?: [] as $link) {
            $mine = $key($link);
            if ($mine === '' || isset($shared[$mine])) {
                continue;
            }
            foreach ($each as $links) {
                if (!in_array($mine, array_map($key, $links), true)) {
                    continue 2;
                }
            }
            $shared[$mine] = $link;
        }
        $own = [];
        foreach ($each as $childuserid => $links) {
            $own[$childuserid] = array_values(array_filter($links, fn($link) => !isset($shared[$key($link)])));
        }

        return [array_values($shared), $own];
    }

    /**
     * What a plugin returned, checked: only items with a label and a web address.
     *
     * @param mixed $returned What the plugin returned.
     * @return array A list of ['label', 'url', 'pin', 'note'].
     */
    public static function clean($returned): array {
        $clean = [];
        foreach (is_array($returned) ? $returned : [] as $link) {
            $url = is_array($link) ? clean_param((string) ($link['url'] ?? ''), PARAM_URL) : '';
            if ($url === '' || empty($link['label'])) {
                continue;
            }
            $clean[] = [
                'label' => (string) $link['label'],
                'url' => $url,
                'pin' => (string) ($link['pin'] ?? ''),
                'note' => (string) ($link['note'] ?? ''),
            ];
        }

        return $clean;
    }
}
