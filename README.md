# Social Feed Block for OJS 3.5

A sidebar block that shows the latest posts of a **Mastodon** or **Bluesky** account on your journal's pages. It also works with a Mastodon hashtag and with a Bluesky custom feed.

It is the successor of the [Twitter Block](https://github.com/RBoelter/twitterBlock), whose timeline widget no longer renders for visitors who are not logged in to X.

## What makes it different

Your **server** fetches the posts, caches them and renders plain HTML. Your visitors' browsers never contact Mastodon or Bluesky:

- no third-party script, image, frame or font is loaded,
- no cookie is set,
- no visitor address reaches the social network.

The block is also safe to leave on: a slow or unreachable network never slows your pages, because a copy of the last posts is shown instead, and after a failure the network is left alone for a few minutes.

## Requirements

- OJS 3.5 (this plugin has no 3.4 version) and PHP 8.2 or newer
- your server must be able to make outgoing HTTPS requests to the network you choose

## Installation

Install the plugin from the plugin gallery (`Settings > Website > Plugins > Plugin Gallery`), or download the release and upload it under `Settings > Website > Plugins > Upload A New Plugin`.

## Set up

1. Enable **Social Feed Block** under `Settings > Website > Plugins`.
2. Open its **Settings**, choose the network and fill in the source (see below), then save. The plugin loads the feed once and tells you at once if the source does not work.
3. Under `Settings > Website > Appearance > Setup`, activate the block in the sidebar and drag it to the position you want.

### Settings

| Setting | Meaning |
|---|---|
| Block title | The heading of the block. Empty uses "Latest posts". |
| Network | Mastodon or Bluesky. Only the fields of the chosen network are shown. |
| Mastodon server | The host name of the server, for example `mastodon.social`. No `https://`. |
| Mastodon: show | The posts of an account, or public posts with a hashtag. |
| Account or hashtag | An account name on that server without the server part, for example `PublicKnowledgeProject`, or a hashtag without `#`. |
| Bluesky: show | The posts of an account, or a custom feed. |
| Account or feed | For an account, its handle (`pkp.sfu.ca`) or DID. For a custom feed, the feed's `at://` address, which contains the DID of its creator. |
| Number of posts | 1 to 20, default 5. |
| Hide replies, hide reposts and boosts | Leave those out. |
| Refresh interval | How long fetched posts are kept before the network is asked again: 5 to 1440 minutes, default 15. |

## What is shown, and what is not

Shown: the author's name and handle, the text with its links, the date, the counts of replies, reposts and likes, and a link to the original post.

Not shown, on purpose: avatars, images, videos and link previews. Showing them would make your visitors' browsers load files from the social network. A post with media says so and links to the original.

Respected: the text of a Mastodon post behind a content warning is not shown, only the warning. Bluesky posts labelled as adult or graphic content, and posts of accounts that asked not to be shown to visitors who are not logged in, are left out.

## Limits

- **Mastodon servers can switch off public access** to their posts. The settings then report "The server does not allow public access to its posts". Use another server's account or ask the operator.
- **Bluesky has no hashtag source here:** its public API refuses searches without a login.
- Mastodon allows 300 requests per five minutes per address. With the default refresh interval that is far from being reached.
- The block shows what other people posted. Prefer your journal's own account over a hashtag, and keep an eye on it.
- Your server contacts the social network, and the request comes from your server's address. Take that into account in your privacy notice if you describe which services your site talks to.
- Your server also contacts the Mastodon host that is entered in the settings, which only administrators and journal managers can change. The plugin refuses IP addresses, `localhost`, paths and ports and only talks HTTPS, but a name that resolves to an internal address is not blocked. Do not give the settings to people you do not trust.

## Troubleshooting

- **The block is not shown:** the source is not configured, the network cannot be reached and there is no earlier copy, or the source has no posts. Save the settings again: the message tells the reason.
- **Reasons are also written to the server's error log**, on lines that start with `socialFeedBlock:`.
- **Saving the settings loads the feed again**, so a change shows at once.

## Moving from Twitter Block

There is no upgrade path. Install this plugin, configure it, activate its block in the sidebar and remove the old plugin.

## Development

The plugin follows PKP's coding standard (`lib/pkp/.php_cs_rules`, PSR-12 based). In an OJS 3.5 checkout with the plugin at `plugins/blocks/socialFeedBlock`:

```
# code style
php lib/pkp/lib/vendor/bin/php-cs-fixer fix --dry-run --diff --config=plugins/blocks/socialFeedBlock/.php-cs-fixer.php

# unit tests (no OJS boot, no network)
cd plugins/blocks/socialFeedBlock && php ../../../lib/pkp/lib/vendor/bin/phpunit

# end-to-end tests (need network access to mastodon.social and bsky.app)
npx cypress run --config specPattern="plugins/blocks/socialFeedBlock/cypress/tests/functional/*.cy.js"
```

## License

GPL-3.0, see `LICENSE`.
