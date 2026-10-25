<?php

/*
 * A deliberately different model: one Source, two vocabularies, a Primary that is not searchable,
 * no search, no reference. Nothing here may need package code.
 */

return [
    'schema_version' => 3,

    'sources' => [
        'default' => ['base_url' => 'https://cms.test'],
    ],

    'content_types' => [
        // #region article
        'article' => [
            'role' => 'primary',
            'entity_type' => 'node',
            'bundle' => 'article',
            'url_prefix' => 'articles',
            'alias_prefix' => 'articles',
            'listing_path' => '/articles',
            'fields' => [
                'title' => ['source' => 'title', 'required' => true],
                'published' => ['source' => 'created', 'type' => 'date'],
                'body' => ['source' => 'body', 'type' => 'html'],
            ],
            'relations' => [
                'writers' => ['to' => 'writer', 'via' => 'uid_writer', 'many' => true],
                'topics' => ['to' => 'topic', 'via' => 'field_topic', 'many' => true, 'optional' => true],
                'genre' => ['to' => 'genre', 'via' => 'field_genre'],
                'hero' => ['type' => 'image', 'via' => 'field_hero', 'image_field' => 'field_media_image', 'optional' => true],
            ],
            'includes' => [
                'item' => ['writers', 'topics', 'genre', 'hero'],
                'listing' => ['hero', 'genre'],
            ],
            'filters' => [
                'topic' => ['type' => 'equals', 'relation' => 'topics', 'native_path' => 'topics.id'],
                'genre' => ['type' => 'equals', 'relation' => 'genre', 'native_path' => 'genre.id'],
            ],
            'sorts' => ['newest' => '-created'],
        ],
        // #endregion article

        // A Primary that is not searchable: routes, listing and item pages only.
        'writer' => [
            'entity_type' => 'node',
            'bundle' => 'writer',
            'searchable' => false,
            'url_prefix' => 'writers',
            'alias_prefix' => 'writers',
            'listing_path' => '/writers',
            'conditions' => [['attribute' => 'article_count', 'operator' => '>', 'value' => 0]],
            'fields' => [
                'name' => ['source' => 'title', 'required' => true],
                'article_count' => ['source' => 'article_count', 'type' => 'integer'],
            ],
        ],

        'topic' => [
            'role' => 'facet',
            'entity_type' => 'taxonomy_term',
            'bundle' => 'topic',
            'conditions' => [['attribute' => 'article_count', 'operator' => '>', 'value' => 0]],
            'fields' => [
                'name' => ['source' => 'name', 'required' => true],
                'article_count' => ['source' => 'article_count', 'type' => 'integer'],
            ],
        ],

        'genre' => [
            'role' => 'facet',
            'entity_type' => 'taxonomy_term',
            'bundle' => 'genre',
            'listing_path' => '/genres',
            'conditions' => [['attribute' => 'article_count', 'operator' => '>', 'value' => 0]],
            'fields' => [
                'name' => ['source' => 'name', 'required' => true],
                'article_count' => ['source' => 'article_count', 'type' => 'integer'],
            ],
        ],
    ],
];
