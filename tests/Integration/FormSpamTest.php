<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Form\Dto\Form;
use Fastsite\Form\Handler\FormSubmitHandler;
use Fastsite\Form\Service\FormRenderer;
use Fastsite\Form\Service\FormService;
use Fastsite\Form\Spam\CaptchaResult;
use Fastsite\Form\Spam\CaptchaVerifier;
use Fastsite\Form\Spam\MxResolver;
use Fastsite\Form\Spam\SpamScorer;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The form spam guard, end to end through the REAL submit handler.
 *
 * That last part matters: before this file existed, nothing exercised
 * FormSubmitHandler at all — the suite tested FormService and FormRenderer
 * directly, so the code path a visitor actually travels was uncovered. Every
 * decision the guard makes lives in that handler, so testing anything else
 * would be testing the parts that were never in doubt.
 */
final class FormSpamTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private DbalSiteRepository $siteRepo;
    private FormService $forms;
    private TokenCipher $cipher;
    private \Predis\Client $redis;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);

        // The real queue and throttle, not fakes: both are `final` by house
        // convention, and asserting against the jobs table is a better test
        // than asserting against a spy — it is what a worker will really read.
        // Database 15, like every other queue test here; never the app's 0.
        $this->redis = new \Predis\Client('tcp://127.0.0.1:6379/15');
        try {
            $this->redis->ping();
            $this->redis->flushdb();
        } catch (\Throwable) {
            self::markTestSkipped('No Redis on 127.0.0.1:6379 — form spam tests skipped.');
        }

        $this->dataPath = sys_get_temp_dir() . '/fastsite-spam-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $this->cipher = new TokenCipher(str_repeat('k', 32));
        // The cipher is the LAST, optional constructor arg and it has to be
        // passed here: without it SiteService silently drops turnstile_secret
        // (and every other encrypted field), turnstileConfigured() stays
        // false, and the challenge tests pass for the wrong reason. Same trap
        // php-di sets in production, met in a test fixture.
        $this->sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot, $this->cipher);
        $this->forms = new FormService($this->databases, $clock);
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (!is_dir($this->dataPath)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dataPath);
    }

    // ---------------------------------------------------------------- scorer

    /**
     * The signal the whole thing rests on. A backlink spammer cannot drop the
     * link, because the link IS the payload — remove it and there was no
     * reason to send the message.
     */
    public function testTwoLinksInAMessageFieldAreHeld(): void
    {
        $verdict = $this->scorer()->score($this->form(), [
            'name' => 'Alex',
            'email' => 'alex@example.com',
            'message' => 'Great site! See https://cheap-seo.example/offer and http://links.example/b for details.',
        ]);

        self::assertTrue($verdict->isSpam);
        self::assertGreaterThanOrEqual(SpamScorer::THRESHOLD, $verdict->score);
        self::assertStringContainsString('links', implode(' ', $verdict->reasons));
    }

    /**
     * ONE link is suspicious and must not be decisive on its own. A real
     * enquiry saying "here's our site, https://acme.example" is exactly the
     * lead this feature exists to protect, and flagging it would make the
     * guard worse than the spam.
     */
    public function testASingleLinkIsNotEnoughToHold(): void
    {
        $verdict = $this->scorer()->score($this->form(), [
            'name' => 'Alex',
            'email' => 'alex@example.com',
            'message' => 'We are an agency, our site is https://acme.example — can we partner?',
        ]);

        self::assertFalse($verdict->isSpam, 'one link alone must never be decisive');
        self::assertGreaterThan(0, $verdict->score, 'but it should still register');
    }

    /**
     * When the form OFFERS a website field, one link in the free-text message
     * is enough on its own — the sender had the right box and used the wrong
     * one.
     *
     * Calibrated against real traffic, not taste. The engine's first
     * production form was letting genuine spam through by exactly one point:
     * a single bare link, Latin script, no markup, scored 4 against a
     * threshold of 5 and was delivered to the customer's sales inbox.
     */
    public function testOneLinkDecidesWhenTheFormHasItsOwnWebsiteField(): void
    {
        $form = $this->form([
            ['name' => 'website', 'type' => 'url', 'label' => 'Your website'],
            ['name' => 'email', 'type' => 'email', 'label' => 'Email'],
            ['name' => 'message', 'type' => 'textarea', 'label' => 'Message'],
        ]);

        $verdict = $this->scorer()->score($form, [
            'website' => 'https://theirsite.example',
            'email' => 'someone@example.com',
            'message' => 'Free videos here https://spam.example/?ref=1 lots of them',
        ]);

        self::assertTrue($verdict->isSpam, 'a link in the message when a website field exists is decisive');
        self::assertStringContainsString('own website field', implode(' ', $verdict->reasons));
    }

    /**
     * The other half, and the reason the rule reads off the form rather than
     * being a flat raise: with NOWHERE else to put a URL, one link goes back
     * to being suspicious rather than decisive.
     */
    public function testOneLinkStaysUndecisiveWhenTheFormOffersNoUrlField(): void
    {
        $verdict = $this->scorer()->score($this->form(), [
            'name' => 'Alex',
            'email' => 'alex@example.com',
            'message' => 'We are an agency, our site is https://acme.example — can we partner?',
        ]);

        self::assertFalse($verdict->isSpam);
    }

    /**
     * A `url` field is SUPPOSED to hold a URL. Scoring it would flag every
     * correctly filled partner application on its first field.
     */
    public function testAUrlFieldIsNeverScoredAsALink(): void
    {
        $form = $this->form([
            ['name' => 'website', 'type' => 'url', 'label' => 'Your website'],
            ['name' => 'message', 'type' => 'textarea', 'label' => 'Message'],
        ]);

        $verdict = $this->scorer()->score($form, [
            'website' => 'https://acme.example',
            'message' => 'We would like to partner with you.',
        ]);

        self::assertSame(0, $verdict->score);
        self::assertFalse($verdict->isSpam);
    }

    /** Nobody types `[url=` into a plain textarea by accident. */
    public function testBbcodeMarkupAloneIsEnoughToHold(): void
    {
        $verdict = $this->scorer()->score($this->form(), [
            'name' => 'Alex',
            'email' => 'alex@example.com',
            'message' => 'Nice work [url=spam]click[/url]',
        ]);

        self::assertTrue($verdict->isSpam);
    }

    /**
     * An address whose domain cannot receive mail is an unusable lead
     * whatever else it is, so this one is decisive alone.
     */
    public function testAnUndeliverableSenderDomainIsHeld(): void
    {
        $verdict = $this->scorer(mxFor: [])->score($this->form(), [
            'name' => 'Alex',
            'email' => 'alex@nowhere.invalid',
            'message' => 'Hello, I would like to talk about your services.',
        ]);

        self::assertTrue($verdict->isSpam);
        self::assertStringContainsString('cannot receive mail', implode(' ', $verdict->reasons));
    }

    /**
     * The calibration guard. This is the message a REAL partner application
     * looks like, and the scorer must be silent on it — a spam filter that
     * flags honest leads is worse than the spam it removes.
     */
    public function testAnOrdinaryEnquiryScoresZero(): void
    {
        $verdict = $this->scorer()->score($this->form(), [
            'name' => 'Marie Sørensen',
            'email' => 'marie@bureau.example',
            'message' => 'We run about 40 client sites on WordPress and Umbraco and are looking '
                . 'for a consent platform we can resell. What does your partner tier look like?',
        ]);

        self::assertSame(0, $verdict->score, implode(' · ', $verdict->reasons));
        self::assertFalse($verdict->isSpam);
    }

    /**
     * The marketing vocabulary that every generic "spam phrases" list is built
     * from is the NATIVE vocabulary of this form's honest senders. A keyword
     * list would flag the real leads and pass the spam, which is why there
     * isn't one.
     */
    public function testAgencyVocabularyIsNotTreatedAsSpam(): void
    {
        $verdict = $this->scorer()->score($this->form(), [
            'name' => 'Sam',
            'email' => 'sam@agency.example',
            'message' => 'We do search engine optimisation and backlink audits for clients and '
                . 'want to improve their rankings while staying compliant. Can we partner?',
        ]);

        self::assertFalse($verdict->isSpam, 'SEO words are this audience\'s job description');
    }

    /** An owner-written phrase is the escape hatch that replaces the list. */
    public function testAnOwnerSuppliedBlockedPhraseHolds(): void
    {
        $verdict = $this->scorer()->score(
            $this->form(),
            ['name' => 'X', 'email' => 'x@example.com', 'message' => 'Buy cheap watches today'],
            'en',
            ['cheap watches'],
        );

        self::assertTrue($verdict->isSpam);
    }

    /**
     * Alphabet is a WEAK contribution and must never decide alone — someone
     * writing in another script is a person who deserves an answer.
     */
    public function testAnUnexpectedScriptAloneDoesNotHold(): void
    {
        $verdict = $this->scorer()->score($this->form(), [
            'name' => 'Ivan',
            'email' => 'ivan@example.com',
            'message' => 'Здравствуйте, мы хотели бы обсудить сотрудничество с вашей компанией.',
        ]);

        self::assertFalse($verdict->isSpam, 'script mismatch must only ever tip an already-suspect submission');
        self::assertGreaterThan(0, $verdict->score);
    }

    // ------------------------------------------------------- the submit path

    /** A held submission is STORED, never emailed, and the visitor sees success. */
    public function testAHeldSubmissionIsStoredButNeverQueuedForEmail(): void
    {
        $site = $this->seed();
        $response = $this->submit($site, [
            'name' => 'Spammer',
            'email' => 'x@example.com',
            'message' => 'https://a.example/1 and https://b.example/2',
            '_fs_consent' => '1',
        ]);

        self::assertSame(200, $response->getStatusCode(), 'the visitor is answered exactly like a success');
        self::assertSame(0, $this->queuedEmails(), 'nothing may be queued for a held submission');

        $held = $this->forms->submissions($site->handle, null, 50, 0, 'spam');
        self::assertCount(1, $held);
        self::assertSame('spam', $held[0]->status);
        self::assertGreaterThanOrEqual(SpamScorer::THRESHOLD, $held[0]->spamScore);
        self::assertNotSame([], $held[0]->spamReasons, 'the reasons must be stored, not just the score');
    }

    /** A clean submission is untouched by any of this. */
    public function testACleanSubmissionIsQueuedAsBefore(): void
    {
        $site = $this->seed();
        $this->submit($site, [
            'name' => 'Marie',
            'email' => 'marie@bureau.example',
            'message' => 'We would like to discuss a partnership for our client sites.',
            '_fs_consent' => '1',
        ]);

        self::assertSame(1, $this->queuedEmails());
        self::assertSame([], $this->forms->submissions($site->handle, null, 50, 0, 'spam'));
    }

    /**
     * Log mode is the reason `spam_mode` is a string and not a bool: it has to
     * record a verdict AND deliver, so a site can see a week of what would
     * have been held before anything is.
     */
    public function testLogModeScoresTheSubmissionButStillSendsIt(): void
    {
        $site = $this->seed(['spam_mode' => 'log']);
        $this->submit($site, [
            'name' => 'Spammer',
            'email' => 'x@example.com',
            'message' => 'https://a.example/1 and https://b.example/2',
            '_fs_consent' => '1',
        ]);

        self::assertSame(1, $this->queuedEmails(), 'log mode still delivers');

        $all = $this->forms->submissions($site->handle, null, 50, 0, 'all');
        self::assertCount(1, $all);
        self::assertSame('pending', $all[0]->status);
        self::assertGreaterThanOrEqual(SpamScorer::THRESHOLD, $all[0]->spamScore, 'but the verdict is recorded');
    }

    public function testOffModeScoresNothingAtAll(): void
    {
        $site = $this->seed(['spam_mode' => 'off']);
        $this->submit($site, [
            'name' => 'Spammer',
            'email' => 'x@example.com',
            'message' => 'https://a.example/1 and https://b.example/2',
            '_fs_consent' => '1',
        ]);

        self::assertSame(1, $this->queuedEmails());
        $all = $this->forms->submissions($site->handle, null, 50, 0, 'all');
        self::assertSame(0, $all[0]->spamScore);
    }

    // ------------------------------------------------------------ turnstile

    /**
     * A rejected challenge gets a REAL error page, not the honeypot's fake
     * success — a human whose widget failed has to be told so they can try
     * again, or a genuine enquiry vanishes silently.
     */
    public function testARejectedChallengeIsAnErrorTheVisitorCanActOn(): void
    {
        $site = $this->seed($this->turnstileKeys(), turnstileOnForm: true);
        $response = $this->submit(
            $site,
            ['name' => 'A', 'email' => 'a@example.com', 'message' => 'Hello there.', '_fs_consent' => '1'],
            new FakeCaptcha(CaptchaResult::fail('bad token')),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->queuedEmails());
        self::assertSame([], $this->forms->submissions($site->handle, null, 50, 0, 'all'), 'nothing is stored');
    }

    /** A visitor must never pay for Cloudflare being unreachable. */
    public function testAnUnreachableVerifierLetsTheSubmissionThrough(): void
    {
        $site = $this->seed($this->turnstileKeys(), turnstileOnForm: true);
        $response = $this->submit(
            $site,
            ['name' => 'A', 'email' => 'a@example.com', 'message' => 'Hello there.', '_fs_consent' => '1'],
            new FakeCaptcha(CaptchaResult::unreachable('timeout')),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->queuedEmails(), 'one guard down is not zero guards');
    }

    /**
     * The scorer still runs when Turnstile passes. This is the whole argument
     * for having both: a click farm or an agent driving real Chrome solves the
     * challenge perfectly and then pastes the same backlink pitch.
     */
    public function testAPassedChallengeDoesNotExemptTheContentScorer(): void
    {
        $site = $this->seed($this->turnstileKeys(), turnstileOnForm: true);
        $this->submit(
            $site,
            [
                'name' => 'A',
                'email' => 'a@example.com',
                'message' => 'https://a.example/1 and https://b.example/2',
                '_fs_consent' => '1',
            ],
            new FakeCaptcha(CaptchaResult::pass()),
        );

        self::assertSame(0, $this->queuedEmails());
        self::assertCount(1, $this->forms->submissions($site->handle, null, 50, 0, 'spam'));
    }

    /**
     * A site key with no secret must render NO widget. A form that looks
     * protected and is not gives nobody a reason to look.
     */
    public function testTurnstileRendersNothingWithoutBothHalvesOfTheKeyPair(): void
    {
        $site = $this->seed(['turnstile_site_key' => '0xSITE'], turnstileOnForm: true);
        $form = $this->forms->find($site->handle, 'contact') ?? self::fail('no form');
        $renderer = new FormRenderer($this->forms, 'https://engine.example.com');

        self::assertStringNotContainsString('cf-turnstile', $renderer->renderForm($site, $form, 'en'));
    }

    public function testTurnstileRendersInsideAnAuditExemptEmbedWrapper(): void
    {
        $site = $this->seed($this->turnstileKeys(), turnstileOnForm: true);
        $form = $this->forms->find($site->handle, 'contact') ?? self::fail('no form');
        $html = (new FormRenderer($this->forms, 'https://engine.example.com'))->renderForm($site, $form, 'en');

        self::assertStringContainsString('cf-turnstile', $html);
        self::assertStringContainsString('data-sitekey="0xSITE"', $html);
        // PageAudit depth-strips data-fs-embed regions; without the wrapper the
        // third-party script would FAIL the build's zero-JS invariant and block
        // the publish outright, exactly as the site-scripts feature once did.
        self::assertStringContainsString('data-fs-embed', $html);
    }

    /**
     * Omitting the key on an update keeps the stored flag — a label-only edit
     * must never silently drop the challenge off a form under attack.
     */
    public function testASourceOnlyUpdateKeepsTheStoredTurnstileFlag(): void
    {
        $site = $this->seed($this->turnstileKeys(), turnstileOnForm: true);
        $this->forms->save($site->handle, [
            'handle' => 'contact',
            'name' => 'Renamed',
            'recipients' => 'leads@example.com',
            'fields' => [['name' => 'name', 'type' => 'text', 'label' => 'Name']],
        ]);

        self::assertTrue(($this->forms->find($site->handle, 'contact') ?? self::fail('gone'))->turnstile);
    }

    /**
     * AN UPDATE IS A PATCH. This used to rebuild the whole row from the
     * input, so an API call changing one boolean silently blanked the name,
     * subject, submit label and autoresponder copy — the same wound
     * upsert_component opened by wiping stored CSS on an omitted key, and
     * worse here because the dashboard always sends everything, so it only
     * ever bit the API caller and did it quietly.
     */
    public function testAPartialUpdateChangesOnlyTheKeysItSends(): void
    {
        $site = $this->seed();
        $this->forms->save($site->handle, [
            'handle' => 'contact',
            'recipients' => 'leads@example.com',
            'name' => 'Partner access request',
            'subject' => 'Partner request — {name}',
            'submit_label' => 'Request access',
            'success_message' => 'Thanks, a person reads every one.',
            'consent_text' => 'the privacy policy',
            'autoresponder_subject' => 'We got your request',
            'autoresponder_body' => 'Thanks for getting in touch.',
            'fields' => [['name' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true]],
        ]);

        // The one-field edit an agent would make.
        $this->forms->save($site->handle, ['handle' => 'contact', 'turnstile' => true]);

        $after = $this->forms->find($site->handle, 'contact') ?? self::fail('gone');
        self::assertTrue($after->turnstile, 'the key that WAS sent must apply');
        self::assertSame('Partner access request', $after->name);
        self::assertSame('Partner request — {name}', $after->subject);
        self::assertSame('Request access', $after->submitLabel);
        self::assertSame('Thanks, a person reads every one.', $after->successMessage);
        self::assertSame('the privacy policy', $after->consentText);
        self::assertSame('We got your request', $after->autoresponderSubject);
        self::assertSame('Thanks for getting in touch.', $after->autoresponderBody);
        self::assertCount(1, $after->fields, 'and the field list survives');
        self::assertTrue($after->enabled);
    }

    /** A create still demands the two things that make a form a form. */
    public function testCreatingStillRequiresRecipientsAndFields(): void
    {
        $site = $this->seed();

        $this->expectException(\InvalidArgumentException::class);
        $this->forms->save($site->handle, ['handle' => 'brand-new', 'name' => 'No recipients']);
    }

    /** An update may still clear a field — it just has to say so. */
    public function testAnUpdateCanStillEmptyAFieldBySendingItBlank(): void
    {
        $site = $this->seed();
        $this->forms->save($site->handle, ['handle' => 'contact', 'consent_text' => '']);

        self::assertSame('', ($this->forms->find($site->handle, 'contact') ?? self::fail('gone'))->consentText);
    }

    // ----------------------------------------------------- folder + lifecycle

    /** Releasing a false positive queues the notification and keeps the evidence. */
    public function testReleasingAHeldSubmissionQueuesItAndKeepsTheVerdict(): void
    {
        $site = $this->seed();
        $this->submit($site, [
            'name' => 'Marie',
            'email' => 'marie@bureau.example',
            'message' => 'Our portfolio: https://a.example/1 https://b.example/2',
            '_fs_consent' => '1',
        ]);

        $held = $this->forms->submissions($site->handle, null, 50, 0, 'spam');
        self::assertCount(1, $held);

        $ids = $this->forms->releaseSpam($site->handle, $held[0]->id);
        self::assertSame([$held[0]->id], $ids);

        $after = $this->forms->findSubmission($site->handle, $held[0]->id) ?? self::fail('vanished');
        self::assertSame('pending', $after->status);
        self::assertGreaterThan(0, $after->spamScore, 'a released row is a labelled mistake — keep the evidence');
    }

    /** The inbox does not show held submissions, and the spam folder shows only those. */
    public function testTheTwoFoldersAreDisjoint(): void
    {
        $site = $this->seed();
        $this->submit($site, ['name' => 'A', 'email' => 'a@bureau.example', 'message' => 'A normal enquiry.', '_fs_consent' => '1']);
        $this->submit($site, ['name' => 'B', 'email' => 'b@example.com', 'message' => 'https://a.example/1 https://b.example/2', '_fs_consent' => '1']);

        self::assertCount(1, $this->forms->submissions($site->handle, null, 50, 0, 'inbox'));
        self::assertCount(1, $this->forms->submissions($site->handle, null, 50, 0, 'spam'));
        self::assertCount(2, $this->forms->submissions($site->handle, null, 50, 0, 'all'));
    }

    /**
     * Retention is what makes auto-deletion safe. A fresh flag survives the
     * window; only an aged one is swept.
     */
    public function testThePurgeRespectsTheRetentionWindow(): void
    {
        $site = $this->seed();
        $this->submit($site, ['name' => 'B', 'email' => 'b@example.com', 'message' => 'https://a.example/1 https://b.example/2', '_fs_consent' => '1']);

        self::assertSame(0, $this->forms->purgeSpam($site->handle, 30), 'a fresh flag is inside the window');
        self::assertCount(1, $this->forms->submissions($site->handle, null, 50, 0, 'spam'));

        self::assertSame(1, $this->forms->purgeSpam($site->handle, 0), '0 days empties it now');
        self::assertSame([], $this->forms->submissions($site->handle, null, 50, 0, 'spam'));
    }

    /**
     * Rescoring is calibration, so a dry run must change nothing — otherwise
     * the only way to find out what a threshold does is to apply it.
     */
    public function testADryRunRescoreChangesNothing(): void
    {
        $site = $this->seed(['spam_mode' => 'off']);
        $this->submit($site, ['name' => 'B', 'email' => 'b@example.com', 'message' => 'https://a.example/1 https://b.example/2', '_fs_consent' => '1']);

        $rows = $this->forms->rescore(
            $site->handle,
            fn (Form $f, array $v, string $l) => $this->scorer()->score($f, $v, $l),
        );

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['would_hold']);
        self::assertSame('pending', ($this->forms->findSubmission($site->handle, $rows[0]['id']) ?? self::fail('gone'))->status);
    }

    /** Applying moves rows INTO the folder — and never drags a sent one back out. */
    public function testApplyingARescoreNeverUnsendsAnAnsweredSubmission(): void
    {
        $site = $this->seed(['spam_mode' => 'off']);
        $this->submit($site, ['name' => 'B', 'email' => 'b@example.com', 'message' => 'https://a.example/1 https://b.example/2', '_fs_consent' => '1']);

        $all = $this->forms->submissions($site->handle, null, 50, 0, 'all');
        $this->forms->markSent($site->handle, $all[0]->id);

        $this->forms->rescore(
            $site->handle,
            fn (Form $f, array $v, string $l) => $this->scorer()->score($f, $v, $l),
            null,
            true,
        );

        $after = $this->forms->findSubmission($site->handle, $all[0]->id) ?? self::fail('gone');
        self::assertSame('sent', $after->status, 'an answered submission is never reclassified');
        self::assertGreaterThan(0, $after->spamScore, 'though its score is still recorded');
    }

    /**
     * The Forms screen has to RENDER with real objects in it.
     *
     * Parsing the template proves only that the Twig is well-formed; a
     * property that does not exist on a DTO is a runtime error, and this
     * screen is the one place a held submission can be found. A 500 here
     * would mean the spam folder is unreachable and the leads in it are, from
     * the owner's point of view, simply gone.
     */
    public function testTheFormsScreenRendersBothFoldersWithRealSubmissions(): void
    {
        $site = $this->seed($this->turnstileKeys(), turnstileOnForm: true);
        $this->submit($site, ['name' => 'Marie', 'email' => 'marie@bureau.example', 'message' => 'A real enquiry.', '_fs_consent' => '1']);
        $this->submit($site, ['name' => 'Bot', 'email' => 'b@example.com', 'message' => 'https://a.example/1 https://b.example/2', '_fs_consent' => '1']);

        $site = $this->siteRepo->findByHandle($site->handle) ?? self::fail('site vanished');
        $form = $this->forms->find($site->handle, 'contact') ?? self::fail('no form');
        $view = new \Fastsite\Http\EngineView(\dirname(__DIR__, 2));

        foreach (['inbox', 'spam'] as $folder) {
            $html = $view->render('forms.html.twig', [
                'title' => 'Forms',
                'site' => $site,
                'forms' => [$form],
                'counts' => $this->forms->counts($site->handle),
                'edit_form' => $form,
                'selected' => $form,
                'folder' => $folder,
                'submissions' => $this->forms->submissions($site->handle, $form->id, 100, 0, $folder),
                'smtp_ready' => false,
                'turnstile_ready' => $site->turnstileConfigured(),
                'spam_mode' => $site->spamMode,
                'spam_retention_days' => $site->spamRetentionDays,
                'field_types' => \Fastsite\Form\Dto\FormField::TYPES,
                'message_keys' => [],
                'message_locales' => \Fastsite\Form\FormMessages::builtInLocales(),
                'user' => ['email' => 'owner@example.com', 'role' => 'admin'],
                'csrf' => 'tok',
                'nav_sites' => [$site],
            ]);

            self::assertStringContainsString('Spam guard', $html, $folder . ': the settings card must render');
            self::assertStringContainsString('folder=spam', $html, $folder . ': the folder tabs must render');
        }

        // The spam view has to show WHY, not just a badge — a score on its own
        // is unarguable and useless to someone deciding it got one wrong.
        $spamHtml = $view->render('forms.html.twig', [
            'title' => 'Forms', 'site' => $site, 'forms' => [$form],
            'counts' => $this->forms->counts($site->handle),
            'edit_form' => null, 'selected' => $form, 'folder' => 'spam',
            'submissions' => $this->forms->submissions($site->handle, $form->id, 100, 0, 'spam'),
            'smtp_ready' => false, 'turnstile_ready' => true,
            'spam_mode' => 'on', 'spam_retention_days' => 30,
            'field_types' => \Fastsite\Form\Dto\FormField::TYPES,
            'message_keys' => [], 'message_locales' => \Fastsite\Form\FormMessages::builtInLocales(),
            'user' => ['email' => 'owner@example.com', 'role' => 'admin'],
            'csrf' => 'tok', 'nav_sites' => [$site],
        ]);

        self::assertStringContainsString('links in message fields', $spamHtml, 'the reasons must be shown');
        self::assertStringContainsString('not spam', $spamHtml, 'a false positive must be releasable from the row');
    }

    /**
     * A pile of failed sends has two answers, and the screen has to offer
     * both: retry them, or accept they were never worth sending. Without the
     * bulk control, "I reviewed these 39 and they are all bots" means 39
     * clicks, which is the kind of answer that makes people give up and leave
     * the spam in the inbox instead.
     */
    public function testTheInboxOffersABulkMarkWhenSendsHaveFailed(): void
    {
        $site = $this->seed(['spam_mode' => 'off']);
        $this->submit($site, ['name' => 'A', 'email' => 'a@bureau.example', 'message' => 'One.', '_fs_consent' => '1']);
        foreach ($this->forms->submissions($site->handle, null, 50, 0, 'all') as $s) {
            $this->forms->markFailed($site->handle, $s->id, 'smtp not configured');
        }

        $site = $this->siteRepo->findByHandle($site->handle) ?? self::fail('site vanished');
        $form = $this->forms->find($site->handle, 'contact') ?? self::fail('no form');

        $html = (new \Fastsite\Http\EngineView(\dirname(__DIR__, 2)))->render('forms.html.twig', [
            'title' => 'Forms', 'site' => $site, 'forms' => [$form],
            'counts' => $this->forms->counts($site->handle),
            'edit_form' => null, 'selected' => $form, 'folder' => 'inbox',
            'submissions' => $this->forms->submissions($site->handle, $form->id, 100, 0, 'inbox'),
            'smtp_ready' => false, 'turnstile_ready' => false,
            'spam_mode' => 'off', 'spam_retention_days' => 30,
            'field_types' => \Fastsite\Form\Dto\FormField::TYPES,
            'message_keys' => [], 'message_locales' => \Fastsite\Form\FormMessages::builtInLocales(),
            'user' => ['email' => 'o@example.com', 'role' => 'admin'], 'csrf' => 'tok', 'nav_sites' => [$site],
        ]);

        self::assertStringContainsString('Mark 1 failed as spam', $html);
        self::assertStringContainsString('name="status" value="failed"', $html, 'the bulk action must stay narrowed');
    }

    /**
     * The judgement the scorer could not make. `markSpam()` shipped with the
     * table and was never called from anywhere, so confirmed spam could only
     * sit in the inbox forever — this is the surface that was missing.
     */
    public function testConfirmedSpamCanBeMarkedInBulkByCurrentStatus(): void
    {
        $site = $this->seed(['spam_mode' => 'off']);
        $this->submit($site, ['name' => 'A', 'email' => 'a@bureau.example', 'message' => 'One.', '_fs_consent' => '1']);
        $this->submit($site, ['name' => 'B', 'email' => 'b@bureau.example', 'message' => 'Two.', '_fs_consent' => '1']);

        // Put them in the state the real backlog was in: stored, never sent.
        foreach ($this->forms->submissions($site->handle, null, 50, 0, 'all') as $s) {
            $this->forms->markFailed($site->handle, $s->id, 'smtp not configured');
        }

        $ids = $this->forms->markSpamWhere($site->handle, null, null, 'failed');
        self::assertCount(2, $ids);
        self::assertCount(2, $this->forms->submissions($site->handle, null, 50, 0, 'spam'));
        self::assertSame(2, $this->forms->purgeSpam($site->handle, 0));
    }

    /**
     * Bulk marking must respect its own filter. A `status` narrowing that
     * quietly swept everything would turn "these failed sends are all bots"
     * into "delete the inbox".
     */
    public function testBulkMarkingOnlyTouchesTheNarrowedStatus(): void
    {
        $site = $this->seed(['spam_mode' => 'off']);
        $this->submit($site, ['name' => 'A', 'email' => 'a@bureau.example', 'message' => 'Failed one.', '_fs_consent' => '1']);
        $this->submit($site, ['name' => 'B', 'email' => 'b@bureau.example', 'message' => 'Live one.', '_fs_consent' => '1']);

        $all = $this->forms->submissions($site->handle, null, 50, 0, 'all');
        $this->forms->markFailed($site->handle, $all[0]->id, 'smtp down');
        $this->forms->markSent($site->handle, $all[1]->id);

        $ids = $this->forms->markSpamWhere($site->handle, null, null, 'failed');

        self::assertCount(1, $ids);
        self::assertSame('sent', ($this->forms->findSubmission($site->handle, $all[1]->id) ?? self::fail('gone'))->status);
    }

    /** Purging can never reach a lead that is not in the spam folder. */
    public function testPurgeCannotDeleteAnInboxSubmission(): void
    {
        $site = $this->seed(['spam_mode' => 'off']);
        $this->submit($site, ['name' => 'A', 'email' => 'a@bureau.example', 'message' => 'A real one.', '_fs_consent' => '1']);

        self::assertSame(0, $this->forms->purgeSpam($site->handle, 0));
        self::assertCount(1, $this->forms->submissions($site->handle, null, 50, 0, 'inbox'));
    }

    // ------------------------------------------------------------- helpers

    /** @param array<string, mixed> $overrides */
    private function seed(array $overrides = [], bool $turnstileOnForm = false): Site
    {
        $this->sites->create('spamsite', 'Spam Site', 'https://spam.example.com');
        if ($overrides !== []) {
            $this->sites->update('spamsite', $overrides);
        }

        $this->forms->save('spamsite', [
            'handle' => 'contact',
            'name' => 'Contact us',
            'recipients' => 'leads@example.com',
            'consent_text' => 'the privacy policy',
            'turnstile' => $turnstileOnForm ? '1' : '0',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true],
                ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                ['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true],
            ],
        ]);

        return $this->siteRepo->findByHandle('spamsite') ?? self::fail('site vanished');
    }

    /** @return array<string, string> */
    private function turnstileKeys(): array
    {
        return ['turnstile_site_key' => '0xSITE', 'turnstile_secret' => '0xSECRET'];
    }

    /**
     * @param list<array<string, mixed>>|null $fields
     */
    private function form(?array $fields = null): Form
    {
        return Form::fromRow([
            'id' => 1,
            'handle' => 'contact',
            'recipients' => 'leads@example.com',
            'fields' => json_encode($fields ?? [
                ['name' => 'name', 'type' => 'text', 'label' => 'Your name'],
                ['name' => 'email', 'type' => 'email', 'label' => 'Email'],
                ['name' => 'message', 'type' => 'textarea', 'label' => 'Message'],
            ]),
        ]);
    }

    /** @param ?array<string, bool> $mxFor null = every domain resolves */
    private function scorer(?array $mxFor = null): SpamScorer
    {
        return new SpamScorer(new FakeMx($mxFor));
    }

    /** @param array<string, string> $body */
    private function submit(Site $site, array $body, ?CaptchaVerifier $captcha = null): \Psr\Http\Message\ResponseInterface
    {
        $handler = new FormSubmitHandler(
            $this->siteRepo,
            $this->forms,
            new \Fastsite\Form\Service\SubmissionThrottle($this->redis),
            new \Fastsite\Shared\Queue\JobQueue($this->databases, $this->redis, new SystemClock()),
            new NullLogger(),
            $this->scorer(),
            $captcha ?? new FakeCaptcha(CaptchaResult::pass()),
            $this->cipher,
        );

        $request = (new ServerRequest('POST', '/f/' . $site->handle . '/contact'))
            ->withAttribute('site', $site->handle)
            ->withAttribute('form', 'contact')
            ->withParsedBody($body);

        return $handler->handle($request);
    }

    /**
     * Notification jobs actually on the queue's state of record.
     *
     * Asserted from the `jobs` table rather than a spy, because "was a
     * notification queued" is the question the whole guard turns on, and the
     * table is what a worker will really read.
     */
    private function queuedEmails(): int
    {
        return (int) $this->databases->engine()->fetchOne(
            "SELECT COUNT(*) FROM jobs WHERE type = 'send_form_email'",
        );
    }
}

/** Every domain resolves unless the map says otherwise. */
final class FakeMx implements MxResolver
{
    /** @param ?array<string, bool> $map */
    public function __construct(private readonly ?array $map = null)
    {
    }

    public function hasMx(string $domain): bool
    {
        return $this->map === null ? true : ($this->map[$domain] ?? false);
    }
}

final class FakeCaptcha implements CaptchaVerifier
{
    public function __construct(private readonly CaptchaResult $result)
    {
    }

    public function verify(string $secret, string $token, string $remoteIp = ''): CaptchaResult
    {
        return $this->result;
    }
}


