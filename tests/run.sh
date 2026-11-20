#!/usr/bin/env bash
# Run one test script, several, or all of them, against the local Lando site.
#
#     tests/run.sh                      every tests/*.php
#     tests/run.sh gif-optimise cli     just those
#
# Exits non-zero when any script reports a failure. WooCommerce's early
# textdomain notice is dropped from the output; it is not ours.

set -o pipefail

plugin="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$plugin/../../.." && pwd)"
lando="${LANDO:-$HOME/.lando/bin/lando}"

if [ $# -eq 0 ]; then
	set -- $(cd "$plugin/tests" && ls *.php | grep -v '^bootstrap\.php$' | sed 's/\.php$//')
fi

status=0

for name in "$@"; do
	echo "== $name"
	( cd "$root" && "$lando" wp eval-file "wp-content/plugins/$(basename "$plugin")/tests/$name.php" 2>&1 ) | grep -v textdomain || status=1
done

exit $status
