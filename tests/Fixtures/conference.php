<?php

use Proteo\DrupalBackend\Tests\Fixtures\TalkDecorator;

/*
 * Parity fixture: a conference site. Two searchable Primaries, one that is not
 * searchable, term, media and paragraph Facets, and a second Source.
 */

return [
    'schema_version' => 3,

    // #region sources
    'sources' => [
        'default' => [
            'base_url' => 'https://drupal.test',
            'jsonapi_path' => '/jsonapi',
            'files_path' => '/sites/default/files',
            'webhook' => ['secret' => 'shared-secret', 'tolerance' => 30],
        ],
        'reviews' => [
            'base_url' => 'https://reviews.test',
            'jsonapi_path' => '/api',
            'frontend_url' => 'https://reviews.example',
        ],
    ],
    // #endregion sources

    'content_types' => [
        // #region talk
        'talk' => [
            'entity_type' => 'node',
            'bundle' => 'talk',
            'url_prefix' => 'talk',
            'alias_prefix' => '',
            'listing_path' => '/talks',
            'per_page' => 15,
            'default_sort' => '-created',
            'decorator' => TalkDecorator::class,
            'fields' => [
                'title' => ['source' => 'title', 'required' => true],
                'date' => ['source' => 'created', 'type' => 'date'],
                'teaser' => ['source' => 'short_description', 'type' => 'html'],
                'content' => ['source' => 'description', 'type' => 'html'],
                'review_id' => ['source' => 'review_reference'],
            ],
            'relations' => [
                'speakers' => ['to' => 'speaker', 'via' => 'speaker', 'many' => true],
                'topics' => ['to' => 'topic', 'via' => 'topic', 'many' => true],
                'tracks' => ['to' => 'track', 'via' => 'tracks', 'many' => true],
                'slide_resources' => ['to' => 'resource', 'via' => 'slides_resources', 'many' => true],
                'recording_resources' => ['to' => 'resource', 'via' => 'recording_resources', 'many' => true],
                'deck' => ['to' => 'slides', 'via' => 'deck', 'optional' => true],
                'cover' => ['type' => 'image', 'via' => 'cover', 'image_field' => 'field_media_image'],
            ],
            'includes' => [
                'item' => ['speakers', 'topics', 'tracks', 'slide_resources', 'recording_resources', 'cover'],
                'listing' => ['cover', 'speakers', 'topics', 'slide_resources', 'recording_resources'],
            ],
            'filters' => [
                'topic' => ['type' => 'equals', 'relation' => 'topics', 'native_path' => 'topic.id'],
                'speaker' => ['type' => 'equals', 'relation' => 'speakers', 'native_path' => 'speaker.id'],
                'track' => ['type' => 'equals', 'relation' => 'tracks', 'native_path' => 'tracks.id'],
                'year' => ['type' => 'year_range', 'native_path' => 'created', 'first_year' => 2021],
                'materials' => ['type' => 'boolean', 'fields' => ['slides' => ['native' => 'has_slides'], 'recording' => ['native' => 'has_recording']], 'match' => 'any'],
            ],
            'sorts' => ['newest' => '-created', 'oldest' => 'created', 'title' => 'title'],
            'suggest_subtitle' => 'speakers',
            'term_fields' => ['title' => 5, 'description.value' => 1],
            'related_term_fields' => ['speaker.title' => 3, 'topic.name' => 2, 'tracks.name' => 2],
            'seo' => ['title' => 'title', 'description' => ['field' => 'teaser', 'max' => 160], 'image' => 'cover', 'author' => 'speakers'],
        ],
        // #endregion talk

        // A second searchable Primary: its own filter and sort.
        'workshop' => [
            'entity_type' => 'node',
            'bundle' => 'workshop',
            'url_prefix' => 'workshop',
            'alias_prefix' => '',
            'listing_path' => '/workshops',
            'title_attribute' => 'title',
            'fields' => [
                'title' => ['source' => 'title', 'required' => true],
                'level' => ['source' => 'level'],
            ],
            'filters' => [
                'level' => ['type' => 'equals', 'relation' => 'levels', 'native_path' => 'level.id'],
            ],
            'relations' => ['levels' => ['to' => 'topic', 'via' => 'level', 'many' => true]],
            'sorts' => ['soonest' => 'start'],
            'term_fields' => ['title' => 5],
        ],

        // #region speaker
        // A Primary that is not searchable keeps its routes, listing and item pages.
        'speaker' => [
            'entity_type' => 'node',
            'bundle' => 'speaker',
            'url_prefix' => 'speaker',
            'alias_prefix' => '',
            'listing_path' => '/speakers',
            'per_page' => 20,
            'default_sort' => 'title',
            'searchable' => false,
            'conditions' => [['attribute' => 'talk_count', 'operator' => '>', 'value' => 0]],
            'title_attribute' => 'title',
            'fields' => [
                'name' => ['source' => 'title', 'required' => true],
                'bio' => ['source' => 'bio', 'type' => 'html'],
                'talk_count' => ['source' => 'talk_count', 'type' => 'integer'],
            ],
        ],
        // #endregion speaker

        'topic' => [
            'entity_type' => 'taxonomy_term',
            'bundle' => 'topic',
            'searchable' => true,
            'listing_path' => '/topics',
            'per_page' => 20,
            'default_sort' => 'name',
            'conditions' => [['attribute' => 'talk_count', 'operator' => '>', 'value' => 0]],
            'title_attribute' => 'name',
            'fields' => [
                'name' => ['source' => 'name', 'required' => true],
                'talk_count' => ['source' => 'talk_count', 'type' => 'integer'],
            ],
        ],

        // The native collection differs from the bundle.
        'track' => [
            'entity_type' => 'taxonomy_term',
            'bundle' => 'tracks',
            'native_collection' => 'track',
            'searchable' => true,
            'conditions' => [['attribute' => 'talk_count', 'operator' => '>', 'value' => 0]],
            'title_attribute' => 'name',
            'fields' => [
                'name' => ['source' => 'name', 'required' => true],
                'talk_count' => ['source' => 'talk_count', 'type' => 'integer'],
            ],
        ],

        // Media: no alias, no routes. The `file` relation yields the downloadable file.
        'slides' => [
            'entity_type' => 'media',
            'bundle' => 'slides',
            'fields' => [
                'name' => ['source' => 'name', 'required' => true],
            ],
            'relations' => [
                'file' => ['type' => 'file', 'via' => 'field_media_file'],
            ],
            'includes' => ['item' => ['file']],
        ],

        // A paragraph has no path alias, so it has no path and no routes.
        'resource' => [
            'entity_type' => 'paragraph',
            'bundle' => 'resource',
            'fields' => [
                'href' => ['source' => 'url', 'required' => true],
            ],
        ],

        'page' => [
            'role' => 'page',
            'entity_type' => 'node',
            'bundle' => 'page',
            'url_prefix' => '',
            'alias_prefix' => '',
            'fields' => [
                'title' => ['source' => 'title', 'required' => true],
                'body' => ['source' => 'body', 'type' => 'html'],
            ],
            'relations' => ['image' => ['type' => 'image', 'via' => 'image']],
            'routes' => ['about' => '/about'],
        ],

        // #region review
        'review' => [
            'role' => 'reference',
            'source' => 'reviews',
            'entity_type' => 'node',
            'bundle' => 'article',
            'native_collection' => 'node/article',
            'referenced_by' => ['talk' => 'review_id'],
            'fields' => [
                'title' => ['source' => 'title', 'required' => true],
                'teaser' => ['source' => 'teaser', 'type' => 'excerpt', 'length' => 300],
            ],
        ],
        // #endregion review
    ],

    // #region search
    'search' => [
        'enabled' => true,
        'tabs' => ['talk', 'workshop', 'topic', 'track'],
        'suggestions' => ['limit' => 10, 'quotas' => ['talk' => 5, 'workshop' => 2, 'topic' => 1, 'track' => 2]],
    ],
    // #endregion search
];
