#!/bin/sh
# Private (internal) deploy prep (#11).
#
# The private build DEFAULTS to the endpointr AI gateway for translation, but
# it ships BOTH translators. It trims nothing: the only proprietary file is the
# endpointr client, and that is precisely the one this build keeps.
#
# This script used to `rm src/Translation/OpenRouterTranslator.php`, on the
# reasoning that the internal artifact should carry only the provider it uses.
# That reasoning stopped being true once translation could be routed per site:
# DefaultAiClientFactory guards its OpenRouter branch with class_exists(), so
# deleting the class made a site's selected AI key silently unusable FOR
# TRANSLATION while still working for fact-checking and SEO. The symptom was
# baffling — pick an OpenRouter key, watch the Translations screen keep saying
# "Built-in AI gateway", and keep hitting the gateway's rate limit with no way
# to move off it. A user-paid key must be able to translate on every build.
#
# Owner-paid translation env (the gateway used when a site selects NO AI key):
#   ENDPOINTR_URL=<endpointr url>
#   ENDPOINTR_KEY=<endpointr key>
#
# Everything user-paid — translation via a selected key, Perplexity
# fact-checking, research — comes from each user's own OpenRouter key in the
# dashboard (Account → AI keys), never from .env.
#
# Usage:  sh scripts/deploy-private.sh   (run from the repo root before build)
set -e

echo "Private build: shipping both translators (endpointr gateway by default, OpenRouter when a site selects an AI key)."
echo "Set ENDPOINTR_URL/ENDPOINTR_KEY in the environment; users add their own OpenRouter key under Account → AI keys."
