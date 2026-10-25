<?php

namespace Proteo\DrupalBackend\Tests\Descriptor;

use Proteo\DrupalBackend\Descriptor\FieldType;
use Proteo\DrupalBackend\Descriptor\FilterType;
use Proteo\DrupalBackend\Descriptor\InvalidDescriptor;
use Proteo\DrupalBackend\Descriptor\RelationKind;
use Proteo\DrupalBackend\Descriptor\Role;

class DescriptorFactoryTest extends DescriptorTestCase
{
    public function test_the_conference_fixture_is_hydrated(): void
    {
        $descriptor = $this->descriptor($this->fixture('conference'));
        $talk = $descriptor->contentType('talk');

        $this->assertSame('default', $descriptor->defaultSource);
        $this->assertSame(['default', 'reviews'], array_keys($descriptor->sources));
        $this->assertSame(Role::Primary, $talk->role);
        $this->assertSame('node--talk', $talk->resourceType());
        $this->assertSame('node/talk', $talk->resourcePath());
        $this->assertSame(FieldType::Date, $talk->fields['date']->type);
        $this->assertTrue($talk->fields['title']->required);
        $this->assertSame('resource', $talk->relations['slide_resources']->to);
        $this->assertSame(RelationKind::Image, $talk->relations['cover']->kind);
        $this->assertSame('speaker', $talk->relations['speakers']->to);
        $this->assertSame(FilterType::Boolean, $talk->filters['materials']->type);
        $this->assertSame(['slides', 'recording'], $talk->filters['materials']->parameters());
        $this->assertSame(['year'], $talk->filters['year']->parameters());
        $this->assertSame('has_slides', $talk->filters['materials']->fields['slides']->native);
        $this->assertSame('created', $talk->filters['year']->nativePath);
        $this->assertSame('teaser', $talk->seo->description);
        $this->assertSame(160, $talk->seo->descriptionMax);
    }

    public function test_content_type_defaults_follow_the_key_and_bundle(): void
    {
        $descriptor = $this->descriptor($this->fixture('conference'));

        $track = $descriptor->contentType('track');
        $this->assertSame('taxonomy_term--tracks', $track->resourceType());
        $this->assertSame('track', $track->nativeCollection);
        $this->assertSame('track', $track->urlPrefix);
        $this->assertSame('track', $track->aliasPrefix);
        $this->assertFalse($track->hasListing());
        $this->assertSame('default', $track->source);

        $topic = $descriptor->contentType('topic');
        $this->assertSame('topic', $topic->nativeCollection);
        $this->assertTrue($topic->hasListing());
    }

    public function test_an_explicit_empty_alias_prefix_is_kept(): void
    {
        $talk = $this->descriptor($this->fixture('conference'))->contentType('talk');

        $this->assertSame('', $talk->aliasPrefix);
        $this->assertSame('talk', $talk->urlPrefix);
    }

    public function test_a_primary_is_searchable_and_a_reference_is_optional_by_default(): void
    {
        $descriptor = $this->descriptor($this->fixture('conference'));

        $this->assertTrue($descriptor->contentType('talk')->searchable);
        $this->assertFalse($descriptor->contentType('resource')->searchable);
        $this->assertSame(['talk', 'workshop', 'topic', 'track'], array_keys($descriptor->searchableTypes()));
        $this->assertTrue($descriptor->contentType('review')->optional);
        $this->assertFalse($descriptor->contentType('talk')->optional);
    }

    public function test_roles_and_facets_can_be_queried(): void
    {
        $descriptor = $this->descriptor($this->fixture('conference'));

        $this->assertSame(['topic', 'track', 'slides', 'resource'], array_keys($descriptor->ofRole(Role::Facet)));
        $this->assertSame(['page'], array_keys($descriptor->ofRole(Role::Page)));
        $this->assertSame(['about' => '/about'], $descriptor->contentType('page')->routes);
    }

