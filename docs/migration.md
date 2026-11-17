## v2.1.0 breaking changes

- `createSchema()` no longer creates an `active` column on the `users` table.

---

## v2 breaking changes

- `Auth::login()` now returns `AuthKit\Login`, not `?string`. Check `$login->isSuccess()`, `$login->isFailure()`, or `$login->requiresChallenge()`.
- Login-time `$additionalChecks` parameter removed from `login()`. Use `LoginExtensionInterface` instead.
- `UserStorageInterface::findByToken()` requires a second argument: `findByToken(string $token, \DateTimeImmutable $now)`.
- `ChallengeStorageInterface::complete()` requires a second argument: `complete(ChallengeRecord $record, \DateTimeImmutable $now)`.
- `ChallengeStorageInterface::purgeExpired()` requires `\DateTimeImmutable $now`.
- `PdoUserStorage::purgeExpiredTokens()` requires `\DateTimeImmutable $now`.
- `UserStorageInterface::storeToken()` accepts `?\DateTimeInterface` instead of `?\DateTime`.
- `Auth` constructor: `$sessionKey` parameter removed — use `transport: new PhpSessionTransport('key')` instead.
- `Auth::setSessionKey()` removed.
- PHP `>=8.1` required (was `>=8.0`).
- `ramsey/uuid` dependency removed — tokens are generated with `bin2hex(random_bytes(32))`.

