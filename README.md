# Salvage

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/ernestdefoe/salvage.svg)](https://packagist.org/packages/ernestdefoe/salvage)

A [Flarum 2](https://flarum.org) extension against link rot. Pictures hotlinked from other sites are copied to your own storage while they still exist, so old posts keep their images. Links across the forum are checked in the background, and the dead ones are listed for staff to replace, unlink, or point at their archive.org copy in one click.

![Broken links in the admin panel](https://raw.githubusercontent.com/ernestdefoe/salvage/main/.github/screenshots/broken.jpg)

## Features

- **Hotlinked images are kept.** Every image posted from another site is copied once and the post is pointed at the copy. Copies are made from the bytes, not the address: only JPEG, PNG, GIF (animation kept) and WebP are accepted, every copy is decoded and encoded again (EXIF and GPS data, comments and anything hidden in the file are dropped), and SVG is never copied.
- **Works with FoF Upload.** When [FoF Upload](https://github.com/FriendsOfFlarum/upload) is on and the poster may upload, copies are stored wherever FoF Upload keeps files (local, S3, and so on) and show in the poster's media manager. Otherwise they go to `assets/salvage`, a normal Flarum filesystem disk you can point elsewhere.
- **Dead links are found, carefully.** Each link is asked with HEAD, then GET when HEAD says no. A link is only called broken after a 404, 410 or a vanished domain on two checks a day apart, or after four network failures spread over about four days. Sites that block checkers (401, 403, 429, LinkedIn's 999) count as working, since a person clicking gets through. Working links are checked again every 30 days (a setting); broken ones weekly, in case they come back.
- **Fix it everywhere at once.** For each broken link: **Replace…** with a new address, **Use archive.org copy**, **Unlink** (the text stays, the link goes), **Ignore**, or **Check again**. Every post holding the link changes together, and **Undo** puts the original back (an unlink is undone by editing the post).
- **archive.org copies.** When a link breaks, Salvage asks the Wayback Machine for its closest copy. You can let it use the copy automatically.
- **Your posts stay as written.** Only the address inside a link or image changes; nothing is re-parsed, so mentions, formatting and other extensions' markup are untouched, and posts are not marked as edited. A post edited later keeps the decision.
- **Optional moderator flags.** With Flags on, posts with a newly broken link can go to the flag queue, one flag per post naming its dead links. At most 100 posts per round, and the flag comes down once staff deal with the link.
- **Scan what is already there.** New and edited posts are tracked as they are saved. One button scans the rest, 500 posts at a time, in the background.

![Mirrored images](https://raw.githubusercontent.com/ernestdefoe/salvage/main/.github/screenshots/mirrored.jpg)

![Replacing a link](https://raw.githubusercontent.com/ernestdefoe/salvage/main/.github/screenshots/replace.jpg)

![A flagged post](https://raw.githubusercontent.com/ernestdefoe/salvage/main/.github/screenshots/flag.jpg)

## Light on your server, polite to others

- **Nothing happens in a page view.** All fetching runs in Flarum's scheduler, every five minutes, through the queue (a worker if you run one, the scheduler's own process otherwise). Saving a post adds a few database queries only when it contains links; pages and the API run exactly the same queries with Salvage on or off.
- **Every round is bounded:** one round at a time, about 50 seconds, 40 requests (a setting), never more than 3 requests to any one site, and a site that times out is left alone for the rest of the round. Failing links back off for hours, then days. archive.org is left alone for an hour (or its Retry-After) when it asks.
- **Capped storage.** Images over 5 MB (a setting) or over 16 megapixels are not copied, and at most 500 MB a day is mirrored (a setting), so a flood of hotlinked pictures cannot fill the disk.
- **The forum never fetches its own network.** Only http and https, on ports 80, 443, 8080 and 8443, with no usernames in the address. Every address a name resolves to must be public: private, loopback, link-local, CGNAT, cloud-metadata, NAT64, 6to4 and IPv4-mapped addresses are refused, and so are numbers spelled as hostnames (`2130706433`, `0x7f.1`, `0177.0.0.1`). The connection is pinned to the address that was checked, proxies from the environment are ignored, and every redirect goes through all of it again.

![On a phone](https://raw.githubusercontent.com/ernestdefoe/salvage/main/.github/screenshots/phone.jpg)

## Requirements

- Flarum 2.0 and PHP 8.3 or newer, with the curl and dom extensions.
- **Flarum's scheduler must run.** Add the cron line from [Flarum's documentation](https://docs.flarum.org/scheduler). The admin page warns when the scheduler has not run.

## Installation

```sh
composer require ernestdefoe/salvage
php flarum migrate
php flarum cache:clear
```

Then enable **Salvage** in the admin panel and press **Scan existing posts** once.

## Updating

```sh
composer update ernestdefoe/salvage
php flarum migrate
php flarum cache:clear
```

## Good to know

- `php flarum salvage:work --now` runs one round right away and prints what it did.
- Uninstalling leaves the copies in place, and posts keep pointing at them, so no pictures are lost.
- Use **Never check or mirror** for sites you trust to stay up, or that block checkers.

## Links

- [Source on GitHub](https://github.com/ernestdefoe/salvage)
- [Packagist](https://packagist.org/packages/ernestdefoe/salvage)

☕ If you like the things I have built, consider [buying me a coffee](https://buymeacoffee.com/ernestdefoe).
