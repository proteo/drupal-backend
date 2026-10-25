# Context

Vocabulary of `proteo/drupal-backend`, a Laravel package for consuming a Drupal JSON:API backend. A consuming project describes its content in a descriptor file and defines its own terms in its own `CONTEXT.md`; nothing here is specific to one site.

## Content and cache

- **Tab**: one result group of the advanced search, one per searchable content type. Each shows its own count and is paginated separately.
- **Searchable**: whether a content type has a search tab and suggestions.
- **Boolean filter**: selects items whose boolean field is true, alone or combined by `any` / `all`.
- **Search backend**: the class bound to `SearchBackend` that plans how each tab is answered; the default answers from native collections, and an extension may wrap it.
- **Capabilities**: what a search backend declares it can do (exact counts, index-only booleans, fulltext).
- **Exact count**: a tab count that is the true number of matches; otherwise it is a floor shown as `N+`.
- **Related request** / **Criterion**: what to relate to an entity (source, criteria in priority order, types, limit, order) / one relation the source and a candidate can share.
- **Condition**: a rule a type's entities must satisfy to appear in lists, search and suggestions; it does not hide the item page.
- **Decorator** / **Extra**: a per-type class computing extra values for a batch of entities / a value it adds, read with `extra()`; distinct from a Field.
- **File**: a downloadable file behind a media or file entity (url, name, mime, size); distinct from an Image.
- **Native collection**: a regular JSON:API collection for one content type. It matches partial words with `CONTAINS` but has no relevance and no total.
- **Alias**: the Drupal path alias in the URL. It is the identity of a content item; other URL segments are not compared with the item's relations.
- **Content generation**: one counter included in every cache key; bumping it invalidates all cached content (per Source, see ADR 0001).
- **Fresh / stale window**: 5 min / 24 h by default (`drupal.cache.fresh` and `drupal.cache.stale`, shipped in the package's `config/drupal.php`). Inside the stale window a failed refresh keeps serving the stale copy.

## Content model descriptor

The vocabulary a project uses to describe its Drupal content to the frontend system.

- **Source**: one Drupal backend connection. Every content type belongs to exactly one Source; one is the default.
- **Content type**: one Drupal (entity type, bundle) pair, such as `node/talk` or `taxonomy_term/tracks`. Has a resource type (derived as `entity-type--bundle`), and a native collection. A vocabulary is a `taxonomy_term` bundle, so each vocabulary is its own content type and a project may declare any number of them.
- **Role**: how the system treats a content type, derived from its entity type. **Primary**: an entity of type `node` served in its own right with an item page and a listing; searchable unless configured otherwise (Talk, Workshop). **Facet**: an entity of any non-node type, subordinate to nodes, with no count and no tie to one Primary (Topic, Track, Slides). **Page**: a static item page with no listing (About). **Reference**: fetched only through a Field of another content type, possibly on another Source, optional and failing soft to nothing (Review).
- **Field**: a frontend-named property sourced from a Drupal field path. Views and components only ever see frontend names.
- **Relation**: a single or many link from one content type to another, loaded through an include path. Media chains end in an **Image field**.
- **Filter** / **Sort**: frontend capabilities of a content type, declared against paths of the native collection (equals, year range, boolean).
- **Entity**: the immutable object a view receives for one content item. Fields are read by their frontend names (an unknown name is an error, never empty); every Entity also has an id, a type, a path, a URL and any Extra a Decorator added. A project may supply its own subclass per content type to add presentation helpers.
- **URL prefix**: the path segment the frontend serves a content type under.
- **Alias prefix**: what is prepended to an alias before Drupal resolves it; may be empty. Independent of the URL prefix.

## Failure vocabulary

- **Not found** (404): Drupal 404 or 403, unresolved alias, wrong-bundle alias.
- **Unavailable** (503, `Retry-After: 30`): connection errors, timeouts, 5xx after retries, 429.
- **Bad response** (502): invalid payload or missing required fields.

Messages shown to visitors are always generic; upstream detail goes to logs only.

Descriptor values are declared raw and normalised where they are used (`DescriptorFactory`): trimming, casting and defaults never belong in the project's `config/drupal-backend.php`.
