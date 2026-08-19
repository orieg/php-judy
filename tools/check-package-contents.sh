#!/bin/sh
# Assert that a built PECL tarball carries no developer tooling and no
# research record -- only what a user installing the extension needs.
#
#   tools/check-package-contents.sh <tarball>
#
# package.xml has always omitted these paths, but only by happenstance:
# nothing asserted it, so a stray <file> entry would have shipped the fuzzer
# or a findings document to every PECL user unnoticed. The tests/ and
# libjudy/ checks in the same CI job assert the opposite direction
# (everything tracked there must ship); this one closes the other end.
#
# The tarball is checked rather than package.xml because the tarball is what
# actually reaches users, and because the CI negative control edits
# package.xml -- a check reading package.xml could be satisfied by an edit
# that pecl then ignores.
set -eu

TGZ=${1:?usage: check-package-contents.sh <tarball>}
[ -f "$TGZ" ] || { echo "no such tarball: $TGZ" >&2; exit 2; }

# Strip the leading "Judy-<version>/" component so the patterns below are
# repo-relative.
ENTRIES=$(mktemp)
trap 'rm -f "$ENTRIES"' EXIT
tar tzf "$TGZ" | sed 's|^[^/]*/||' | grep -v '^$' > "$ENTRIES"

# A listing that came back empty (or came back without the files every
# package must have) would pass every pattern below for the wrong reason.
grep -qx 'config.m4' "$ENTRIES" || {
    echo "ERROR: $TGZ has no config.m4 at its root -- the listing is not a" \
         "package tarball, so this check proves nothing" >&2
    exit 1
}

# Directories whose whole point is that they do not ship. Keep in sync with
# CONTRIBUTING.md's "Where code lives" table.
UNSHIPPED='^(tools|research)/'

if hits=$(grep -E "$UNSHIPPED" "$ENTRIES"); then
    echo "ERROR: the shipped package contains paths that must not ship:" >&2
    printf '%s\n' "$hits" | sed 's/^/  /' >&2
    echo "" >&2
    echo "tools/ is developer tooling and research/ is an evidence record;" >&2
    echo "neither is part of the extension. Remove their <file> entries from" >&2
    echo "package.xml (see CONTRIBUTING.md, 'Where code lives')." >&2
    exit 1
fi

echo "package contents OK: $(wc -l < "$ENTRIES" | tr -d ' ') files, no tools/ or research/ entries"
