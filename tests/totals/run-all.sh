#!/bin/bash
# Runs every cart totals case file, one group per process, several at a time, and adds up the results.
# Files that share a helper file (name-helpers.php) run together in one group.
#
#   WP="wp-slot wp 10" tests/totals/run-all.sh                     # on test slot 10
#   JOBS=8 WP="wp --path=/path/to/site" tests/totals/run-all.sh    # plain WP-CLI, 8 at a time
#
# SPC_TOTALS_STRIPE=1 is passed through for the Stripe test-mode cases.

cd "$(dirname "$0")" || exit 1
WP="${WP:-wp}"
JOBS="${JOBS:-6}"
OUT="$(mktemp -d)"

groups=()
grouped=" "
for helpers in cases/*-helpers.php; do
	[ -e "$helpers" ] || continue
	prefix="$(basename "$helpers" -helpers.php)"
	members=""
	for f in cases/"$prefix".php cases/"$prefix"-*.php; do
		[ -e "$f" ] || continue
		name="$(basename "$f" .php)"
		members="$members,$name"
		grouped="$grouped$name "
	done
	groups+=("${members#,}")
done
for f in cases/*.php; do
	name="$(basename "$f" .php)"
	case "$grouped" in *" $name "*) continue ;; esac
	groups+=("$name")
done

run_group() {
	SPC_TOTALS_FILES="$1" $WP eval-file wp-content/plugins/sunshine-photo-cart/tests/totals/run.php > "$OUT/${1%%,*}.txt" 2>&1
}
export -f run_group
export WP OUT

printf '%s\n' "${groups[@]}" | xargs -P "$JOBS" -I {} bash -c 'run_group "$@"' _ {}

passed=0; failed=0; skipped=0
for f in "$OUT"/*.txt; do
	line="$(grep -E '^[0-9]+ checks passed' "$f" | tail -1)"
	if [ -z "$line" ]; then
		echo "DID NOT FINISH: $(basename "$f" .txt)"; tail -3 "$f"; failed=$((failed + 1)); continue
	fi
	set -- $line
	passed=$((passed + $1)); failed=$((failed + $4)); skipped=$((skipped + $6))
	grep -E '^FAIL ' "$f"
done
echo "${#groups[@]} groups: $passed checks passed, $failed failed, $skipped tests skipped (full output in $OUT)"
[ "$failed" -eq 0 ]
