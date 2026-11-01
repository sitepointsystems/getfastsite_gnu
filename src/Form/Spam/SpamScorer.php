<?php

declare(strict_types=1);

namespace Fastsite\Form\Spam;

use Fastsite\Form\Dto\Form;
use Fastsite\Form\Dto\FormField;

/**
 * Scores a submission on what it CONTAINS, not on how it was delivered.
 *
 * This is the half of spam defence that a challenge widget structurally cannot
 * do. Turnstile asks "is there a browser and a human-ish signal at the other
 * end" — and a click farm, a residential-proxy bot driving real Chrome, or an
 * AI agent all answer yes, then paste the same backlink pitch. The content is
 * still spam. Conversely this runs on EVERY path into the endpoint, including
 * the no-JavaScript submit and hand-authored bespoke forms that post directly,
 * neither of which a widget in the generated markup protects at all.
 *
 * It is deliberately pure apart from one injected MX lookup, so the whole
 * thing is testable without a network, a database or a request.
 *
 * ON CALIBRATION. The threshold is 5 and the weights are chosen so that no
 * single soft signal can flag a submission on its own — a lone link, a
 * throwaway address, an unexpected alphabet are all suspicious and none of
 * them is proof. Only the two signals with no legitimate explanation reach the
 * threshold alone: markup-wrapped links (nobody types `[url=` by accident) and
 * an email domain that cannot receive mail at all, where the lead is unusable
 * whatever it is.
 *
 * WHAT IS DELIBERATELY ABSENT is a keyword list. Every "contact form spam
 * phrases" list is built from marketing vocabulary — "improve your ranking",
 * "backlinks", "search engine optimisation" — and the first site this shipped
 * for collects partner applications FROM WEB AGENCIES, whose genuine enquiries
 * are made of exactly those words. A generic list would have flagged the real
 * leads and passed the spam. Sites that want phrase matching get
 * `blockedPhrases`, which they write themselves after reading their own spam.
 */
final class SpamScorer
{
    /** At or above this, a submission is held rather than notified. */
    public const THRESHOLD = 5;

    /**
     * The first link is suspicious; it is the second that decides — UNLESS the
     * form offers a dedicated `url` field, in which case the first one decides
     * on its own.
     *
     * That distinction is not a fudge to catch one more sample, it is the
     * form's own shape carrying information: when there is a "Your website"
     * box and the sender put their link in the free-text message instead, they
     * bypassed the place built for it. On a form with nowhere else to put a
     * URL, one link stays exactly what it was — suspicious, not decisive.
     *
     * Calibrated against real traffic. The engine's first production form was
     * missing genuine spam by exactly one point: a single bare link, Latin
     * script, no markup, scored 4 against a threshold of 5 and was delivered.
     */
    private const LINK_FIRST = 4;
    private const LINK_FIRST_WITH_URL_FIELD = 5;
    private const LINK_EACH = 3;
    private const LINK_CAP = 10;

    private const MARKUP_LINK = 5;
    private const NO_MX = 5;
    private const DISPOSABLE = 3;
    private const SCRIPT_MISMATCH = 2;
    private const BLOCKED_PHRASE = 5;