    public function test_the_source_is_normalised_and_inherits_the_default_for_content_types(): void
    {
        $config = $this->fixture('conference');
        $config['sources']['default']['base_url'] = 'https://drupal.test/';
        $config['sources']['default']['jsonapi_path'] = 'jsonapi/';
        $config['sources']['reviews']['webhook'] = ['secret' => '', 'tolerance' => '45'];
        $config['sources']['reviews']['flush'] = ['also' => ['sources' => ['default']]];
        $config['sources']['reviews']['timeout'] = 4;

        $descriptor = $this->descriptor($config);

        $this->assertSame('https://drupal.test', $descriptor->source('default')->baseUrl);
        $this->assertSame('/jsonapi', $descriptor->source('default')->jsonapiPath);
        $this->assertSame('/sites/default/files', $descriptor->source('default')->filesPath);
        $this->assertSame('shared-secret', $descriptor->source('default')->webhookSecret);
        $this->assertNull($descriptor->source('reviews')->webhookSecret);
        $this->assertSame(45, $descriptor->source('reviews')->webhookTolerance);
        $this->assertSame(['default'], $descriptor->source('reviews')->flushAlsoSources);
        $this->assertSame(['timeout' => 4], $descriptor->source('reviews')->overrides);
        $this->assertSame('https://reviews.example', $descriptor->source('reviews')->frontendUrl);
    }

    public function test_raw_env_values_are_normalised_where_they_are_used(): void
    {
        $config = $this->fixture('conference');
        $config['sources']['default'] = [
            'base_url' => 'https://drupal.test//',
            'jsonapi_path' => '',
            'files_path' => null,
            'frontend_url' => '',
            'webhook' => ['secret' => null, 'tolerance' => '45'],
        ];
        $config['search']['cache'] = '60';
        $config['limits']['content'] = '120';

        $descriptor = $this->descriptor($config);
        $source = $descriptor->source('default');

        $this->assertSame('https://drupal.test', $source->baseUrl);
        $this->assertSame('/jsonapi', $source->jsonapiPath);
        $this->assertSame('', $source->filesPath);
        $this->assertNull($source->frontendUrl);
        $this->assertNull($source->webhookSecret);
        $this->assertSame(45, $source->webhookTolerance);
        $this->assertSame(60, $descriptor->search->cacheSeconds);
        $this->assertSame(120, $descriptor->limits->content);
    }

    public function test_an_unset_base_url_is_rejected(): void
    {
        $config = $this->fixture('conference');
        $config['sources']['default']['base_url'] = null;

        $this->expectException(InvalidDescriptor::class);

        $this->descriptor($config);
    }

    public function test_code_defaults_apply_when_sections_are_missing(): void
    {
        $descriptor = $this->descriptor($this->fixture('article'));

        $this->assertFalse($descriptor->search->enabled);
        $this->assertSame(18, $descriptor->search->perPage);
        $this->assertSame(50, $descriptor->search->nativeLimit);
        $this->assertSame(60, $descriptor->search->cacheSeconds);
        $this->assertSame(10, $descriptor->search->suggestionLimit);
        $this->assertSame([2, 100, 10], [$descriptor->search->termMin, $descriptor->search->termMax, $descriptor->search->termMaxWords]);
        $this->assertTrue($descriptor->routes->auto);
        $this->assertSame(['web'], $descriptor->routes->middleware);
        $this->assertSame('[a-z0-9-]{1,128}', $descriptor->routes->aliasPattern);
        $this->assertSame('/hooks/drupal', $descriptor->webhook->path);
        $this->assertSame([120, 60, 60], [$descriptor->limits->content, $descriptor->limits->searchSuggest, $descriptor->limits->drupalWebhook]);
    }

