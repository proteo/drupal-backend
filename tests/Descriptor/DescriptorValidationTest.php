<?php

namespace Proteo\DrupalBackend\Tests\Descriptor;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Proteo\DrupalBackend\Content\Entity;
use Proteo\DrupalBackend\Search\Capabilities;

class DescriptorValidationTest extends DescriptorTestCase
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function with(array $config, string $path, mixed $value): array
    {
        Arr::set($config, $path, $value);

        return $config;
    }

    private function assertProblem(string $needle, array $config): void
    {
        $problems = $this->problems($config);

        $this->assertNotEmpty(array_filter($problems, fn (string $p): bool => str_contains($p, $needle)), "No problem mentions [$needle]. Got:\n".implode("\n", $problems));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertNoProblem(string $needle, array $config): void
    {
        $this->assertSame([], array_values(array_filter($this->problems($config), fn (string $p): bool => str_contains($p, $needle))));
    }

    public function test_the_conference_fixture_boots(): void
    {
        $this->assertSame([], $this->problems($this->fixture('conference')));
    }

    public function test_the_article_fixture_boots(): void
    {
        $this->assertSame([], $this->problems($this->fixture('article')));
    }

    public function test_the_default_source_must_exist(): void
    {
        $this->assertProblem('default source [nope]', $this->with($this->fixture('conference'), 'default_source', 'nope'));
    }

    public function test_a_content_type_must_use_a_declared_source(): void
    {
        $this->assertProblem('content_types.talk.source: unknown source [elsewhere]', $this->with($this->fixture('conference'), 'content_types.talk.source', 'elsewhere'));
    }

    public function test_flush_also_must_name_declared_sources(): void
    {
        $this->assertProblem('flush.also.sources: unknown source [ghost]', $this->with($this->fixture('conference'), 'sources.reviews.flush', ['also' => ['sources' => ['ghost']]]));
    }

    /**
     * @return list<array{string}>
     */
    public static function builtInNames(): array
    {
        return [['id'], ['type'], ['path'], ['url']];
    }

    #[DataProvider('builtInNames')]
    public function test_a_built_in_name_cannot_be_a_field(string $name): void
    {
        $config = $this->with($this->fixture('conference'), "content_types.talk.fields.$name", ['source' => 'x']);

        $this->assertProblem("fields.$name: [$name] is a built-in name", $config);
    }

    public function test_a_built_in_name_cannot_be_a_relation(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.relations.url', ['to' => 'speaker', 'via' => 'speaker']);

        $this->assertProblem('relations.url: [url] is a built-in name', $config);
    }

    public function test_a_name_cannot_be_both_a_field_and_a_relation(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.fields.speakers', ['source' => 'x']);

        $this->assertProblem('[speakers] is declared as both a field and a relation', $config);
    }

    public function test_a_content_relation_must_point_at_a_content_type(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.relations.speakers.to', 'writer');

        $this->assertProblem('relations.speakers.to: unknown content type [writer]', $config);
    }

    public function test_a_content_relation_needs_a_target(): void
    {
        $config = $this->fixture('conference');
        unset($config['content_types']['talk']['relations']['speakers']['to']);

        $this->assertProblem('relations.speakers.to: unknown content type []', $config);
    }

    public function test_a_value_relation_points_at_no_content_type(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.relations.cover.to', 'speaker');

        $this->assertProblem('relations.cover.to: a image relation points at no content type', $config);
    }

    public function test_only_an_image_relation_has_an_image_field(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.relations.speakers.image_field', 'f');

        $this->assertProblem('relations.speakers.image_field', $config);
    }

    public function test_includes_must_name_relations(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.includes.item', ['speakers', 'ghosts']);

        $this->assertProblem('includes.item: unknown relation [ghosts]', $config);
    }

    public function test_an_equals_filter_needs_a_content_relation_of_its_type(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.filters.topic.relation', 'cover');

        $this->assertProblem('filters.topic.relation', $config);
    }

    public function test_an_equals_filter_and_a_year_range_need_a_native_path(): void
    {
        $config = $this->fixture('conference');
        unset($config['content_types']['talk']['filters']['topic']['native_path'], $config['content_types']['talk']['filters']['year']['native_path']);

        $this->assertProblem('filters.topic.native_path', $config);
        $this->assertProblem('filters.year.native_path', $config);
    }

    public function test_a_year_range_needs_a_first_year(): void
    {
        $config = $this->fixture('conference');
        unset($config['content_types']['talk']['filters']['year']['first_year']);

        $this->assertProblem('filters.year.first_year', $config);
    }

    public function test_a_boolean_filter_needs_at_least_one_field(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.filters.materials.fields', []);
        $this->assertProblem('filters.materials.fields: a boolean filter needs', $config);
    }

    public function test_a_boolean_without_a_native_path_needs_a_backend_with_index_only_booleans(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.filters.materials.fields.recording', []);

        $this->assertSame([], $this->problems($config));
        $this->assertNotEmpty(array_filter($this->problems($config, new Capabilities), fn (string $p): bool => str_contains($p, 'filters.materials.fields.recording.native')));
        $this->assertSame([], array_values(array_filter($this->problems($config, new Capabilities(indexOnlyBooleans: true)), fn (string $p): bool => str_contains($p, 'filters.materials'))));
    }

    /**
     * @return list<array{string}>
     */
    public static function reservedParameters(): array
    {
        return [['q'], ['sort'], ['tab'], ['page']];
    }

    #[DataProvider('reservedParameters')]
    public function test_a_filter_key_cannot_clash_with_a_search_parameter(string $name): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['filters'][$name] = $config['content_types']['talk']['filters']['topic'];

        $this->assertProblem("filters.$name: [$name] is reserved", $config);
    }

    public function test_a_boolean_parameter_cannot_clash_with_a_search_parameter(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['talk']['filters']['materials']['fields'] = ['page' => ['native' => 'has_slides']];

        $this->assertProblem('filters.materials: [page] is reserved', $config);
    }

    public function test_url_parameters_must_be_unique_within_a_type(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.filters.materials.fields', ['year' => ['native' => 'has_slides']]);

        $this->assertProblem('the URL parameter [year] is already used by filter [year]', $config);
    }

    public function test_a_facet_may_declare_filters(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.speaker.filters', ['track' => ['type' => 'equals', 'relation' => 'x', 'native_path' => 'x.id']]);

        $this->assertProblem('content_types.speaker.filters.track.relation', $config);
        $this->assertNoProblem('content_types.speaker.filters: ', $config);
    }

    public function test_seo_must_point_at_fields_or_relations(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.seo.image', 'poster');

        $this->assertProblem('seo.image: unknown field or relation [poster]', $config);
    }

    public function test_an_entity_class_must_extend_the_entity_base(): void
    {
        $this->assertProblem('entity: [App\\Nope\\Talk] must be a class extending', $this->with($this->fixture('conference'), 'content_types.talk.entity', 'App\\Nope\\Talk'));
        $this->assertProblem('entity: [stdClass] must be a class extending', $this->with($this->fixture('conference'), 'content_types.talk.entity', \stdClass::class));
    }

    public function test_an_entity_subclass_is_accepted(): void
    {
        $this->assertSame([], $this->problems($this->with($this->fixture('conference'), 'content_types.talk.entity', Entity::class)));
    }

    public function test_only_a_page_has_routes(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.routes', ['x' => '/x']);

        $this->assertProblem('content_types.talk.routes: only a Page has routes', $config);
    }

    public function test_page_slugs_must_be_unique_and_urls_absolute(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['contact'] = ['role' => 'page', 'entity_type' => 'node', 'bundle' => 'contact', 'routes' => ['about' => '/contact', 'team' => 'team']];

        $this->assertProblem('the slug [about] is already used by [page]', $config);
        $this->assertProblem('routes.team: the URL must start with a slash', $config);
    }

    public function test_listing_paths_must_be_unique(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.speaker.listing_path', '/talks');

        $this->assertProblem('listing_path: [/talks] is already used by [talk]', $config);
    }

    public function test_a_page_or_reference_is_never_searchable(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.page.searchable', true);
        $this->assertProblem('content_types.page.searchable: a page is never searchable', $config);

        $config = $this->with($this->fixture('conference'), 'content_types.review.searchable', true);
        $this->assertProblem('content_types.review.searchable: a reference is never searchable', $config);
    }

    public function test_the_role_must_fit_the_entity_type(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.role', 'facet');
        $this->assertProblem('content_types.talk.role: a node is a Primary, Page or Reference', $config);

        $config = $this->with($this->fixture('conference'), 'content_types.topic.role', 'primary');
        $this->assertProblem('content_types.topic.role: [taxonomy_term] is not a node', $config);
    }

    public function test_conditions_check_operator_and_value_shape(): void
    {
        $with = fn (array $condition): array => $this->with($this->fixture('conference'), 'content_types.speaker.conditions', [$condition]);

        $this->assertProblem('conditions.0.operator: [LIKE]', $with(['attribute' => 'talk_count', 'operator' => 'LIKE', 'value' => 1]));
        $this->assertProblem('conditions.0.value: [IN] takes a non-empty list', $with(['attribute' => 'a', 'operator' => 'IN', 'value' => 1]));
        $this->assertProblem('conditions.0.value: [IS NULL] takes no value', $with(['attribute' => 'a', 'operator' => 'IS NULL', 'value' => 1]));
        $this->assertProblem('conditions.0.value: [=] takes one scalar value', $with(['attribute' => 'a', 'operator' => '=', 'value' => [1]]));
        $this->assertNoProblem('conditions', $with(['attribute' => 'a', 'operator' => 'NOT IN', 'value' => [1, 2], 'filterable' => true]));
        $this->assertNoProblem('conditions', $with(['attribute' => 'b', 'operator' => 'IS NOT NULL']));
    }

    public function test_a_reference_needs_a_holder_field(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.review.referenced_by', ['talk' => 'nope']);
        $this->assertProblem('content type [talk] has no field [nope]', $config);

        $config = $this->with($this->fixture('conference'), 'content_types.review.referenced_by', []);
        $this->assertProblem('referenced_by: a Reference is fetched through', $config);
    }

    public function test_enabled_search_needs_a_searchable_type(): void
    {
        $config = $this->fixture('conference');
        foreach (['talk', 'workshop', 'topic', 'track'] as $key) {
            $config['content_types'][$key]['searchable'] = false;
        }
        $config['search']['tabs'] = [];

        $this->assertProblem('search: at least one searchable content type is required', $config);
    }

    public function test_several_searchable_primaries_are_valid(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['journal'] = ['entity_type' => 'node', 'bundle' => 'journal'];
        unset($config['search']['tabs']);

        $this->assertSame([], $this->problems($config));
        $this->assertSame(['talk', 'workshop', 'topic', 'track', 'journal'], $this->descriptor($config)->search->tabs);
    }

    public function test_filter_keys_are_unique_across_types(): void
    {
        $config = $this->fixture('conference');
        $config['content_types']['journal'] = ['entity_type' => 'node', 'bundle' => 'journal', 'filters' => ['year' => ['type' => 'year_range', 'native_path' => 'year', 'first_year' => 2000]]];
        $config['content_types']['talk']['filters']['year'] = ['type' => 'year_range', 'native_path' => 'year', 'first_year' => 2000];

        $this->assertProblem('filter keys are unique across types', $config);
    }

    public function test_search_is_not_checked_while_it_is_disabled(): void
    {
        $config = $this->with($this->fixture('conference'), 'search.enabled', false);
        $config = $this->with($config, 'content_types.talk.searchable', false);

        $this->assertSame([], $this->problems($config));
    }

    public function test_tabs_must_name_content_types(): void
    {
        $this->assertProblem('search.tabs: unknown content type [shoes]', $this->with($this->fixture('conference'), 'search.tabs', ['talk', 'shoes']));
    }

    public function test_tabs_must_be_searchable(): void
    {
        $this->assertProblem('search.tabs: [page] is not searchable', $this->with($this->fixture('conference'), 'search.tabs', ['talk', 'page']));
    }

    public function test_a_tab_is_listed_once(): void
    {
        $this->assertProblem('search.tabs: [talk] is listed twice', $this->with($this->fixture('conference'), 'search.tabs', ['talk', 'talk']));
    }

    public function test_suggestion_quotas_name_content_types(): void
    {
        $this->assertProblem('suggestions.quotas: unknown content type [shoes]', $this->with($this->fixture('conference'), 'search.suggestions.quotas.shoes', 1));
    }

    public function test_every_problem_is_reported_not_only_the_first(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.fields.id', ['source' => 'x']);
        $config = $this->with($config, 'content_types.talk.includes.item', ['ghosts']);

        $this->assertCount(2, $this->problems($config));
    }

    public function test_term_fields_default_to_the_title_attribute(): void
    {
        $descriptor = $this->descriptor($this->fixture('conference'));

        $this->assertSame(['name' => 1], $descriptor->contentType('topic')->termFields);
        $this->assertSame([], $descriptor->contentType('resource')->termFields);
    }

    public function test_a_native_backend_needs_term_fields_on_a_searchable_type(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.term_fields', []);

        $this->assertNotEmpty(array_filter($this->problems($config, new Capabilities), fn (string $p): bool => str_contains($p, 'term_fields')));
    }

    public function test_a_backend_with_fulltext_does_not_need_term_fields(): void
    {
        $config = $this->with($this->fixture('conference'), 'content_types.talk.term_fields', []);

        $this->assertSame([], $this->problems($config, new Capabilities(fulltext: true)));
    }

    public function test_the_target_of_an_equals_filter_needs_an_title_attribute_even_outside_the_tabs(): void
    {
        $config = $this->fixture('conference');
        $config['search']['tabs'] = ['talk'];
        unset($config['content_types']['topic']['title_attribute']);

        $this->assertProblem('content_types.topic.title_attribute: required to offer the choices of filter [topic]', $config);
    }

    public function test_a_facet_in_the_tabs_needs_an_title_attribute(): void
    {
        $config = $this->fixture('conference');
        unset($config['content_types']['speaker']['title_attribute']);

        $this->assertProblem('content_types.speaker.title_attribute', $config);
    }

    public function test_the_suggestion_subtitle_must_be_a_content_relation(): void
    {
        $this->assertProblem('suggest_subtitle', $this->with($this->fixture('conference'), 'content_types.talk.suggest_subtitle', 'cover'));
    }

    public function test_search_requirements_are_not_checked_while_search_is_off(): void
    {
        $config = $this->with($this->fixture('conference'), 'search.enabled', false);

        $this->assertSame([], $this->problems($config));
    }
}
