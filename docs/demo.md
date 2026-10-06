# Synthetic demonstration

Authorized synthetic records are ingested → indexed search returns paginated results; the retained asynchronous path creates a job, scans its corpus, reports progress and returns owner-scoped output.

## Walkthrough

1. Generate harmless synthetic event logs.
2. Index the records and compare first/next search pages.
3. Run the retained asynchronous path and inspect progress and ownership.
4. Disable OpenSearch intentionally; record the indexed-route error separately from the file path.

## Acceptance checklist

- [ ] PHP syntax and Composer setup
- [ ] Pagination, cache behavior and unavailable index service
- [ ] Job ownership and limits
- [ ] Measured warm/cold-cache benchmark only after running the documented procedure

## Evidence discipline

Screenshots must come from the running application with synthetic records. Record the component, viewport and configuration. A storyboard is not a recorded walkthrough. Benchmark only generated data and include hardware, input size, configuration, elapsed time and cache conditions.

No speed, terabyte-scale, document-count or legal-compliance claims are made. Integration checks need disposable OpenSearch/MySQL services. Publish no credential collections or private datasets.
