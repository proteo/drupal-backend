<div>
    <form method="get" action="{{ $searchUrl }}" wire:submit="search" role="search">
        <input type="search" name="q" value="{{ $form->term }}" wire:model="form.term" aria-label="Search" aria-invalid="{{ $tooShort ? 'true' : 'false' }}">
        @if($tooShort)
            <p role="alert">{{ Proteo\DrupalBackend\Livewire\Forms\SearchForm::tooShortMessage() }}</p>
        @endif

        @foreach($filterSet->filters() as $filter)
            @if($filter->type === Proteo\DrupalBackend\Descriptor\FilterType::Equals)
                <label>{{ $filterSet->label($filter) }}
                    <select name="{{ $filter->key }}" wire:model="filters.{{ $filter->key }}" @disabled($options[$filter->key] === null)>
                        <option value="">Any</option>
                        @foreach($options[$filter->key] ?? [] as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            @elseif($filter->type === Proteo\DrupalBackend\Descriptor\FilterType::YearRange)
                <label>{{ $filterSet->label($filter) }}
                    <select name="{{ $filter->key }}" wire:model="filters.{{ $filter->key }}">
                        <option value="">Any year</option>
                        @foreach($filterSet->years($filter) as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>
                </label>
            @else
                @foreach($filter->parameters() as $parameter)
                    <label><input type="checkbox" name="{{ $parameter }}" value="1" wire:model="filters.{{ $parameter }}"> {{ $filter->labels[$parameter] ?? Illuminate\Support\Str::headline($parameter) }}</label>
                @endforeach
            @endif
        @endforeach

        @foreach($active->problems as $problem)
            <p role="alert">{{ $problem }}</p>
        @endforeach

        <button type="button" wire:click="clearFilters">Clear filters</button>
        <button type="submit">Search</button>
    </form>

    @if(! $searched)
        <p>Type a term or pick a filter to start.</p>
    @elseif($failed)
        <p role="alert">Search is temporarily unavailable. Please try again in a moment.</p>
    @else
        <nav role="tablist" aria-label="Result types">
            @foreach($visibleTabs as $option)
                <a href="{{ route('search', ['tab' => $option] + $linkQuery) }}" wire:click.prevent="selectTab('{{ $option }}')" wire:key="tab-{{ $option }}" role="tab" aria-selected="{{ $option === $activeTab ? 'true' : 'false' }}">{{ $tabs->label($option) }} ({{ $results->countLabel($option) }})</a>
            @endforeach
        </nav>

        @if($results->total() === 0 || $results->listing->items->isEmpty())
            <p>No results.</p>
        @else
            <ul>
                @foreach($results->listing->items as $item)
                    <li wire:key="{{ $activeTab }}-{{ $item->id }}">
                        @include(view()->first(['livewire.search.'.$activeTab, 'drupal::livewire.search-item'])->name(), ['item' => $item, 'type' => $activeTab])
                    </li>
                @endforeach
            </ul>

            @isset($pages['prev'])<a href="{{ $pages['prev'] }}" rel="prev">Previous</a>@endisset
            @isset($pages['next'])<a href="{{ $pages['next'] }}" rel="next">Next</a>@endisset
        @endif
    @endif
</div>
