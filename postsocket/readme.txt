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

* A Live Blog is a post with an intro; its entries are posts of their own, written in the block editor, so any block works: paragraphs, images, embeds, tables, quotes
* Readers see new entries land at the top, edits replace what they were reading, pinned entries stay above the rest, and removed entries leave
* A reader who has scrolled down gets a quiet "3 new updates" pill instead of a jumping page
* A live badge while coverage runs, an ended badge when you close it, older entries on request
* Entries have no pages of their own: each one links to its place on the live blog, so your coverage page keeps every reader and every search result
* `LiveBlogPosting` structured data for Google's live coverage features
* The feed is added after the intro for you, or place the Live Feed block where you want it

**Living articles**

Choose post types on the PostSocket tab of WordSocket's settings, and readers of those posts see your saved edits land in place. A correction, a new paragraph or an updated figure appears without a reload, and the parts of the page that did not change, an embedded video for example, are left exactly as they were.

**Built for publishers**

* Every editor works in the normal block editor, several at once, each on their own entry
* Nothing is polled: WordSocket relays each change over a WebSocket to every open page
* Works with classic and block themes, full-page caches and CDNs (a cached page catches up on its own)
* No tables of its own: live blogs and entries are posts, kept and backed up like the rest of your content

**For developers**

Events `postsocket.entry.updated`, `postsocket.entry.removed` and `postsocket.liveblog.state` travel on `postsocket:liveblog:{id}`; `postsocket.post.updated` on `postsocket:post:{id}`. Filters: `postsocket_rewrite_slug`, `postsocket_is_living`, `postsocket_inline_limit`. Routes under `postsocket/v1`.

WPSignal is an independent service and is not affiliated with Automattic or WordPress.com.

== Installation ==

1. Install and activate [WordSocket](https://wordpress.org/plugins/wordsocket/) and connect it to your WPSignal account (Settings > WordSocket > Connect).
2. Install and activate PostSocket.
3. Add a live blog under Live Blogs > Add New, publish it, then add entries from the Live coverage panel or Live Blogs > Entries.
4. To make ordinary posts update live too, choose their post types on Settings > WordSocket > PostSocket.

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

Any public post type. Live blogs are always live; everything else is off until you switch it on under Settings > WordSocket > PostSocket.

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
* First release: live blogs with entries written in the block editor, living articles for the post types you choose, the Live Feed block, `LiveBlogPosting` structured data, and the PostSocket settings tab.

== Upgrade Notice ==

= 0.1.0 =
First release.
