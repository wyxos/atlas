Prefill container ID
- when opening a container in a new tab (in app), the new tab advanced search does not prefill the postId or userId in the browser form, even though the search does bring up the correct container query.

Blacklisting large number of files at once
- when blacklisting 50+ files at once, the toast shows, reaction is queued, and once timer expires, the toast remains instead of disappearing, and the request takes a while to execute. Need to improve to handle large amount.
- closing the tab during that operation causes a glitch, where the tab remains open.

Switching tab doesn't kill to dislike timers
- if a tab is loaded and timer to dislike shows on items, it is expected that if we switch to another tab, those timers need to be cleared/reset. Likely needs to be achieved in unmounted of TabContent.
