# Indexed text search and background processing

## From the problem to the implementation

Provide indexed text lookup and a separate asynchronous scanning path with accounts, limits and result management.

Authorized synthetic records are ingested → indexed search returns paginated results; the retained asynchronous path creates a job, scans its corpus, reports progress and returns owner-scoped output.

## Decisions and tradeoffs

Indexed retrieval and direct file scanning are different execution paths. OpenSearch availability determines the active indexed route; keeping old job utilities does not make them automatic failover.

Composer now declares the missing OpenSearch PHP client. Configure the index explicitly; the public default is `portfolio_records`.

Legacy identifiers such as LEAKED_DATA_PATH remain in the source for compatibility and are documented, rather than presented as new terminology.

A hard-coded personal unlimited-account exception is replaced with an empty-by-default environment allowlist.

Educational intent does not authorize processing private information. The MIT warranty clause is not a promise of immunity.

## What the publication preparation established

PHP syntax, compatible Composer installation and fresh MariaDB synthetic bootstrap passed. I fixed query parameter binding, moved job ownership validation before processing, and added index/cursor values to Redis cache keys. OpenSearch ingestion/pagination/cache checks are provided but have not executed successfully in the current environment.

## Deployment experience and evidence limits

Search engineering showcase. Public examples use generated, authorized records only; no original corpus or private search results are included.

No indexed performance figures are published until the supplied OpenSearch checks and generated-corpus benchmark actually run. Redis and OpenSearch failure behavior, pagination and indexed/file result consistency remain release limitations. Only authorized synthetic records may be used.

## Next steps

Complete the uncovered checks above, record the results, and update the demonstration. Retain the existing architecture and add reproducible synthetic cases before claiming performance improvements or another provider integration.
