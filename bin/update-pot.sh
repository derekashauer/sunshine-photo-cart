#!/usr/bin/env bash
#
# update-pot.sh — Regenerate translation strings for Sunshine Photo Cart.
#
# Sunshine keeps ALL translations centralized in the core plugin (one text
# domain, `sunshine-photo-cart`, covering core + every addon) and self-hosts
# them, because WordPress.org forbids automated translation. This script is the
# deterministic half of that workflow — it does NOT translate anything. The
# translation of new/changed strings is handled separately by the
# /update-translations skill (Claude), which runs between steps 2 and 4 below.
#
# Pipeline:
#   1. make-pot  — extract strings from core + all addons into the master .pot
#   2. msgmerge  — merge the .pot into each locale .po (keeps translations,
#                  flags changed strings fuzzy, adds new strings empty)
#   3. (AI translation pass — external, fills empty + reviews fuzzy)
#   4. compile   — strip fuzzy, then build fuzzy-free .mo and .l10n.php
#
# Tools: wp-cli (make-pot, make-php) + gettext (msgmerge, msgattrib, msgfmt).
# gettext is used for the merge/compile steps because it does fuzzy matching
# and fuzzy exclusion, which `wp i18n update-po`/`make-mo` do not.
#
# Usage:
#   bash bin/update-pot.sh              # uses `wp` from PATH
#   WP_CLI=spc bash bin/update-pot.sh   # route wp-cli through a wrapper
#
# The /update-translations skill runs this in two phases around its AI pass:
#   bash bin/update-pot.sh --no-compile     # steps 1-2: extract + merge
#   ...AI fills empty msgstr / reviews fuzzy...
#   bash bin/update-pot.sh --compile-only    # step 4: build fuzzy-free .mo/.l10n.php

set -euo pipefail

# --- Paths -------------------------------------------------------------------
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"   # .../sunshine-photo-cart
PLUGINS="$(dirname "$PLUGIN_DIR")"                              # .../wp-content/plugins
LANGS="$PLUGIN_DIR/languages"
POT="$LANGS/sunshine-photo-cart.pot"
DOMAIN="sunshine-photo-cart"

WP_CLI="${WP_CLI:-wp}"
EXTRACT=1   # steps 1-2: make-pot + msgmerge
COMPILE=1   # step 4:   fuzzy-free .mo + .l10n.php
case "${1:-}" in
	--no-compile)   COMPILE=0 ;;
	--compile-only) EXTRACT=0 ;;
	"")             ;;
	*) echo "Unknown option: $1 (use --no-compile or --compile-only)" >&2; exit 2 ;;
esac

# --- Plugins to scan ---------------------------------------------------------
# Index 0 MUST be the core plugin (extracted first, fresh). The rest are merged
# in. This is the same set Poedit scanned (its X-Poedit-SearchPath-* headers).
# Add a new addon here when it ships user-facing strings.
SLUGS=(
	sunshine-photo-cart
	sunshine-advanced-shipping
	sunshine-analytics
	sunshine-authorizenet
	sunshine-automated-email-marketing
	sunshine-bulk-galleries
	sunshine-campaign-monitor
	sunshine-digital-downloads
	sunshine-discounts
	sunshine-exports
	sunshine-light-blue
	sunshine-lightbox
	sunshine-mailchimp
	sunshine-messaging
	sunshine-minimum-order
	sunshine-mollie
	sunshine-multi-image-products
	sunshine-packages
	sunshine-price-levels
	sunshine-price-list
	sunshine-product-options
	sunshine-quantity-discounts
	sunshine-square
	sunshine-stripe
	sunshine-mercado-pago
	sunshine-payfast
	sunshine-paystack
	sunshine-quickpay
	sunshine-sell-anything
	sunshine-session-fees
	sunshine-cloud-storage
	sunshine-video-sales
	sunshine-gift-cards
	sunshine-whcc
	sunshine-api
)

# --- Dependency checks (fail loud) ------------------------------------------
missing=()
$WP_CLI --version >/dev/null 2>&1 || missing+=("wp-cli (set WP_CLI=... if it is not named 'wp')")
for bin in msgmerge msgattrib msgfmt; do
	command -v "$bin" >/dev/null 2>&1 || missing+=("$bin")