    public function test_provided_values_override_the_code_defaults(): void
    {
        $config = $this->fixture('article');
        $config['search'] = ['enabled' => true, 'per_page' => 12, 'suggestions' => ['limit' => 4]];
        $config['routes'] = ['auto' => false, 'prefix' => '/shop/', 'middleware' => ['web', 'cache'], 'alias_pattern' => '[a-z]+'];
        $config['webhook'] = ['path' => 'hooks/cms'];
        $config['limits'] = ['content' => 30];

        $descriptor = $this->descriptor($config);

        $this->assertSame([12, 4], [$descriptor->search->perPage, $descriptor->search->suggestionLimit]);
        $this->assertFalse($descriptor->routes->auto);
        $this->assertSame('shop', $descriptor->routes->prefix);
        $this->assertSame(['web', 'cache'], $descriptor->routes->middleware);
        $this->assertSame('/hooks/cms', $descriptor->webhook->path);
        $this->assertSame([30, 60], [$descriptor->limits->content, $descriptor->limits->searchSuggest]);
    }

    public function test_the_default_source_can_be_named(): void
    {
        $config = $this->fixture('conference');
        $config['default_source'] = 'reviews';

        $this->assertSame('reviews', $this->descriptor($config)->defaultSource);
        $this->assertSame('reviews', $this->descriptor($config)->contentType('track')->source);
    }

    public function test_the_package_default_config_is_merged_one_level_deep(): void
    {
        $this->assertSame(3, config('drupal-backend.schema_version'));
        $this->assertSame([], config('drupal-backend.content_types'));

        config(['drupal-backend.limits' => ['content' => 5]]);

        $this->assertSame(5, config('drupal-backend.limits.content'));
    }

    public function test_a_schema_version_mismatch_fails_with_an_upgrade_pointer(): void
    {
        $config = $this->fixture('conference');
        $config['schema_version'] = 4;

        $this->expectException(InvalidDescriptor::class);
        $this->expectExceptionMessage('schema_version [4]');

        $this->descriptor($config);
    }

    public function test_a_missing_schema_version_fails(): void
    {
        $config = $this->fixture('conference');
        unset($config['schema_version']);

        $this->expectException(InvalidDescriptor::class);

        $this->descriptor($config);
    }

    public function test_an_unknown_role_names_the_path_and_the_allowed_values(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['role'] = 'star';

        $this->expectException(InvalidDescriptor::class);
        $this->expectExceptionMessage('content_types.talk.role');
        $this->expectExceptionMessage('primary, facet, page, reference');

        $this->descriptor($config);
    }

    public function test_an_unknown_field_type_is_rejected(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['fields']['title']['type'] = 'markdown';

        $this->expectExceptionMessage('content_types.talk.fields.title.type');

        $this->descriptor($config);
    }

    public function test_the_bundle_defaults_to_the_entity_type(): void
    {
        $config = $this->fixture('conference');
        unset($config['content_types']['talk']['bundle']);

        $type = $this->descriptor($config)->contentType('talk');

        $this->assertSame('node', $type->bundle);
        $this->assertSame('node--node', $type->resourceType());
    }

    public function test_the_role_follows_the_entity_type(): void
    {
        $config = $this->fixture('conference');
        unset($config['content_types']['talk']['role'], $config['content_types']['topic']['role'], $config['content_types']['topic']['searchable']);

        $descriptor = $this->descriptor($config);

        $this->assertSame(Role::Primary, $descriptor->contentType('talk')->role);
        $this->assertTrue($descriptor->contentType('talk')->searchable);
        $this->assertSame(Role::Facet, $descriptor->contentType('topic')->role);
        $this->assertFalse($descriptor->contentType('topic')->searchable);
    }

    public function test_only_types_with_an_alias_or_an_opt_in_are_served(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['asset'] = ['entity_type' => 'media', 'bundle' => 'document'];
        $config['content_types']['block'] = ['entity_type' => 'paragraph', 'bundle' => 'block', 'url_prefix' => 'blocks'];

        $descriptor = $this->descriptor($config);

        $this->assertTrue($descriptor->contentType('talk')->served);
        $this->assertTrue($descriptor->contentType('topic')->served);
        $this->assertFalse($descriptor->contentType('asset')->served);
        $this->assertTrue($descriptor->contentType('block')->served);
        $this->assertFalse($descriptor->contentType('page')->served);
    }

