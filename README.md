<a href="https://wpsignal.io/wordsocket" target="_blank"><img src="https://wpsignal.io/gh-banner.jpg"></a>

# WordSocket Extensions

Plugins published on WordPress.org that extend [WordSocket](https://github.com/wpsignal/wordsocket). Each one depends on WordSocket (`Requires Plugins: wordsocket`) and renders its settings on WordSocket's settings page under the Extensions tab.

| Plugin | What it does |
|--------|--------------|
| `shopsocket` (ShopSocket, in progress) | A live orders board for your team and live stock on product pages. |

## License

GPL-2.0-or-later

## Releasing

Each plugin releases on its own tag, `<plugin>/vX.Y.Z`. From inside the plugin directory:

```
npm run release -- [--tested <wp-version>] <version> "changelog entry" ...
```

That bumps the plugin, commits, tags, and pushes; the release workflow builds the zip, verifies its version, creates the GitHub release, and publishes to WordPress.org. `bin/dist.sh <plugin>` builds the zip locally for a look; `bin/svn.sh <plugin> --readme-only` pushes a readme change without a release.
