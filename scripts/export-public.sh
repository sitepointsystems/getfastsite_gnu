#!/bin/sh
# Build the GPL-3.0 PUBLIC export tree — the source published as
# github.com/sitepointsystems/getfastsite_gnu.
#
# It is a SCRUBBED SNAPSHOT, not a git mirror: the private history never
# travels, so commit messages, branch names and reverted experiments stay
# private. Three classes of thing are removed, and the ordering matters:
#
#   1. Untracked files can never leak BY CONSTRUCTION, because `git archive`
#      emits tracked files only — var/, vendor/, node_modules/ and .env are
#      excluded without anyone having to remember them.
#   2. Proprietary code and sites go through scripts/deploy-public.sh, which is
#      the ONE definition of "public" and is shared with the FLAVOR=public
#      Docker build. Two trimmers would drift, and the drift would only be
#      discovered once something private was already published.
#   3. The operating notes and agent config are removed here: CLAUDE.md carries
#      client domains and infrastructure ids, and .claude/ is one developer's
#      local tooling config.
#
# Nothing in this script pushes. It builds a tree for inspection; publishing is
# a deliberate separate step, because a public repo cannot be un-published.
#
# Usage:  sh scripts/export-public.sh [<commit-ish>] <target-dir>
set -e

if [ "$#" -eq 1 ]; then
    rev="HEAD"
    out="$1"
elif [ "$#" -eq 2 ]; then
    rev="$1"
    out="$2"
else
    echo "usage: sh scripts/export-public.sh [<commit-ish>] <target-dir>" >&2
    exit 64
fi

if [ -e "$out" ] && [ -n "$(ls -A "$out" 2>/dev/null)" ]; then
    echo "refusing to export into a non-empty directory: $out" >&2
    exit 65
fi

mkdir -p "$out"
sha="$(git rev-parse --short "$rev")"

# Tracked files only — see (1) above.
git archive "$rev" | tar -x -C "$out"

cd "$out"

# (3) Private operating notes and local agent/IDE config.
rm -rf CLAUDE.md .claude

# (2) Proprietary code, and the sites: a public install starts blank.
sh scripts/deploy-public.sh

# The export is GPL-3.0. The text lives under scripts/ in the private repo so
# nothing there looks as though IT is GPL; it becomes LICENSE here, and the
# source copy goes with it so the export carries exactly one.
if [ ! -f scripts/public-LICENSE ]; then
    echo "scripts/public-LICENSE is missing from $sha — an unlicensed export must not be published." >&2
    exit 66
fi
mv scripts/public-LICENSE LICENSE

echo
echo "Public export of $sha built in: $out"
echo "  files:      $(find . -type f -not -path './.git/*' | wc -l)"
echo "  no sites:   $([ -e src/Site/Service/MarketingSeeder.php ] && echo 'FAIL — seeder present' || echo 'ok')"
echo "  no endpointr: $([ -e src/Translation/EndpointrTranslator.php ] && echo 'FAIL — present' || echo 'ok')"
echo "  starter shell kept: $([ -d templates/shell-default ] && echo 'ok' || echo 'FAIL — a new site cannot build')"
echo "  licence:    $([ -f LICENSE ] && echo 'LICENSE present' || echo 'FAIL — missing')"
echo
echo "Review it, then publish deliberately. Nothing has been pushed."
