# Manual failover and no automatic failback

- Status: Accepted
- Date: 2026-10-05

## Context

Both supported engines can occupy the primary role. After failover, the former standby may accept new production writes and create a new authoritative timeline.

## Decision

Authority changes require fencing and operator control.

Once a standby has been promoted and accepts application writes, automatic return to the old primary is prohibited regardless of which database engine is on either side.

## Consequences

Recovery and later authority changes must migrate/validate from the current authority. Do not merge or reactivate an old divergent primary automatically.
