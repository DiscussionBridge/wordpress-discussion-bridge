[discotoc]

This article begins on **The Bridge** and is presented by the live stock WordPress demo. The forum remains authoritative for the source post, discussion, revisions, moderation, and replies.

## Start with a forum-owned source

DiscussionBridge identifies this exact topic and creates a presentation record for the WordPress Demo connection. WordPress receives only the connection-authorized record.

## Present safely in WordPress

The WordPress plugin retrieves the record on the server, validates its identity and topic URL, sanitizes the bounded cooked HTML, and briefly caches the presentation. The Bridge credential never enters the page or browser.

## Preserve portable structure

| The Bridge supplies | WordPress supplies |
| --- | --- |
| Authoritative source post | Stock publishing presentation |
| Revisions and moderation | Page navigation and theme |
| Replies and reader sessions | A canonical presentation destination |

![DiscussionBridge content flow](https://bridge.demo.discussionbridge.dev/uploads/default/original/1X/a10528a4105e50ef28db187db3d34dd7dcbba904.svg)

## Keep one discussion

```json
{"direction":"from_discourse","presentation":"wordpress","discussion":"same_topic"}
```

The imported first post appears once in WordPress. The fullInteractive frame below it contains this topic's replies and controls without repeating the source post.

## Continue where readers are

Readers can follow and reply from WordPress or open the same topic on The Bridge. There is no copied comment database and no competing source of truth.
