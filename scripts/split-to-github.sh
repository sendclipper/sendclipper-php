#!/usr/bin/env bash
# Publish sdks/php to its public GitHub repository.
#
# The monorepo (GitLab, private) is the source of truth. This pushes ONLY the
# sdks/php subtree, with its history, to github.com/sendclipper/sendclipper-php.
# Never push the monorepo itself there.
#
#   sdks/php/scripts/split-to-github.sh            # push main
#   sdks/php/scripts/split-to-github.sh v0.1.0     # push main and tag v0.1.0
#
# Packagist reads versions from the repository's tags: once the package is
# submitted on packagist.org, pushing a tag IS the release (through the
# GitHub integration's webhook, or Packagist's own polling).
#
# REMOTE defaults to the `github-sendclipper` SSH alias (~/.ssh/config), which
# authenticates as the sendclipper GitHub account.
set -euo pipefail

REMOTE="${REMOTE:-git@github-sendclipper:sendclipper/sendclipper-php.git}"
TAG="${1:-}"
ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

if [ -n "$(git status --porcelain -- sdks/php)" ]; then
  echo "sdks/php has uncommitted changes; commit them first." >&2
  exit 1
fi

if [ -n "$TAG" ]; then
  version="$(sed -n "s/.*public const VERSION = '\(.*\)';/\1/p" sdks/php/src/Version.php)"
  if [ "v$version" != "$TAG" ]; then
    echo "tag $TAG does not match Version::VERSION $version" >&2
    exit 1
  fi
fi

SPLIT="$(git subtree split --prefix=sdks/php HEAD 2>/dev/null)"
echo "split commit: $SPLIT"
git push "$REMOTE" "$SPLIT:refs/heads/main"

if [ -n "$TAG" ]; then
  git push "$REMOTE" "$SPLIT:refs/tags/$TAG"
fi
