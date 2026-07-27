#!/usr/bin/env bash
#
# Pre-ship checks for TCT plugins. Run this before building a zip for the live site.
#
#   ./scripts/smoke-test.sh
#
# Checks, in order of how badly they bite:
#   1. PHP syntax on every file
#   2. Capability sanity — the v1.0.0 bug that broke manage_options site-wide
#   3. Post type registers and its admin screen is reachable
#   4. Blocks register and render
#   5. No PHP warnings/errors in the debug log
#
set -uo pipefail

eval "$(/opt/homebrew/bin/brew shellenv)"

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCAL_WP="${TCT_LOCAL_WP:-/Users/teecorley/Sites/tct-local}"
PLUGIN_SLUG="tct-reader-kit"

fails=0
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; fails=$((fails+1)); }
head() { printf '\n\033[1m%s\033[0m\n' "$1"; }

wpc() { php -d memory_limit=512M /opt/homebrew/bin/wp --path="$LOCAL_WP" "$@" 2>/dev/null | grep -v '^Deprecated' ; }

# ---------------------------------------------------------------- 1. syntax
head "PHP syntax"
while IFS= read -r f; do
	if php -l "$f" >/dev/null 2>&1; then
		pass "$(basename "$f")"
	else
		fail "$(basename "$f")"
		php -l "$f" 2>&1 | sed 's/^/        /'
	fi
done < <(find "$REPO/$PLUGIN_SLUG" -name '*.php')

# ------------------------------------------------------- 2. local WP present
if [ ! -f "$LOCAL_WP/wp-load.php" ]; then
	head "Local WordPress"
	fail "not found at $LOCAL_WP — skipping runtime checks"
	printf '\nSyntax-only run. %s failure(s).\n' "$fails"
	exit $(( fails > 0 ))
fi

head "Sync plugin into local WordPress"
rsync -a --delete "$REPO/$PLUGIN_SLUG/" "$LOCAL_WP/wp-content/plugins/$PLUGIN_SLUG/" \
	&& pass "synced to local site" || fail "sync failed"
wpc plugin activate "$PLUGIN_SLUG" >/dev/null && pass "plugin active" || fail "activation failed"

# --------------------------------------------------------- 3. capabilities
# This is the regression that took down the live site: mapping a post type's
# meta capabilities (edit_post/read_post/delete_post) to manage_options registers
# manage_options itself as a meta cap, so current_user_can('manage_options')
# returns false everywhere and the Settings menu silently disappears.
head "Capabilities (the v1.0.0 regression)"
CAPS="$(wpc eval '
$u = get_users(array("role"=>"administrator","number"=>1));
wp_set_current_user($u[0]->ID);
$pt = get_post_type_object("tct_subscriber");
echo "registered=" . ($pt ? 1 : 0) . ";";
echo "meta_poisoned=" . (isset($GLOBALS["post_type_meta_caps"]["manage_options"]) ? 1 : 0) . ";";
echo "can_manage_options=" . (current_user_can("manage_options") ? 1 : 0) . ";";
echo "can_list_screen=" . (($pt && current_user_can($pt->cap->edit_posts)) ? 1 : 0) . ";";
echo "create_blocked=" . (($pt && "do_not_allow" === $pt->cap->create_posts) ? 1 : 0) . ";";
')"
get() { echo "$CAPS" | tr ';' '\n' | grep "^$1=" | cut -d= -f2; }

[ "$(get registered)"          = 1 ] && pass "post type registers"                  || fail "post type did not register"
[ "$(get meta_poisoned)"       = 0 ] && pass "manage_options NOT hijacked as a meta cap" || fail "manage_options registered as a meta cap — WILL BREAK THE SITE"
[ "$(get can_manage_options)"  = 1 ] && pass "admin still has manage_options at runtime"  || fail "manage_options broken at runtime — Settings menu will vanish"
[ "$(get can_list_screen)"     = 1 ] && pass "admin can reach the subscriber screen"  || fail "admin gets 403 on the subscriber screen"
[ "$(get create_blocked)"      = 1 ] && pass "manual subscriber creation blocked"     || fail "create_posts is not blocked"