    public function test_a_version_1_descriptor_fails_listing_every_removed_key(): void
    {
        $config = $this->fixture('conference');
        $config['schema_version'] = 1;
        $config['content_types']['speaker']['facet_of'] = 'talk';
        $config['content_types']['speaker']['count_field'] = ['attribute' => 'n'];
        $config['content_types']['talk']['filters']['materials']['type'] = 'any_flag';
        $config['content_types']['talk']['relations']['buy'] = ['type' => 'link', 'via' => 'links'];

        try {
            $this->descriptor($config);
            $this->fail('A version 1 descriptor must not load.');
        } catch (InvalidDescriptor $e) {
            foreach (['content_types.speaker.facet_of', 'content_types.speaker.count_field', 'content_types.talk.filters.materials.type', 'content_types.talk.relations.buy.type'] as $path) {
                $this->assertStringContainsString($path, $e->getMessage());
            }
        }
    }

    public function test_a_removed_key_in_a_current_descriptor_fails(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['speaker']['facet_of'] = 'talk';

        $this->expectException(InvalidDescriptor::class);
        $this->expectExceptionMessage('content_types.speaker.facet_of');

        $this->descriptor($config);
    }

    public function test_an_unknown_filter_type_is_rejected(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['filters']['year']['type'] = 'between';

        $this->expectExceptionMessage('content_types.talk.filters.year.type');

        $this->descriptor($config);
    }

    public function test_a_version_2_descriptor_fails_with_one_error_listing_every_removed_key(): void
    {
        $config = $this->fixture('conference');
        $config['schema_version'] = 2;
        $config['search']['index'] = ['path' => 'index/default'];
        $config['search']['index_lookup_pages'] = 5;
        $config['search']['match_strategy'] = 'index_fulltext';
        $talk = &$config['content_types']['talk'];
        $talk += ['index_bundle' => 'talks', 'index_fields' => ['topic' => 'topic_id'], 'index_title_field' => 'title', 'match_strategy' => 'native_contains', 'related' => ['relations' => ['topics']]];
        $talk['filters']['year']['index_field'] = 'created';
        $talk['filters']['materials']['fields']['slides'] = 'slides';
        $talk['filters']['materials']['fields']['recording'] = ['index' => 'recording', 'native' => 'has_recording'];
        $config['content_types']['speaker']['conditions'][0]['index'] = 'talk_count';
        unset($talk);

        try {
            $this->descriptor($config);
            $this->fail('A version 2 descriptor must not load.');
        } catch (InvalidDescriptor $e) {
            foreach ([
                'search.index.path', 'search.index_lookup_pages', 'search.match_strategy', 'content_types.talk.index_bundle', 'content_types.talk.index_fields',
                'content_types.talk.index_title_field', 'content_types.talk.match_strategy', 'content_types.talk.related', 'content_types.talk.filters.year.index_field',
                'content_types.talk.filters.materials.fields.slides', 'content_types.talk.filters.materials.fields.recording.index', 'content_types.speaker.conditions.0.index',
            ] as $path) {
                $this->assertStringContainsString($path, $e->getMessage());
            }

            $this->assertStringContainsString('proteo/drupal-search-api', $e->getMessage());
            $this->assertStringContainsString('rename it `title_attribute`', $e->getMessage());
        }
    }

    public function test_a_boolean_field_takes_a_native_path_only(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['filters']['materials']['fields']['slides'] = 'has_slides';

        $this->expectException(InvalidDescriptor::class);
        $this->expectExceptionMessage('content_types.talk.filters.materials.fields.slides');

        $this->descriptor($config);
    }
}