    /**
     * A short, honest list rather than a pretence at completeness: these are
     * the throwaway providers that show up in practice. It contributes 3, so
     * it never flags alone — plenty of real people hand a burner address to a
     * form before they trust you, and they still deserve a reply.
     */
    private const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', '10minutemail.com',
        'tempmail.com', 'temp-mail.org', 'throwawaymail.com', 'yopmail.com',
        'sharklasers.com', 'trashmail.com', 'getnada.com', 'dispostable.com',
        'maildrop.cc', 'fakeinbox.com', 'mailnesia.com', 'spam4.me',
        'mohmal.com', 'emailondeck.com', 'tempinbox.com', 'burnermail.io',
    ];

    public function __construct(
        private readonly MxResolver $mx,
    ) {
    }

    /**
     * @param array<string, string> $values validated values, keyed by field name
     * @param list<string> $blockedPhrases owner-supplied, matched case-insensitively
     */
    public function score(Form $form, array $values, string $locale = 'en', array $blockedPhrases = []): SpamVerdict
    {
        $score = 0;
        $reasons = [];

        $freeText = [];
        $emails = [];
        $hasUrlField = false;
        foreach ($form->fields as $field) {
            if ($field->type === 'url') {
                $hasUrlField = true;
            }
            $value = trim($values[$field->name] ?? '');
            if ($value === '') {
                continue;
            }
            if ($field->type === 'email') {
                $emails[] = $value;
                continue;
            }
            // A `url` field is SUPPOSED to hold a URL — scoring it would flag
            // every correctly filled partner application on the first field.
            if (\in_array($field->type, ['text', 'textarea'], true)) {
                $freeText[$field->name] = $value;
            }
        }

        $blob = implode("\n", $freeText);

        // 1. Links where prose belongs. The strongest available signal, and
        //    the one a backlink spammer cannot drop, because the link IS the
        //    payload — remove it and there was no reason to send the message.
        $links = $this->countLinks($blob);
        if ($links > 0) {
            $first = $hasUrlField ? self::LINK_FIRST_WITH_URL_FIELD : self::LINK_FIRST;
            $points = min(self::LINK_CAP, $first + (($links - 1) * self::LINK_EACH));
            $score += $points;
            $reasons[] = ($links === 1 ? '1 link in a message field' : $links . ' links in message fields')
                . ($hasUrlField ? ' (this form has its own website field)' : '');
        }

        // 2. Markup-wrapped links. `<a href`, `[url=`, `[link=` — a human
        //    typing into a plain textarea never produces these.
        if (preg_match('#<a\s[^>]*href|\[url[\s=\]]|\[link[\s=\]]#i', $blob) === 1) {
            $score += self::MARKUP_LINK;
            $reasons[] = 'HTML or BBCode link markup in a message field';
        }

        // 3. Owner-supplied phrases: the escape hatch that replaces a generic
        //    keyword list, written by someone who has read their own spam.
        foreach ($blockedPhrases as $phrase) {
            $phrase = trim($phrase);
            if ($phrase !== '' && stripos($blob, $phrase) !== false) {
                $score += self::BLOCKED_PHRASE;
                $reasons[] = 'Contains a blocked phrase: "' . $phrase . '"';
                break;
            }
        }

        // 4. The address itself.
        foreach ($emails as $email) {
            $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
            if ($domain === '') {
                continue;
            }
            if (\in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
                $score += self::DISPOSABLE;
                $reasons[] = 'Disposable email domain (' . $domain . ')';
                continue;
            }
            if (!$this->mx->hasMx($domain)) {
                $score += self::NO_MX;
                $reasons[] = 'Email domain cannot receive mail (' . $domain . ')';
            }
        }

        // 5. A script the form's own languages do not use. Weak on purpose
        //    and worth stating why: a real enquiry in an unexpected alphabet
        //    is a person who deserves an answer, so this can never flag a
        //    submission on its own — it only ever tips one that already looks
        //    wrong for another reason.
        if ($blob !== '' && $this->scriptMismatch($blob, $locale)) {
            $score += self::SCRIPT_MISMATCH;
            $reasons[] = 'Written in a script this form\'s languages do not use';
        }

        return new SpamVerdict($score, $reasons, $score >= self::THRESHOLD);
    }

    /**
     * Explicit links only — a scheme or a `www.` label. Bare "example.com"
     * detection is deliberately NOT here: it cannot tell a domain from
     * "socket.io", "Nuxt.js" or "node.green" in a sentence about tooling,
     * which is the exact vocabulary this form's honest senders use.
     */
    private function countLinks(string $text): int
    {
        // preg_match_all returns false on a catastrophic backtrack. Counting
        // that as "no links" is the right failure: the guard falls silent
        // rather than flagging something it did not actually read.
        $n = preg_match_all('#\bhttps?://\S+|\bwww\.[a-z0-9-]+\.[a-z]{2,}#i', $text);

        return $n === false ? 0 : $n;
    }

    /**
     * True when the text is substantially in a script none of the site's own
     * locales are written in. Latin-script locales are the only ones this
     * judges, because they are the only ones where "not this script" is a
     * meaningful statement rather than a guess.
     */
    private function scriptMismatch(string $text, string $locale): bool
    {
        $lang = strtolower(substr($locale, 0, 2));
        // Locales whose own script is not Latin can never trip this.
        if (\in_array($lang, ['ru', 'uk', 'bg', 'sr', 'el', 'zh', 'ja', 'ko', 'ar', 'he', 'hi', 'th', 'fa'], true)) {
            return false;
        }

        $foreign = preg_match_all('/[\x{0400}-\x{04FF}\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{0600}-\x{06FF}]/u', $text);
        if ($foreign === false || $foreign === 0) {
            return false;
        }

        $letters = preg_match_all('/\p{L}/u', $text);

        // A stray character is a quotation or a name. A third of the letters
        // is a message written in another script.
        return $letters > 0 && ($foreign / $letters) > 0.33;
    }
}
