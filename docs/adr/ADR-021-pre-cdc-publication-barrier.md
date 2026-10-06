# ADR-021: Brief final write fence before pre-CDC publication

- Status: Accepted
- Date: 2026-10-06

## Context

A consistent source snapshot can become stale while its candidate is built. The project has not implemented durable change capture or catch-up, so row counts at snapshot time do not prove parity at publication time.

## Decision

After candidate validation, release the long-running snapshot and enter a short source write fence: `LOCK TABLES ... READ` for the MySQL-family source or `BEGIN IMMEDIATE` for SQLite. Under that fence, re-introspect schema and stream each copied table through an order-independent content digest; compare the candidate content and the profile's expected exclusions. Publish only when schema and content still match. Any difference discards the candidate and returns a rebuild-required failure. The lock is released in `finally` after publication or error.

## Consequences

- Normal writes continue during most of the build. Writes are blocked only during the final comparison and publication window.
- The guarantee is parity at the fenced publication point, not zero lag after the fence is released. Later writes can immediately create drift until CDC exists.
- Large datasets can extend the final fence because content is streamed for verification. The duration must be measured on the development site.
- Equal row counts alone are insufficient; row content and schema are compared.
- Later CDC catch-up should replace or shorten this barrier only after its durability and recovery properties are proven.
