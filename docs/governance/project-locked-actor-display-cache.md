# Locked project actor display data (partial)

This isolated successor is based on `bbc2094a09cc2ec0e141270cf14aa802ab91a367`. An actual native validation-page run reached `PhabricatorUser::requireCacheData` for `user.preferences.v2` through the standard page timezone setting. Replacing the request viewer with its locked authority row correctly preserved current security facts, but lost the native display cache attachments. Restoring an older viewer would lose that authority freshness.

After the native execution scope opens, only its actual web request path now attaches display values to the same actor and session. It calls the fixed native preferences factory, and the native unread-message/notification count factories only under the same `isClassInstalledForViewer` conditions used by `AphlictDropdownDataQuery`. These factories read preferences or COUNT rows; no durable cache is written. Native preference factory internal Omni use is restricted to the one locked actor's settings and never supplies object permission.

A standard full page also reads the avatar URI. The scope only reads an existing native user-cache row and checks `PhabricatorUserProfileImageCacheType::isRawCacheDataValid` against the locked actor. It never calls that type's generating factory. Missing or invalid avatar cache remains unattached, without rejecting a direct editor before mutation; a later renderer still requires an actual valid cache. This extreme missing-cache page profile remains an unproved technical boundary. No fake version or fallback URI is attached.

There is no inline generation, cache-registry sweep, badge generation, avatar generation, user save, actor replacement, old-viewer restoration or new permission grant. The original native actor/session checks, MFA, CSRF and final current-source checks remain unchanged. App-installation cache values are display conditions, not new authorization proof.

The patch has only host lint/static source review at preparation. Native validation-page, SQL, no-extra-user/file/cache-write and final integrated source checks are required. Optional features remain off by default; no deployment or overall family closure is claimed.