# --------------------------------------------------------------- 4. blocks
head "Blocks"
BLK="$(wpc eval '
$r = WP_Block_Type_Registry::get_instance();
foreach (array("tct/table-of-contents","tct/newsletter") as $n) {
	echo $n . "=" . ($r->is_registered($n) ? 1 : 0) . ";";
}
$post_id = wp_insert_post(array("post_title"=>"smoke","post_status"=>"publish","post_content"=>"<h2>Alpha</h2><p>x</p><h2>Beta</h2><p>y</p><h3>Gamma</h3>"));
global $post; $post = get_post($post_id); setup_postdata($post);
// tct_rk_inject_heading_ids() deliberately no-ops unless this is a singular request
// for the queried post. WP-CLI has no query context, so fake one — otherwise the
// anchors legitimately never get injected and every TOC link looks dangling.
global $wp_query;
$wp_query->is_singular       = true;
$wp_query->is_single         = true;
$wp_query->queried_object    = $post;
$wp_query->queried_object_id = $post_id;
$toc = tct_rk_render_toc(array("heading"=>"Contents","maxLevel"=>3));
$news = tct_rk_render_newsletter(array("heading"=>"Join"));
preg_match_all("/href=\"#([^\"]+)\"/", $toc, $m);
$filtered = tct_rk_inject_heading_ids($post->post_content);
$dangling = 0;
foreach ($m[1] as $slug) { if (false === strpos($filtered, "id=\"" . $slug . "\"")) { $dangling++; } }
echo "toc_links=" . count($m[1]) . ";dangling=" . $dangling . ";";
echo "form_present=" . (false !== strpos($news, "tct_rk_signup") ? 1 : 0) . ";";
echo "honeypot_present=" . (false !== strpos($news, "tct_website") ? 1 : 0) . ";";
wp_delete_post($post_id, true);
')"
getb() { echo "$BLK" | tr ';' '\n' | grep "^$1=" | cut -d= -f2; }

[ "$(getb 'tct/table-of-contents')" = 1 ] && pass "TOC block registered"      || fail "TOC block not registered"
[ "$(getb 'tct/newsletter')"        = 1 ] && pass "newsletter block registered" || fail "newsletter block not registered"
[ "$(getb toc_links)" -ge 3 ] 2>/dev/null && pass "TOC built $(getb toc_links) links" || fail "TOC produced too few links"
[ "$(getb dangling)"     = 0 ] && pass "no dangling anchors"            || fail "$(getb dangling) dangling anchor(s)"
[ "$(getb form_present)" = 1 ] && pass "signup form renders"             || fail "signup form missing"
[ "$(getb honeypot_present)" = 1 ] && pass "honeypot present"            || fail "honeypot missing"

# ------------------------------------------------------------ 5. debug log
head "PHP debug log"
LOG="$LOCAL_WP/wp-content/debug.log"
if [ -f "$LOG" ]; then
	# No `|| echo 0` here: grep -c already prints 0 when there are no matches and
	# exits 1, so the fallback would append a second line and break the comparison.
	RECENT="$(grep -c "$PLUGIN_SLUG" "$LOG" 2>/dev/null)"
	RECENT="${RECENT:-0}"
	[ "$RECENT" -eq 0 ] && pass "no plugin entries in debug.log" || fail "$RECENT plugin entries in debug.log (see $LOG)"
else
	pass "no debug.log written"
fi

printf '\n────────────────────────────────\n'
if [ "$fails" -eq 0 ]; then
	printf '\033[32mALL CHECKS PASSED\033[0m — safe to build a zip.\n'
else
	printf '\033[31m%s CHECK(S) FAILED\033[0m — do not ship.\n' "$fails"
fi
exit $(( fails > 0 ))
