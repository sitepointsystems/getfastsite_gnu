<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\BuildService;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Form\Dto\Form;
use Fastsite\Form\Service\FormMailer;
use Fastsite\Form\Service\FormRenderer;
use Fastsite\Form\Service\FormService;
use Fastsite\Mail\MailException;
use Fastsite\Mail\MailTransport;
use Fastsite\Mail\Message;
use Fastsite\Mail\SmtpConfig;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Forms end to end: the placeholder survives sanitising, the build turns it
 * into a real zero-JS form, submissions validate and store, and the mail that
 * goes out addresses itself correctly.
 */
final class FormsTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private ContentService $content;
    private FormService $forms;
    private BuildService $build;
    private DbalSiteRepository $siteRepo;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-forms-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $mediaRepo = new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock);
        $componentRepo = new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock);

        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        // The cipher is the optional LAST argument, and leaving it out makes
        // SiteService silently drop every encrypted field (turnstile_secret,
        // smtp_password, the FTP credentials). A fixture without it does not
        // fail — it quietly tests the unconfigured path while looking like it
        // tests the configured one.
        $this->sites = new SiteService(
            $this->siteRepo,
            $this->databases,
            $migrator,
            $clock,
            new NullLogger(),
            $appRoot,
            new TokenCipher(str_repeat('k', 32)),
        );
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, $mediaRepo);
        $this->forms = new FormService($this->databases, $clock);
        $this->build = new BuildService(
            $contentRepo,
            $this->databases,
            $clock,
            new NullLogger(),
            new \Fastsite\Build\Service\MediaRenderer($mediaRepo),
            new \Fastsite\Translation\Repository\DbalTranslationRepository($this->databases, $clock),
            new \Fastsite\Build\Service\ComponentRenderer($componentRepo, $this->databases, new NullLogger(), $appRoot),
            new \Fastsite\Build\Figlet\FigletRenderer(),
            new \Fastsite\Build\Service\FileRenderer($mediaRepo, $this->databases, new NullLogger(), $appRoot),
            $mediaRepo,
            new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock),
            new \Fastsite\Build\Service\HtaccessBuilder(),
            new \Fastsite\Component\Service\ComponentHookService($this->databases, $clock, $componentRepo),
            new FormRenderer($this->forms, 'https://engine.example.com'),
        );
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

    private function seedSite(string $handle = 'formsite'): Site
    {
        $this->sites->create($handle, 'Form Site', 'https://forms.example.com');

        return $this->siteRepo->findByHandle($handle) ?? self::fail('site vanished');
    }

    private function seedForm(string $handle = 'formsite'): Form
    {
        return $this->forms->save($handle, [
            'handle' => 'contact',
            'name' => 'Contact us',
            'recipients' => 'leads@example.com',
            'subject' => 'Enquiry from {name}',
            'consent_text' => 'the privacy policy',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true],
                ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                ['name' => 'topic', 'type' => 'select', 'label' => 'Topic', 'options' => ['Sales', 'Support']],
                ['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true],
            ],
        ]);
    }

    /**
     * The reason the whole feature is a build-time placeholder rather than
     * authored markup: ContentSanitizer strips <form>, <input> and <button>
     * from every ingest path, so a hand-written form silently vanishes.
     */
    public function testFormMarkupIsStrippedByTheSanitizerButThePlaceholderSurvives(): void
    {
        $site = $this->seedSite();

        $this->content->create($site, [
            'type' => 'page', 'title' => 'Hand written', 'slug' => 'hand', 'status' => 'published',
            'body_html' => '<form action="/x"><input name="a"><button>Go</button></form>',
        ]);
        $this->content->create($site, [
            'type' => 'page', 'title' => 'Placeholder', 'slug' => 'ph', 'status' => 'published',
            'body_html' => '<p>Hi</p><div data-fs-form="contact"></div>',
        ]);

        $repo = new DbalContentRepository($this->databases, new SystemClock());
        $hand = $repo->findBySlug($site->handle, 'page', 'hand') ?? self::fail('missing');
        $placeholder = $repo->findBySlug($site->handle, 'page', 'ph') ?? self::fail('missing');

        self::assertStringNotContainsString('<form', $hand->bodyHtml, 'the sanitizer must strip authored form markup');
        self::assertStringNotContainsString('<input', $hand->bodyHtml);
        self::assertStringContainsString('data-fs-form="contact"', $placeholder->bodyHtml, 'the inert placeholder must survive sanitising');
    }

    public function testBuildExpandsThePlaceholderIntoAWorkingZeroJsForm(): void
    {
        $site = $this->seedSite();
        $this->seedForm();
        $this->content->create($site, [
            'type' => 'page', 'title' => 'Contact', 'slug' => 'contact', 'status' => 'published',
            'body_html' => '<p>Say hello.</p><div data-fs-form="contact"></div>',
        ]);

        $this->build->build($site);
        $html = (string) file_get_contents($this->dataPath . '/sites/' . $site->handle . '/build/contact/index.html');

        self::assertStringContainsString('action="https://engine.example.com/f/formsite/contact"', $html);
        self::assertStringContainsString('method="post"', $html);
        // Native validation, so the browser catches most mistakes with no JS.
        self::assertStringContainsString('type="email"', $html);
        self::assertStringContainsString('required', $html);
        // The honeypot ships, and the consent box is required.
        self::assertStringContainsString('name="' . FormRenderer::HONEYPOT . '"', $html);
        self::assertStringContainsString('name="' . FormRenderer::CONSENT . '"', $html);
        // The whole point: a form costs the page nothing in JavaScript. The
        // only <script> allowed here is the JSON-LD data block, which is not
        // executable and which PageAudit exempts for the same reason.
        $executable = preg_replace('#<script type="application/ld\+json">.*?</script>#s', '', $html) ?? $html;
        self::assertStringNotContainsString('<script', $executable, 'a form must not ship any JavaScript');
        // The form CSS rides along in the page's inlined stylesheet.
        self::assertStringContainsString('.fs-form', $html);
    }

    /**
     * The build's CSP sets no form-action directive, and CSP3 gives that
     * directive no default-src fallback — which is precisely why a static page
     * may post cross-origin to the engine. If a form-action ever appears here,
     * every form on every site breaks.
     */
    public function testHeadersStillCarryNoFormActionDirective(): void
    {
        $site = $this->seedSite();
        $this->seedForm();
        $this->content->create($site, [
            'type' => 'page', 'title' => 'Contact', 'slug' => 'contact', 'status' => 'published',
            'body_html' => '<div data-fs-form="contact"></div>',
        ]);
        $this->build->build($site);

        $headers = (string) file_get_contents($this->dataPath . '/sites/' . $site->handle . '/build/_headers');
        self::assertStringNotContainsString('form-action', $headers);
        self::assertStringContainsString("script-src 'none'", $headers, 'a form alone must not relax the zero-JS CSP');
    }

    /**
     * A Turnstile form must BUILD. This is the exact failure the site-scripts
     * feature hit: a third-party `<script src>` on the page trips PageAudit's
     * external-resource and zero-JS invariants, which are FAILs — they block
     * build → publish → preview outright and make the feature unusable rather
     * than merely degraded. The widget ships inside `<div data-fs-embed>`,
     * which PageAudit depth-strips, and the CSP follows the shipped script.
     */
    public function testATurnstileFormBuildsAndRelaxesOnlyItsOwnPageCsp(): void
    {
        $site = $this->seedSite();
        $this->sites->update($site->handle, [
            'turnstile_site_key' => '0xSITEKEY',
            'turnstile_secret' => '0xSECRET',
        ]);
        $this->forms->save($site->handle, [
            'handle' => 'contact',
            'name' => 'Contact us',
            'recipients' => 'leads@example.com',
            'turnstile' => '1',
            'fields' => [['name' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true]],
        ]);
        $site = $this->siteRepo->findByHandle($site->handle) ?? self::fail('site vanished');

        $this->content->create($site, [
            'type' => 'page', 'title' => 'Contact', 'slug' => 'contact', 'status' => 'published',
            'body_html' => '<div data-fs-form="contact"></div>',
        ]);

        // The assertion IS that this does not throw: a PageAudit FAIL aborts.
        $this->build->build($site);

        $html = (string) file_get_contents($this->dataPath . '/sites/' . $site->handle . '/build/contact/index.html');
        self::assertStringContainsString('challenges.cloudflare.com', $html);
        self::assertStringContainsString('data-fs-embed', $html, 'the widget must ship inside the audit-exempt wrapper');

        // This test's NAME has been the truth since per-page CSP landed; it
        // used to assert on the site-wide file, which could not distinguish
        // "only its own page" from "the whole site". Now it can.
        $headers = (string) file_get_contents($this->dataPath . '/sites/' . $site->handle . '/build/_headers');
        self::assertMatchesRegularExpression(
            "~/\\*\n(?:  [^\n]*\n)*?  Content-Security-Policy: script-src 'none'~",
            $headers,
            "the rest of the site keeps script-src 'none' — that is the whole point of targeting",
        );
        self::assertMatchesRegularExpression(
            "~/contact/\n  Content-Security-Policy: script-src 'self'~",
            $headers,
            'the page that ships the widget gets the relax, by path',
        );
        // Still no form-action, or every form on every site breaks.
        self::assertStringNotContainsString('form-action', $headers);
    }

    /**
     * The cipher is what proves a form with the box ticked but no stored keys
     * renders nothing: the widget is not a "maybe", it is off until both
     * halves exist, because a form that looks protected and is not gives
     * nobody a reason to look at it.
     */
    public function testTurnstileTickedWithoutKeysShipsNoWidgetAndKeepsZeroJs(): void
    {
        $site = $this->seedSite();
        $this->forms->save($site->handle, [
            'handle' => 'contact',
            'name' => 'Contact us',
            'recipients' => 'leads@example.com',
            'turnstile' => '1',
            'fields' => [['name' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true]],
        ]);
        $site = $this->siteRepo->findByHandle($site->handle) ?? self::fail('site vanished');

        $this->content->create($site, [
            'type' => 'page', 'title' => 'Contact', 'slug' => 'contact', 'status' => 'published',
            'body_html' => '<div data-fs-form="contact"></div>',
        ]);
        $this->build->build($site);

        $html = (string) file_get_contents($this->dataPath . '/sites/' . $site->handle . '/build/contact/index.html');
        self::assertStringNotContainsString('cf-turnstile', $html);

        $headers = (string) file_get_contents($this->dataPath . '/sites/' . $site->handle . '/build/_headers');
        self::assertStringContainsString("script-src 'none'", $headers, 'no widget means no CSP relaxation');
    }

    public function testUnknownOrDisabledFormRendersNothingRatherThanBreakingTheBuild(): void
    {
        $site = $this->seedSite();
        $this->forms->save($site->handle, [
            'handle' => 'off', 'recipients' => 'a@example.com', 'enabled' => false,
            'fields' => [['name' => 'email', 'type' => 'email', 'label' => 'Email']],
        ]);
        $this->content->create($site, [
            'type' => 'page', 'title' => 'Two', 'slug' => 'two', 'status' => 'published',
            'body_html' => '<div data-fs-form="ghost"></div><div data-fs-form="off"></div><p>After</p>',
        ]);

        $this->build->build($site);
        $html = (string) file_get_contents($this->dataPath . '/sites/' . $site->handle . '/build/two/index.html');

        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString('After', $html, 'the rest of the page must still render');
    }

    public function testHoneypotIsReportedAsSpamWithoutRevealingItself(): void
    {
        $this->seedSite();
        $form = $this->seedForm();

        $result = $this->forms->validate($form, [
            'name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'buy things',
            FormRenderer::CONSENT => '1',
            FormRenderer::HONEYPOT => 'http://spam.example',
        ]);

        self::assertTrue($result['spam']);
        self::assertSame([], $result['errors'], 'a caught bot must not be told which field caught it');
    }

    public function testValidationEnforcesRequiredFieldsEmailShapeSelectOptionsAndConsent(): void
    {
        $this->seedSite();
        $form = $this->seedForm();

        $bad = $this->forms->validate($form, ['name' => '', 'email' => 'not-an-email', 'topic' => 'Nonsense', 'message' => '']);
        $joined = implode(' ', $bad['errors']);

        self::assertStringContainsString('Your name is required', $joined);
        self::assertStringContainsString('not a valid email', $joined);
        self::assertStringContainsString('not one of the available choices', $joined, 'a select must be enforced server-side, not only by the browser');
        self::assertStringContainsString('Message is required', $joined);
        self::assertStringContainsString('privacy policy', $joined, 'the consent box is a server-side requirement too');

        $good = $this->forms->validate($form, [
            'name' => 'Ada', 'email' => 'ada@example.com', 'topic' => 'Sales',
            'message' => 'Hello', FormRenderer::CONSENT => '1',
        ]);
        self::assertSame([], $good['errors']);
        self::assertFalse($good['spam']);
        self::assertSame('Ada', $good['values']['name']);
    }

    public function testSubmissionIsStoredWithAHashedIpAndAReplyToAddress(): void
    {
        $this->seedSite();
        $form = $this->seedForm();

        $submission = $this->forms->record('formsite', $form, [
            'name' => 'Ada', 'email' => 'ada@example.com', 'topic' => 'Sales', 'message' => 'Hi',
        ], ['ip' => '203.0.113.7', 'page' => '/contact/', 'locale' => 'en', 'ua' => 'Test/1.0']);

        self::assertSame('pending', $submission->status);
        self::assertSame('ada@example.com', $submission->replyTo);
        self::assertSame('/contact/', $submission->pagePath);
        // The raw address is personal data we have no reason to keep.
        self::assertNotSame('203.0.113.7', $submission->ipHash);
        self::assertNotEmpty($submission->ipHash);
    }

    public function testFailedSendsCanBeRetriedAfterFixingTheMailSettings(): void
    {
        $this->seedSite();
        $form = $this->seedForm();

        $a = $this->forms->record('formsite', $form, ['email' => 'a@example.com']);
        $b = $this->forms->record('formsite', $form, ['email' => 'b@example.com']);
        $this->forms->markFailed('formsite', $a->id, 'auth rejected', 'trace');
        $this->forms->markSent('formsite', $b->id);

        $ids = $this->forms->retryFailed('formsite', $form->id);
        self::assertSame([$a->id], $ids, 'only the failed row is retried, never one already delivered');

        $reloaded = $this->forms->findSubmission('formsite', $a->id) ?? self::fail('gone');
        self::assertSame('pending', $reloaded->status);
        self::assertSame('', $reloaded->error, 'the stale error must be cleared or the screen keeps showing a fixed problem');
    }

    /**
     * Forms live in the site db and their markup is generated at build, so an
     * edit moves no content timestamp. Without this in the preview staleness
     * signature, preview keeps serving the pre-edit markup — the bug class the
     * media, component and site-settings work each hit in turn.
     */
    public function testSignatureChangesWhenAFormChanges(): void
    {
        $this->seedSite();
        $this->seedForm();
        $before = $this->forms->signature('formsite');

        $this->forms->save('formsite', [
            'handle' => 'contact', 'recipients' => 'leads@example.com',
            'submit_label' => 'Send it',
            'fields' => [['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true]],
        ]);

        self::assertNotSame($before, $this->forms->signature('formsite'));
    }

    public function testSuccessPathMustBeOnThisSite(): void
    {
        $this->seedSite();

        $this->expectException(\InvalidArgumentException::class);
        $this->forms->save('formsite', [
            'handle' => 'contact', 'recipients' => 'a@example.com',
            'success_path' => 'https://evil.example.net/phish',
            'fields' => [['name' => 'email', 'type' => 'email', 'label' => 'Email']],
        ]);
    }

    public function testAFormNeedsARecipientAndTheyMustBeRealAddresses(): void
    {
        $this->seedSite();

        try {
            $this->forms->save('formsite', ['handle' => 'a', 'recipients' => '', 'fields' => [['name' => 'x', 'type' => 'text']]]);
            self::fail('a form with no recipient must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('recipient', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->forms->save('formsite', ['handle' => 'a', 'recipients' => 'not-an-address', 'fields' => [['name' => 'x', 'type' => 'text']]]);
    }

    /**
     * From is the site's own authenticated sender and the visitor goes in
     * Reply-To. Backwards, providers SPF-fail the mail and it lands in spam —
     * and a From the visitor controls is a forgery on the customer's domain.
     */
    public function testNotificationSendsFromTheSiteAndRepliesToTheVisitor(): void
    {
        $site = $this->seedSite();
        $form = $this->seedForm();
        $site = $this->sites->update($site->handle, [
            'smtp_host' => 'smtp.example.com',
            'smtp_from_email' => 'website@example.com',
            'smtp_from_name' => 'Form Site',
        ]);

        $submission = $this->forms->record($site->handle, $form, [
            'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'topic' => 'Sales', 'message' => 'Hello',
        ], ['page' => '/contact/']);

        $transport = new RecordingMailTransport();
        (new FormMailer($transport, new TokenCipher(str_repeat('k', 32))))->notify($site, $form, $submission);

        $sent = $transport->sent[0] ?? self::fail('nothing was sent');
        self::assertSame('website@example.com', $sent->fromEmail);
        self::assertSame(['leads@example.com'], $sent->to);
        self::assertSame('ada@example.com', $sent->replyTo, 'reply must reach the person who wrote in');
        self::assertSame('Enquiry from Ada Lovelace', $sent->subject, '{field} placeholders fill from the submission');
        self::assertStringContainsString('Hello', $sent->textBody);
    }

    public function testAMissingMailConfigurationFailsLoudlyInsteadOfSilentlyDoingNothing(): void
    {
        $site = $this->seedSite();
        $form = $this->seedForm();
        $submission = $this->forms->record($site->handle, $form, ['email' => 'a@example.com']);

        $mailer = new FormMailer(new RecordingMailTransport(), new TokenCipher(str_repeat('k', 32)));

        $this->expectException(MailException::class);
        $mailer->notify($site, $form, $submission);
    }

    public function testAutoresponderStaysOffUnlessAskedFor(): void
    {
        $site = $this->seedSite();
        $form = $this->seedForm();
        $site = $this->sites->update($site->handle, [
            'smtp_host' => 'smtp.example.com', 'smtp_from_email' => 'website@example.com',
        ]);
        $submission = $this->forms->record($site->handle, $form, ['name' => 'Ada', 'email' => 'ada@example.com']);

        $transport = new RecordingMailTransport();
        (new FormMailer($transport, new TokenCipher(str_repeat('k', 32))))->autorespond($site, $form, $submission);

        self::assertSame([], $transport->sent, 'the autoresponder is a backscatter vector — it must be opt-in');
    }

    /**
     * The escape hatch for anything the generated markup cannot express — a
     * multi-step wizard, a price calculator with a running total. The author
     * writes the form, the definition still supplies recipients, labels and
     * validation, and undefined names a bot invents are dropped.
     */
    public function testAHandAuthoredFormCanPostToTheSameEndpoint(): void
    {
        $this->seedSite();
        $form = $this->forms->save('formsite', [
            'handle' => 'quote', 'recipients' => 'leads@example.com',
            'fields' => [
                ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                ['name' => 'total', 'type' => 'hidden', 'label' => 'Estimate'],
            ],
        ]);

        // No _fs_page, no _fs_return, no honeypot — a hand-written form sends
        // none of those, and it must still validate.
        $result = $this->forms->validate($form, [
            'email' => 'ada@example.com',
            'total' => '7995',
            'nonsense_injected_by_a_bot' => 'drop me',
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame('7995', $result['values']['total'], 'a script-computed hidden value is kept');
        self::assertArrayNotHasKey('nonsense_injected_by_a_bot', $result['values'], 'undefined names are dropped');
    }

    public function testHiddenFieldsRenderOutsideTheGridAndCarryNoLabel(): void
    {
        $site = $this->seedSite();
        $form = $this->forms->save('formsite', [
            'handle' => 'h', 'recipients' => 'a@example.com',
            'fields' => [
                ['name' => 'email', 'type' => 'email', 'label' => 'Email'],
                ['name' => 'source', 'type' => 'hidden', 'label' => 'Source', 'placeholder' => 'spring-campaign'],
            ],
        ]);

        $html = (new FormRenderer($this->forms, 'https://engine.example.com'))
            ->renderForm($site, $form, 'en', '/x/');

        self::assertStringContainsString('<input type="hidden" name="source" value="spring-campaign">', $html);
        // Outside the grid: a labelless cell would blow a hole in the layout.
        $grid = substr($html, (int) strpos($html, 'fs-form-grid'));
        self::assertStringNotContainsString('name="source"', $grid);
    }

    public function testFieldNamesCannotShadowTheEngineControlFields(): void
    {
        $this->seedSite();
        $form = $this->forms->save('formsite', [
            'handle' => 'x', 'recipients' => 'a@example.com',
            'fields' => [
                ['name' => FormRenderer::HONEYPOT, 'type' => 'text', 'label' => 'Sneaky'],
                ['name' => FormRenderer::CONSENT, 'type' => 'text', 'label' => 'Sneakier'],
            ],
        ]);

        foreach ($form->fields as $field) {
            self::assertStringStartsNotWith('_fs_', $field->name);
        }
    }

    public function testSmtpPasswordIsEncryptedAtRestAndBlankKeepsIt(): void
    {
        $site = $this->seedSite();
        $cipher = new TokenCipher(str_repeat('k', 32));
        $sites = new SiteService(
            $this->siteRepo,
            $this->databases,
            new Migrator(new SystemClock()),
            new SystemClock(),
            new NullLogger(),
            \dirname(__DIR__, 2),
            $cipher,
        );

        $saved = $sites->update($site->handle, ['smtp_host' => 'smtp.example.com', 'smtp_password' => 'hunter2 ']);
        self::assertNotNull($saved->smtpPasswordEnc);
        self::assertStringNotContainsString('hunter2', (string) $saved->smtpPasswordEnc);
        // NOT trimmed: an app password may legitimately end in a space.
        self::assertSame('hunter2 ', $cipher->decrypt((string) $saved->smtpPasswordEnc));

        $again = $sites->update($site->handle, ['smtp_username' => 'bob', 'smtp_password' => '']);
        self::assertSame($saved->smtpPasswordEnc, $again->smtpPasswordEnc, 'a blank password field must keep the stored one');

        $cleared = $sites->update($site->handle, ['smtp_password' => '__clear__']);
        self::assertNull($cleared->smtpPasswordEnc);
    }

    /**
     * Every value in the CSV export was typed by a stranger, and Excel,
     * LibreOffice and Sheets all EXECUTE a cell that begins with = + - @ — so
     * an unescaped export turns a contact form into code execution on the
     * laptop of whoever opens the leads spreadsheet.
     */
    public function testCsvExportNeutralisesFormulaInjection(): void
    {
        $cell = new \ReflectionMethod(\Fastsite\Dashboard\Handler\FormsActionHandler::class, 'csvCell');

        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $dangerous) {
            self::assertStringStartsWith("'", (string) $cell->invoke(null, $dangerous), $dangerous . ' must be made literal');
        }

        // Ordinary answers must survive untouched, or the export is unreadable.
        self::assertSame('Ada Lovelace', $cell->invoke(null, 'Ada Lovelace'));
        self::assertSame('', $cell->invoke(null, ''));
    }

    /**
     * The no-JavaScript path used to answer in English on every site, so a
     * Danish visitor was told "Dato for festen is required" — worse than
     * either language on its own.
     */
    public function testValidationErrorsComeBackInTheSitesOwnLanguage(): void
    {
        $this->seedSite();
        $form = $this->seedForm();

        // An EMPTY email is "required"; the format message only fires on a
        // non-empty value, so the two need separate payloads.
        $danish = $this->forms->validate($form, ['topic' => 'Sales', 'email' => 'ikke-en-mail'], 'da');
        self::assertContains('Your name skal udfyldes.', $danish['errors']);
        self::assertContains('Email er ikke en gyldig e-mailadresse.', $danish['errors']);
        self::assertContains('Topic er ikke et af de mulige valg.', $this->forms->validate($form, ['topic' => 'Vrøvl'], 'da')['errors']);

        $german = $this->forms->validate($form, ['email' => 'nope'], 'de');
        self::assertContains('Email ist keine gültige E-Mail-Adresse.', $german['errors']);

        // A locale with no built-in pack must fall back to English rather than
        // to empty strings.
        $finnish = $this->forms->validate($form, [], 'fi');
        self::assertContains('Your name is required.', $finnish['errors']);
    }

    public function testARegionalLocaleResolvesToItsBasePack(): void
    {
        self::assertSame(
            \Fastsite\Form\FormMessages::for('da')->get('sent_title'),
            \Fastsite\Form\FormMessages::for('da-DK')->get('sent_title'),
        );
        // Norwegian is written no / nb / nn depending on who is asking.
        self::assertSame(
            \Fastsite\Form\FormMessages::for('nb')->get('sent_title'),
            \Fastsite\Form\FormMessages::for('no')->get('sent_title'),
        );
    }

    /**
     * A built-in pack can only be neutral-polite; a site's own voice usually
     * isn't. The override is what closes that gap — and it must survive a
     * round trip through the database.
     */
    public function testPerFormMessageOverridesWinAndPersist(): void
    {
        $this->seedSite();
        $this->forms->save('formsite', [
            'handle' => 'contact', 'recipients' => 'leads@example.com',
            'fields' => [['name' => 'navn', 'type' => 'text', 'label' => 'Navn', 'required' => true]],
            'messages' => ['required' => 'Du mangler at udfylde {field}.', 'bogus_key' => 'ignored'],
        ]);

        $form = $this->forms->find('formsite', 'contact') ?? self::fail('gone');
        self::assertSame(['required' => 'Du mangler at udfylde {field}.'], $form->messages, 'unknown keys are dropped, not stored');

        $result = $this->forms->validate($form, [], 'da');
        self::assertSame(['Du mangler at udfylde Navn.'], $result['errors']);
    }

    /**
     * The REST and MCP surfaces send only what changed, so a save that omits
     * `messages` must not wipe wording someone already set.
     */
    public function testOmittingMessagesOnUpdateKeepsTheStoredWording(): void
    {
        $this->seedSite();
        $base = [
            'handle' => 'contact', 'recipients' => 'leads@example.com',
            'fields' => [['name' => 'navn', 'type' => 'text', 'label' => 'Navn', 'required' => true]],
        ];
        $this->forms->save('formsite', $base + ['messages' => ['required' => 'Skriv jeres {field}.']]);
        $this->forms->save('formsite', $base + ['submit_label' => 'Send']);

        $form = $this->forms->find('formsite', 'contact') ?? self::fail('gone');
        self::assertSame('Skriv jeres {field}.', $form->messages['required'] ?? null);
    }

    public function testDefaultPortFollowsTheChosenEncryption(): void
    {
        self::assertSame(587, SmtpConfig::resolvePort(0, 'starttls'));
        self::assertSame(465, SmtpConfig::resolvePort(0, 'tls'));
        self::assertSame(25, SmtpConfig::resolvePort(0, 'none'));
        self::assertSame(2525, SmtpConfig::resolvePort(2525, 'starttls'), 'an explicit port always wins');
    }
}

/**
 * Captures messages instead of opening a socket — the seam the MailTransport
 * interface exists for.
 */
final class RecordingMailTransport implements MailTransport
{
    /** @var list<Message> */
    public array $sent = [];

    public function send(SmtpConfig $config, Message $message): void
    {
        $this->sent[] = $message;
    }

    public function probe(SmtpConfig $config): string
    {
        return '* fake probe';
    }
}
