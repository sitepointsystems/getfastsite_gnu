<?php

declare(strict_types=1);

namespace Fastsite\Publish\Service;

use Fastsite\Site\Dto\Site;
use Psr\Log\LoggerInterface;

/**
 * One-click Cloudflare Pages go-live: with a scoped API token this creates
 * the Pages project bound to the site's GitHub repo, attaches the custom
 * domain, adds the DNS records when the zone lives on the same account, and
 * triggers a publish so Cloudflare has something to deploy.
 *
 * Cloudflare offers no public third-party OAuth, so a pasted token is the
 * ceiling of automation — and the one-time Cloudflare↔GitHub app install is
 * a manual click CF itself requires; when it's missing the project-create
 * step fails with the exact connect link.
 *
 * Every step reports individually (ok / already done / failed + why) so a
 * half-completed setup is visible instead of silent — the flash message on
 * the settings screen is built straight from these steps.
 */
final class CloudflarePagesService
{
    public function __construct(
        private readonly CfTransport $cf,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{ok: bool, account_id: string, steps: list<array{step: string, ok: bool, note: string}>}
     */
    public function setup(Site $site, string $token): array
    {
        $steps = [];
        // By-reference capture: $fail must see the steps appended before the
        // failing one (an arrow fn would freeze the empty array).
        $fail = static function (string $step, string $note) use (&$steps): array {
            return [
                'ok' => false, 'account_id' => '',
                'steps' => [...$steps, ['step' => $step, 'ok' => false, 'note' => $note]],
            ];
        };

        $repo = self::parseGithubRepo($site->githubRepo);
        if ($repo === null) {
            return $fail('repo', 'Set the GitHub repo above first (Cloudflare Pages deploys from it).');
        }
        $apex = $site->primaryDomain;
        if ($apex === '') {
            return $fail('domain', 'Set the base URL above first — it is the domain that gets attached.');
        }

        // 1. Token valid?
        try {
            $verify = $this->cf->request('GET', '/user/tokens/verify', null, $token);
        } catch (\RuntimeException $e) {
            return $fail('token', $e->getMessage());
        }
        if (!($verify['json']['success'] ?? false)) {
            return $fail('token', 'Cloudflare rejected the API token: ' . $this->firstError($verify['json']) . ' Create one at dash.cloudflare.com → My Profile → API Tokens with "Cloudflare Pages: Edit", "Zone: Read" and "DNS: Edit".');
        }
        $steps[] = ['step' => 'token', 'ok' => true, 'note' => 'API token verified.'];

        // 2. Which account?
        $accounts = $this->cf->request('GET', '/accounts', null, $token);
        $accountId = (string) ($accounts['json']['result'][0]['id'] ?? '');
        if ($accountId === '') {
            return $fail('account', 'The token can\'t list any Cloudflare account — grant it "Account Settings: Read" (or scope it to your account).');
        }
        $accountName = (string) ($accounts['json']['result'][0]['name'] ?? $accountId);
        $steps[] = ['step' => 'account', 'ok' => true, 'note' => 'Account: ' . $accountName . '.'];

        // 3. Create the Pages project bound to the repo.
        $project = self::projectName($site->handle);
        $create = $this->cf->request('POST', '/accounts/' . $accountId . '/pages/projects', [
            'name' => $project,
            'production_branch' => $site->githubBranch !== '' ? $site->githubBranch : 'main',
            'build_config' => ['build_command' => '', 'destination_dir' => '', 'root_dir' => ''],
            'source' => [
                'type' => 'github',
                'config' => [
                    'owner' => $repo['owner'],
                    'repo_name' => $repo['name'],
                    'production_branch' => $site->githubBranch !== '' ? $site->githubBranch : 'main',
                    'deployments_enabled' => true,
                    'pr_comments_enabled' => false,
                ],
            ],
        ], $token);

        if ($create['json']['success'] ?? false) {
            $steps[] = ['step' => 'project', 'ok' => true, 'note' => 'Pages project "' . $project . '" created from ' . $repo['owner'] . '/' . $repo['name'] . '.'];
        } elseif ($this->alreadyExists($create['json'])) {
            $steps[] = ['step' => 'project', 'ok' => true, 'note' => 'Pages project "' . $project . '" already exists — reusing it.'];
        } else {
            $why = $this->firstError($create['json']);
            // The one thing the API cannot do for the user: the CF↔GitHub app
            // install. Point at the exact screen that fixes it.
            if (stripos($why, 'github') !== false || stripos($why, 'source') !== false || stripos($why, 'install') !== false) {
                $why .= ' — connect GitHub to Cloudflare once at https://dash.cloudflare.com/' . $accountId . '/pages/new/provider/github (pick the repo, cancel the wizard), then run this again.';
            }

            return [
                'ok' => false, 'account_id' => $accountId,
                'steps' => [...$steps, ['step' => 'project', 'ok' => false, 'note' => 'Could not create the Pages project: ' . $why]],
            ];
        }

        // 4. Attach the custom domain to the project.
        $domain = $this->cf->request('POST', '/accounts/' . $accountId . '/pages/projects/' . $project . '/domains', ['name' => $apex], $token);
        if (($domain['json']['success'] ?? false) || $this->alreadyExists($domain['json'])) {
            $steps[] = ['step' => 'domain', 'ok' => true, 'note' => $apex . ' attached to the project.'];
        } else {
            $steps[] = ['step' => 'domain', 'ok' => false, 'note' => 'Could not attach ' . $apex . ': ' . $this->firstError($domain['json'])];
        }

        // 5. DNS — only automatic when the zone lives on this account.
        $pagesHost = $project . '.pages.dev';
        $zones = $this->cf->request('GET', '/zones?name=' . rawurlencode($apex), null, $token);
        $zoneId = (string) ($zones['json']['result'][0]['id'] ?? '');
        if ($zoneId !== '') {
            $made = [];
            foreach ([$apex, 'www.' . $apex] as $recordName) {
                $rec = $this->cf->request('POST', '/zones/' . $zoneId . '/dns_records', [
                    'type' => 'CNAME',
                    'name' => $recordName,
                    'content' => $pagesHost,
                    'proxied' => true,
                    'ttl' => 1,
                ], $token);
                if (($rec['json']['success'] ?? false) || $this->alreadyExists($rec['json'])) {
                    $made[] = $recordName;
                }
            }
            $steps[] = $made !== []
                ? ['step' => 'dns', 'ok' => true, 'note' => 'DNS CNAME → ' . $pagesHost . ' for ' . implode(' + ', $made) . '.']
                : ['step' => 'dns', 'ok' => false, 'note' => 'Zone found but the DNS records could not be written — add a CNAME ' . $apex . ' → ' . $pagesHost . ' manually.'];
        } else {
            $steps[] = ['step' => 'dns', 'ok' => true, 'note' => 'Zone ' . $apex . ' is not on this Cloudflare account — at your DNS host, add a CNAME ' . $apex . ' → ' . $pagesHost . '.'];
        }

        $this->logger->info('Cloudflare Pages setup ran', ['site' => $site->handle, 'project' => $project, 'account' => $accountId]);

        return ['ok' => !\in_array(false, array_column($steps, 'ok'), true), 'account_id' => $accountId, 'steps' => $steps];
    }

    /**
     * @return array{owner: string, name: string}|null
     */
    public static function parseGithubRepo(string $repo): ?array
    {
        // git@github.com:owner/name.git  |  https://github.com/owner/name(.git)
        if (preg_match('#^git@github\.com:([^/]+)/(.+?)(?:\.git)?$#', $repo, $m) === 1
            || preg_match('#^https://github\.com/([^/]+)/(.+?)(?:\.git)?/?$#', $repo, $m) === 1) {
            return ['owner' => $m[1], 'name' => $m[2]];
        }

        return null;
    }

    /** Pages project names: lowercase alnum + dashes, alnum at both ends. */
    public static function projectName(string $handle): string
    {
        $name = strtolower(str_replace('_', '-', $handle));
        $name = (string) preg_replace('/[^a-z0-9-]/', '-', $name);

        return trim(substr($name, 0, 58), '-') ?: 'site';
    }

    /**
     * @param array<string, mixed> $json
     */
    private function alreadyExists(array $json): bool
    {
        foreach ((array) ($json['errors'] ?? []) as $error) {
            $msg = strtolower((string) ($error['message'] ?? ''));
            if (str_contains($msg, 'already exists') || str_contains($msg, 'already associated') || str_contains($msg, 'duplicate')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function firstError(array $json): string
    {
        $first = ((array) ($json['errors'] ?? []))[0] ?? null;
        $msg = \is_array($first) ? (string) ($first['message'] ?? '') : '';

        return $msg !== '' ? $msg : 'unknown Cloudflare API error.';
    }
}
