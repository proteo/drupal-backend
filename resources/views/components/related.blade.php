<ul>
    @foreach($entities as $related)
        <li><a href="{{ $related->url() }}">{{ $related->get(array_key_first(app(\Proteo\DrupalBackend\Descriptor\Descriptor::class)->contentType($related->type)->fields)) }}</a></li>
    @endforeach
</ul>