done
if [ ${#missing[@]} -gt 0 ]; then
	echo "ERROR: missing required tools:" >&2
	printf '  - %s\n' "${missing[@]}" >&2
	echo "Install gettext with:  brew install gettext && brew link --force gettext" >&2
	exit 1
fi

echo "Plugin:    $PLUGIN_DIR"
echo "Languages: $LANGS"
echo

# --- Resolve locale .po files (needed by both extract and compile) -----------
shopt -s nullglob
po_files=( "$LANGS"/sunshine-photo-cart-*.po )
if [ ${#po_files[@]} -eq 0 ]; then
	echo "ERROR: no locale .po files found in $LANGS" >&2
	exit 1
fi

if [ "$EXTRACT" -eq 1 ]; then
# --- Step 1: extract master .pot from core + all addons ----------------------
echo "==> Step 1: extracting strings (make-pot) over ${#SLUGS[@]} plugins"
for i in "${!SLUGS[@]}"; do
	slug="${SLUGS[$i]}"
	src="$PLUGINS/$slug"
	if [ ! -d "$src" ]; then
		echo "    ! skipping missing plugin dir: $slug" >&2
		continue
	fi
	if [ "$i" -eq 0 ]; then
		# Core: fresh extraction (overwrites the .pot).
		$WP_CLI i18n make-pot "$src" "$POT" \
			--domain="$DOMAIN" --slug="$DOMAIN" \
			--exclude=vendor,node_modules,bin --skip-audit >/dev/null
	else
		# Addons: merge into the accumulating .pot (union of strings).
		$WP_CLI i18n make-pot "$src" "$POT" \
			--domain="$DOMAIN" --merge \
			--exclude=vendor,node_modules --skip-audit >/dev/null
	fi
done
echo "    .pot strings: $(grep -c '^msgid ' "$POT")"
echo

# --- Step 2: merge .pot into each locale .po (fuzzy matching on) --------------
echo "==> Step 2: merging into locale .po files (msgmerge)"
for po in "${po_files[@]}"; do
	# --backup=none: no .po~ files. Default wrapping (matches existing files).
	msgmerge --quiet --update --backup=none "$po" "$POT"
done
echo "    merged ${#po_files[@]} locales"
echo
fi  # EXTRACT

if [ "$COMPILE" -eq 0 ]; then
	echo "==> --no-compile: stopping before .mo/.l10n.php."
	echo "    Run the /update-translations AI pass, then: bash bin/update-pot.sh --compile-only"
	exit 0
fi

# --- Step 4: compile fuzzy-free .mo + .l10n.php ------------------------------
# wp i18n make-mo/make-php INCLUDE fuzzy translations; msgfmt does not. So we
# strip fuzzy/obsolete into a temp copy and compile that. The master .po files
# keep their fuzzy entries as the review queue. Anything still fuzzy ships as
# English (untranslated) rather than as an unverified translation.
echo "==> Step 4: compiling fuzzy-free .mo + .l10n.php"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
for po in "${po_files[@]}"; do
	base="$(basename "$po" .po)"
	msgattrib --no-fuzzy --no-obsolete "$po" -o "$TMP/$base.po"
	msgfmt -o "$LANGS/$base.mo" "$TMP/$base.po"
done
$WP_CLI i18n make-php "$TMP/" "$LANGS/" >/dev/null
echo "    built $(ls -1 "$LANGS"/*.mo | wc -l | tr -d ' ') .mo and $(ls -1 "$LANGS"/*.l10n.php | wc -l | tr -d ' ') .l10n.php files"
echo

# --- Summary: per-locale translation status ----------------------------------
echo "==> Translation status (translated / fuzzy / untranslated):"
for po in "${po_files[@]}"; do
	stats="$(msgfmt --statistics -o /dev/null "$po" 2>&1)"
	printf '    %-40s %s\n' "$(basename "$po")" "$stats"
done
echo
echo "Done. Review the diff, run the /update-translations AI pass for any fuzzy/untranslated"
echo "entries, then re-run to recompile before committing."
