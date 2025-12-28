# Future Improvements

Potential enhancements that maintain the "boringly reliable" philosophy: no JavaScript required, privacy-first, minimal complexity.

## User Experience

-   [ ] Per-vote-type confirmation messages (`yesConfirmation`, `noConfirmation`)
-   [ ] Optional vote count display in snippet

## Integration

-   [ ] Webhook support for vote notifications (async, non-blocking)
-   [ ] Kirby hooks: `helpful:vote`, `helpful:stored`, `helpful:countsUpdated`

## Developer Experience

-   [ ] Blueprint snippet for `helpful_yes` / `helpful_no` fields
-   [ ] CSS class override via snippet parameters - allow customizing class names per snippet call
-   [ ] Disable counts per snippet - allow showing form without updating page counts (useful for test/preview instances)
-   [ ] Additional HTML attributes - allow adding custom data attributes, IDs, or other attributes to form/buttons per snippet call

## Performance

-   [ ] Request-scoped cache for `hasVoted()` - avoid redundant cache lookups during same request
-   [ ] Concurrency-safe count updates - prevent lost increments under simultaneous votes

## Maintenance

-   [ ] Log rotation - date-based JSONL files to prevent single file from growing too large

## Code Organization

-   [ ] Extract IP anonymization logic - `IpAnonymizer` class for `normalizeIp`, `truncateIpv4`, `truncateIpv6` (~70 lines, most self-contained)
-   [ ] Extract token operations - `TokenManager` class for token generation/validation if logic expands (~120 lines)
-   [ ] Extract rate limiting - `RateLimiter` class if rate limiting becomes more complex (~50 lines)

Note: Current single-class approach is reasonable for current size. Consider extraction only if class grows significantly or if components need reuse elsewhere.

---

All improvements must be backward compatible and maintain the no-JS principle.
