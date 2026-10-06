## Comparing indexed search with direct file processing

MisterX retains multiple implementation paths: an active OpenSearch route and file-search/background-job code. These solve different parts of the search problem. Ingestion prepares indexed documents, search_after carries pagination state, and background processing provides a job lifecycle with progress and result ownership.

The release supplies generated JSONL records and a compatible Composer dependency setup. It does not publish private corpora, credential collections or acquisition instructions. Historical variable names remain documented so the code's evolution is understandable; an educational statement does not authorize processing somebody else's private data.

## Query identity includes the index and cursor

A cached search response depends on more than its query text. Changing the index or pagination cursor changes the requested page, so those values belong in the cache key. The source review corrects that boundary and checks ownership before processing a job rather than after its work has begun.

PHP installation and synthetic database setup were verified locally. The supplied OpenSearch checks have not yet executed successfully here, so no indexed performance or equivalence benchmark is published. The next measured step is an isolated generated-corpus run covering pagination, service failure, cache behavior and agreement with the supported file path.
