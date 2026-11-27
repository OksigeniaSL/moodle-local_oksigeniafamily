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

use local_oksigeniafamily\api;

/**
 * The mails in the common look of the organization, by the way of Oksigenia Marandu when it is there.
 *
 * @package    local_oksigeniafamily
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniafamily\local\commonmail
 * @covers     \local_oksigeniafamily\local\claimmail
 * @covers     \local_oksigeniafamily\local\consent_notices
 */
final class commonmail_test extends \advanced_testcase {
    /**
     * What the pages escaped is plain again, and a text becomes a paragraph for each block of lines.
     */
    public function test_plain_and_paragraphs(): void {
        $this->assertSame('Sol & Mar «Leo»', commonmail::plain('Sol &amp; Mar &laquo;Leo&raquo;'));
        $this->assertSame(
            [['paragraph' => 'Uno.'], ['paragraph' => "Dos,\ncon dos líneas."]],
            commonmail::paragraphs("\n Uno.\n\n  \nDos,\ncon dos líneas.\n")
        );
    }

    /**
     * Skips the test without Oksigenia Marandu.
     */
    private function common_look(): void {
        if (!commonmail::available()) {
            $this->markTestSkipped('Oksigenia Marandu 0.70 or later is not installed.');
        }
        $this->resetAfterTest();
    }

    /**
     * The link that puts a child into an account: in the common look, with a text version, the link in both, and a
     * name the pages escape shown once escaped, not twice.
     */
    public function test_claim_in_the_common_look(): void {
        $this->common_look();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Pilar']);
        $child = $this->getDataGenerator()->create_user(['firstname' => 'Leo & Eva']);
        $sink = $this->redirectEmails();

        $this->assertTrue(claimmail::send('ABC-123', $user, (int) $child->id));

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Auto-Submitted: auto-generated', $messages[0]->header);
        $raw = quoted_printable_decode($messages[0]->body);
        $this->assertSame(2, substr_count($raw, 'local/oksigeniafamily/claim.php?confirm='));
        $this->assertStringContainsString('Leo &amp; Eva', $raw);
        $this->assertStringNotContainsString('Leo &amp;amp; Eva', $raw);
        $this->assertStringContainsString('Pilar', $raw);
    }

    /**
     * A request for consent: the adult with only an address gets the mail, and the adult with an account the notice,
     * whose mail goes in the same look; both with the school's own words and the way to answer.
     */
    public function test_consent_in_the_common_look(): void {
        $this->common_look();
        $generator = $this->getDataGenerator();
        $ana = $generator->create_user(['firstname' => 'Ana']);
        $parent = $generator->create_user(['firstname' => 'Juan', 'lastname' => 'Ruiz']);
        $familyid = api::create_family();
        api::add_adult($familyid, ['firstname' => 'Juan', 'lastname' => 'Ruiz', 'userid' => $parent->id]);
        api::add_adult($familyid, ['firstname' => 'Pilar', 'lastname' => 'Sanz', 'email' => 'pilar@example.com']);
        api::add_child($familyid, (int) $ana->id);
        $requestid = consent::create(\context_system::instance(), [
            'title' => 'Salida al Teide & La Laguna',
            'message' => '<p>Salimos a las 8:30.</p><p>Volvemos a las 14:00.</p>',
            'deadline' => time() + 3 * DAYSECS,
            'needall' => false,
        ], [(int) $ana->id], (int) $generator->create_user()->id);
        $sink = $this->redirectEmails();

        $sent = consent_notices::send($requestid);

        $this->assertSame(1, $sent['email']);
        $mails = [];
        foreach ($sink->get_messages() as $message) {
            $mails[$message->to] = $message;
        }
        $this->assertArrayHasKey('pilar@example.com', $mails);
        $this->assertArrayHasKey($parent->email, $mails);
        foreach ([$mails['pilar@example.com'], $mails[$parent->email]] as $mail) {
            $this->assertStringContainsString('Auto-Submitted: auto-generated', $mail->header);
            $raw = quoted_printable_decode($mail->body);
            $this->assertStringContainsString('Salida al Teide &amp; La Laguna', $raw);
            $this->assertStringNotContainsString('Teide &amp;amp; La Laguna', $raw);
            $this->assertStringContainsString('Volvemos a las 14:00.', $raw);
        }
        $this->assertStringContainsString('answer.php', quoted_printable_decode($mails['pilar@example.com']->body));
        $this->assertStringContainsString('Pilar', quoted_printable_decode($mails['pilar@example.com']->body));
        $this->assertStringContainsString('Juan', quoted_printable_decode($mails[$parent->email]->body));
    }
}
