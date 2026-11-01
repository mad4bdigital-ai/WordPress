"""Source policy for a narrowly scoped, no-cache, server-rendered JetBlog first paint."""
from pathlib import Path

root = Path(__file__).resolve().parents[4]
source = root / "wp-content/mu-plugins/325-mad4b-jetblog-mobile-server-first-paint.php"
s = source.read_text()
must = [
 "wp_get_environment_type()!=='staging'",
 "tours.dev.mad4b.com",
 "e5e64bca-ed62-48d2-b2b3-dff693cd702a",
 "is_front_page()", "is_preview()", "is_user_logged_in()", "wp_doing_ajax()",
 "HTTP_COOKIE", "HTTP_AUTHORIZATION", "HTTP_X_MAD4B_CURRENCY",
 "HTTP_X_GEO_COUNTRY", "HTTP_CF_IPCOUNTRY", "HTTP_X_FORWARDED_GEO_COUNTRY",
 "HTTP_X_MAD4B_JETBLOG_FIRST_PAINT_CONTROL",
 "get_woocommerce_currency()!=='USD'",
 "jet_blog()->get_version()!=='2.4.10.1'",
 "elementor/frontend/after_enqueue_scripts",
 "wp_script_is('jet-blog','enqueued')",
 "wp_add_inline_script('jet-blog',$script,'before')",
 "b44fd5b", "1330604", "jet-blog-smart-list/init.mad4b-first-paint",
 "opts['posts_columns_'+k]", "opts['posts_rows_'+k]",
 "$wrap.find('.jet-smart-listing').children().length",
 "$wrap.addClass('jet-processing')", "Promise.resolve().then",
 "$wrap.removeClass('jet-processing')",
 # Italian empty-post optimization remains content-aware, not a permanent disable.
 "$locale==='it'",
 "'post_type'=>'post'",
 "'post_status'=>'publish'",
 "'posts_per_page'=>1",
 "'suppress_filters'=>false",
 "$skip_empty_italian=empty($posts->posts)",
 "wp_reset_postdata()",
 "mad4bJetBlogEmptyItalianPostGuard",
 "opts.post_type",
 "t.length!==1",
 "opts.use_custom_query",
 "opts.custom_query",
 "data-mad4b-empty-italian-posts",
 "hasRenderedPosts",
]
for token in must:
 assert token in s, token
for locale in ["en", "fr", "de", "ar", "es", "it"]:
 assert f"'{locale}'" in s
for disallowed in ["wp_cache_set", "set_transient(", "update_option(", "delete_option(", "wp_send_json", "wp_ajax_nopriv"]:
 assert disallowed not in s, disallowed
print("PASS: site/source pins, anonymous-only, price/session/FX bypass, native fallback and later JetBlog events preserved")
