#!/bin/sh
# Open-source (public) deploy prep (#11).
#
# The public build uses OpenRouter for AI translation and must NOT ship the
# proprietary endpointr gateway client. This excludes that one file; the AI
# client factory (config/services.php → DefaultAiClientFactory) then resolves
# OpenRouterTranslator via class_exists().
#
# NO AI keys go in the environment. Each user adds their own OpenRouter key in
# the dashboard (Account → AI keys) and selects it per site — the key spends
# the user's own AI credits, not the operator's. It powers both translation
# and Perplexity fact-checking.
#
# Usage:  sh scripts/deploy-public.sh   (run from the repo root before build)
set -e

rm -f src/Translation/EndpointrTranslator.php
rm -f src/Ai/EndpointrChatClient.php
echo "Removed src/Translation/EndpointrTranslator.php and src/Ai/EndpointrChatClient.php (proprietary — excluded from the public build)."
echo "No AI env vars needed: users add their own OpenRouter key under Account → AI keys."

# THE PUBLIC BUILD SHIPS NO SITES. The marketing seeder stands up
# getfastsite.com — our own marketing copy — and shell-marketing is used by
# nothing else, so both go. Every reference degrades instead of fataling:
# McpTools takes the seeder as a NULLABLE dependency wired behind
# class_exists (config/services.php), the CLI command refuses with a clear
# message, and the tool is omitted from tools/list rather than advertised
# and broken.
#
# templates/shell-default deliberately STAYS: SiteService installs it into
# every new site, so removing it would leave a blank install unable to
# build anything at all. It is the starter theme, not a site.
rm -f src/Site/Service/MarketingSeeder.php
rm -f tests/Integration/MarketingSeederTest.php
rm -rf templates/shell-marketing
# Describes how WE run getfastsite.com (and names the seeder), so it
# documents a site and a feature the public build does not have.
rm -f docs/domain-split.md
echo "Removed the marketing seeder, its test and templates/shell-marketing (the public build ships no sites; shell-default stays)."
