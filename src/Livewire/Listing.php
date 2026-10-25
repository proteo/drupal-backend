<?php

namespace Proteo\DrupalBackend\Livewire;

use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException as UnknownContentType;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Proteo\DrupalBackend\Content\ContentFetcher;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Descriptor\RelationKind;
use Proteo\DrupalBackend\Livewire\Concerns\PaginatesListing;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * A paginated listing of any content type the descriptor gives a `per_page`.
 *
 * Optionally narrowed to the items related to one entry through a content
 * relation (e.g. the talks of a speaker). Everything that reaches the
 * upstream query is locked so a crafted update request cannot change it.
 * The view is `livewire.{key}` in the project, else the package default.
 */
class Listing extends Component
{
    use PaginatesListing;

    #[Locked]
    public string $contentType;

    #[Locked]
    public ?string $relation = null;

    #[Locked]
    public ?string $uuid = null;

    /** Defaults to the descriptor's `per_page`. */
    #[Locked]
    public ?int $perPage = null;

    public function mount(Descriptor $descriptor): void
    {
        try {
            $type = $descriptor->contentType($this->contentType);
        } catch (UnknownContentType) {
            abort(404);
        }

        // Both or neither, and the relation must be one the type can be narrowed by.
        abort_if(($this->relation === null) !== ($this->uuid === null), 404);

        if ($this->relation !== null) {
            abort_unless(($type->relations[$this->relation] ?? null)?->kind === RelationKind::Content, 404);
            abort_unless(Str::isUuid($this->uuid), 404);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function render(ContentFetcher $fetcher, Descriptor $descriptor): View
    {
        $type = $descriptor->contentType($this->contentType);
        $listing = $fetcher->all(
            $this->contentType,
            $this->currentPage(),
            $this->perPage === null ? null : max(1, $this->perPage),
            $this->relation,
            $this->uuid,
        );

        return view()->first(['livewire.'.$this->contentType, 'drupal::livewire.listing'], [
            'type' => $type,
            'items' => $listing->items,
            'pages' => $this->pageLinks($listing->hasMore),
        ]);
    }
}
