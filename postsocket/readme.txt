=== PostSocket ===
Contributors: wpsignal, jaredrethman
Tags: live blog, liveblog, breaking news, realtime, wordsocket
Requires at least: 6.7
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 8.2
Requires Plugins: wordsocket
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live blogs and articles that update in every reader's browser as you publish. Needs WordSocket and a WPSignal account.

== Description ==

PostSocket turns WordPress into a live newsroom. Cover a match, an election night, a product launch or a keynote, and every reader on the page sees each entry the moment you publish it, with no refresh and no polling.

**Requires** the [WordSocket](https://wordpress.org/plugins/wordsocket/) plugin and a [WPSignal](https://wpsignal.io/) account. Install WordSocket, click Connect, then install PostSocket.

**Live blogs**

* Run each live blog from the Live Desk: every entry, its status and its pin in one list that updates as colleagues publish, with the live or ended switch beside it
* Entries are written in the block editor, so any block works: paragraphs, images, embeds, tables, quotes
* Readers see new entries land at the top, edits replace what they were reading, pinned entries stay above the rest, and removed entries leave
* A reader who has scrolled down gets a quiet "3 new updates" pill instead of a jumping page
* A live badge while coverage runs, an ended badge when you close it, older entries on request
* Entries have no pages of their own: each one links to its place on the post the live blog lives on, so your coverage page keeps every reader and every search result
* `LiveBlogPosting` structured data for Google's live coverage features
* Place a live blog on any post or page with the Live Feed block, so the intro, scheduling, revisions and SEO are your normal post's

**Living articles**

Choose post types on the PostSocket tab of WordSocket's settings, switch Live editing on in the editor of the posts you want, and readers of those posts see your saved edits land in place. A post showing a live blog is always live. A correction, a new paragraph or an updated figure appears without a reload, and the parts of the page that did not change, an embedded video for example, are left exactly as they were.

**Built for publishers**

* Every editor works in the normal block editor, several at once, each on their own entry
* Nothing is polled: WordSocket relays each change over a WebSocket to every open page
* Works with classic and block themes, full-page caches and CDNs (a cached page catches up on its own)
* No tables of its own: entries are posts and live blogs are terms, kept and backed up like the rest of your content

**For developers**

Events `postsocket.entry.updated`, `postsocket.entry.removed` and `postsocket.liveblog.state` travel on `postsocket:liveblog:{id}`; `postsocket.post.updated` on `postsocket:post:{id}`. `{id}` on a live blog's channel is its term id in the `postsocket_liveblog` taxonomy. Filters: `postsocket_host_post_type`, `postsocket_is_living`, `postsocket_inline_limit`. Routes under `postsocket/v1`.

WPSignal is an independent service and is not affiliated with Automattic or WordPress.com.

== Installation ==

1. Install and activate [WordSocket](https://wordpress.org/plugins/wordsocket/) and connect it to your WPSignal account (Settings > WordSocket > Connect).
2. Install and activate PostSocket.
3. Open Live Blogs, click New live blog, then Create page to place it on a new post (or add the Live Feed block to any post) and publish.
4. Add entries from the desk with Add entry; each one reaches readers the moment you publish it.
5. To make ordinary posts update live too, choose their post types on Settings > WordSocket > PostSocket.

== Frequently Asked Questions ==

= Do I need WordSocket and a WPSignal account? =

Yes. WordSocket connects your site to the WPSignal relay, which carries each change to your readers. The WordSocket plugin and a WPSignal account are both needed; PostSocket adds the live blog and living article features on top.

= Can several people post at once? =

Yes. Every entry is its own post in the block editor, so your team works side by side without taking turns on one document.

= Do entries show up on my home page or in search? =

No. Entries are not public posts: they never appear in your loops, archives, feeds, search or sitemaps. Each entry links to its place on the live blog page.

= Does it work with my caching plugin or CDN? =

Yes. A page served from a cache catches up on its own once it connects, and from then on it hears every change live. Content fetched after a large edit is cacheable too, so a crowd of readers does not hit your server at once.

= Which post types can be living articles? =

Any public post type. A post showing a live blog is always live; everything else is off until you enable its post type under Settings > WordSocket > PostSocket and switch Live editing on in the post's editor.

= A custom field changed and the page did not update. Why? =

A living article updates its content and title, including anything in the content that reads a custom field. For a custom field your theme prints outside the content, tell PostSocket where it is with the `postsocket_live_fields` filter: `$fields['subtitle'] = array( 'selector' => '.entry-subtitle' );`. Without a selector, PostSocket fills every element marked `data-postsocket-field="subtitle"`. A `render` callback returns the HTML when the value needs formatting. The `postsocket_live_taxonomies` filter does the same for a post's terms: `$taxonomies['category'] = array( 'selector' => '.cat-links' );`. As a catch-all for whatever those do not cover, the `postsocket_refresh_selector` filter names a part of the page, such as `.site-main`, that readers' browsers fetch again and update in place. For a change that brings its own stylesheets or scripts, such as opening comments, name the meta key or post column in `postsocket_reload_live_fields`, or the taxonomy in `postsocket_reload_live_taxonomies`, and readers' pages reload. To have a change ignored altogether, name the meta key in `postsocket_exclude_live_fields` or the taxonomy in `postsocket_exclude_live_taxonomies`.

= What about posts built with a page builder? =

Posts built with Elementor or Divi are left alone, since their markup is not block content.

= Where is my data stored? =

Live blogs and entries are ordinary posts in your WordPress database. Nothing is stored on WPSignal's servers beyond the moment it takes to relay a change.

== Screenshots ==

1. A live blog as readers see it: the live badge, pinned entry and the feed.
2. An entry in the block editor with the Live blog panel.
3. The Live coverage panel on a live blog.
4. The PostSocket tab on WordSocket's settings.

== Changelog ==

= 0.1.0 =
* First release: live blogs run from the Live Desk with entries written in the block editor, placed on any post with the Live Feed block, living articles for the post types you choose, `LiveBlogPosting` structured data, and the PostSocket settings tab.

== Upgrade Notice ==

= 0.1.0 =
First release.
