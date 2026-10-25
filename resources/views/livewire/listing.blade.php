<div>
    <ul>
        @foreach($items as $item)
            <li wire:key="{{ $type->key }}-{{ $item->id }}">
                <a href="{{ $item->url() }}">{{ $item->get(array_key_first($type->fields)) }}</a>
            </li>
        @endforeach
    </ul>

    @isset($pages['prev'])<a href="{{ $pages['prev'] }}" rel="prev">Previous</a>@endisset
    @isset($pages['next'])<a href="{{ $pages['next'] }}" rel="next">Next</a>@endisset
</div>
