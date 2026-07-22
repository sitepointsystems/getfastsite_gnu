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
echo "Removed src/Translation/EndpointrTranslator.php (proprietary — excluded from the public build)."
echo "No AI env vars needed: users add their own OpenRouter key under Account → AI keys."
